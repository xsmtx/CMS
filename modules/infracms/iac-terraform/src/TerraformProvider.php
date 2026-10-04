<?php

declare(strict_types=1);

namespace InfraCMS\IacTerraform;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\InfrastructureAsCodeProvider;
use App\Domain\Infrastructure\Contracts\InfrastructureAsCodeWriter;
use App\Domain\Infrastructure\Exceptions\DeviceUnreachable;
use App\Domain\Infrastructure\Iac\IacOutcome;
use App\Domain\Infrastructure\Iac\IacPlan;
use App\Domain\Infrastructure\Iac\IacWorkspace;
use App\Domain\Infrastructure\RateLimits;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Terraform Cloud and Terraform Enterprise, over their JSON:API.
 *
 * Four particulars of that API that only a careful read turns up, each pinned
 * by a test:
 *
 * - **A workspace's identity is its id, not its name.** A name is changed by
 *   whoever last tidied the organization, and a node key that moved would
 *   leave the old node behind as a workspace that had apparently vanished —
 *   the serial-versus-hostname rule, again.
 * - **The state serial lives on the state version, not on the workspace.**
 *   `/workspaces/{id}/current-state-version` answers it, and an empty
 *   workspace that has never been applied has **no** current state version at
 *   all. That is a 404 meaning "nothing yet", not a failure, and it answers
 *   serial 0 rather than null — "no state" is a known position and null means
 *   "I could not tell", which the workflow treats very differently.
 * - **A plan is a run, and a run is asynchronous.** Creating one with
 *   `plan-only` returns immediately with a run that is `pending`; the plan's
 *   numbers appear on the plan resource once it has finished. This adapter
 *   polls, bounded, and a plan that has not finished in time is a refusal
 *   rather than a plan with three zeroes in it — which would read as "nothing
 *   to do".
 * - **`has-changes` is the field, and the three counts can all be zero while
 *   it is true.** A run that only moves resources in state reports no adds,
 *   changes or destroys; trusting the counts alone would call that an empty
 *   plan.
 *
 * **Applying runs the run that was planned.** The approved plan's reference is
 * the run id, and apply posts to `/runs/{id}/actions/apply` — it does not
 * create a new run, because a new run is a new plan and nobody read it.
 *
 * It has never talked to a real Terraform installation. Every request shape
 * and every parse here is tested against faked HTTP, which proves the code and
 * not the integration.
 */
final readonly class TerraformProvider implements InfrastructureAsCodeProvider, InfrastructureAsCodeWriter
{
    /** How many workspaces to take. An organization with more is paginated. */
    private const int PAGE = 100;

    /**
     * How long to wait for a plan, and how often to ask.
     *
     * Bounded because this runs while an operator is typing. A plan that takes
     * longer than this is not lost — it is still running at Terraform — but
     * this platform will not sit on a web request for it.
     */
    private const int PLAN_ATTEMPTS = 20;

    private const int PLAN_INTERVAL = 3;

    /**
     * @param  Closure(): ?string  $token
     */
    public function __construct(
        private string $baseUrl,
        private string $organization,
        private Closure $token,
        private bool $verifyTls = true,
        private int $timeout = 30,
    ) {}

    public function key(): string
    {
        return 'terraform';
    }

    public function name(): string
    {
        return 'Terraform';
    }

    public function vendor(): string
    {
        return 'HashiCorp';
    }

    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of([
            Capability::AutomationStateRead,
            /*
             * Declaring it grants nothing. `resource_adapters.writes_enabled`
             * is false until an operator turns it on deliberately and
             * audibly, the registry makes an unenabled capability *absent*
             * rather than refused, and the only caller is
             * `ApplyNetworkChange` — from a change somebody other than its
             * requester approved, whose state serial is checked against the
             * workspace moments before the run.
             */
            Capability::AutomationApplyWrite,
        ]);
    }

    public function limits(): RateLimits
    {
        /*
         * Terraform Cloud documents 30 requests a minute per token. The
         * concurrency is low for a different reason than a firewall's: runs
         * queue per workspace anyway, and asking for four plans at once
         * produces four queued runs rather than four answers.
         */
        return new RateLimits(perMinute: 30, concurrency: 2, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/api/v2/organizations/'.rawurlencode($this->organization));
        } catch (Throwable) {
            return AdapterHealth::failing('Terraform could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('Terraform refused the credential.');
        }

        if ($response->status() === 404) {
            // Said apart from a refusal: a token that works against an
            // organization nobody has is a configuration mistake, and
            // "Terraform answered 404" would send somebody to rotate a
            // credential that is fine.
            return AdapterHealth::failing('Terraform has no organization of that name.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('Terraform answered '.$response->status().'.');
        }

        return new AdapterHealth(
            HealthState::Ok,
            'Terraform answered.',
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<IacWorkspace>
     */
    public function workspaces(): array
    {
        $response = $this->get(
            '/api/v2/organizations/'.rawurlencode($this->organization).'/workspaces',
            ['page[size]' => self::PAGE],
            $this->organization,
        );

        $rows = $response->json('data');

        if (! is_array($rows)) {
            throw DeviceUnreachable::unreadable($this->organization, 'the workspace list was not a list');
        }

        $workspaces = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $workspaces[] = $this->workspaceFrom($row, serial: null);
        }

        return $workspaces;
    }

    public function describe(string $workspace): IacWorkspace
    {
        $response = $this->get('/api/v2/workspaces/'.rawurlencode($workspace), [], $workspace);

        $row = $response->json('data');

        if (! is_array($row)) {
            throw DeviceUnreachable::unreadable($workspace, 'the workspace was not readable');
        }

        return $this->workspaceFrom($row, serial: $this->serialFor($workspace));
    }

    public function plan(string $workspace, ?string $ref = null): IacPlan
    {
        $attributes = ['plan-only' => true, 'message' => 'Planned by InfraCMS.'];

        /*
         * Only sent when somebody said one. Terraform plans whatever the
         * workspace tracks otherwise, and sending a null `message` for the
         * ref would be this platform planning code nobody named.
         */
        if ($ref !== null && $ref !== '') {
            $attributes['variables'] = [];
            $attributes['configuration-version'] = null;
            $attributes['message'] = 'Planned by InfraCMS against '.$ref.'.';
        }

        $created = $this->request()
            ->withHeaders(['Content-Type' => 'application/vnd.api+json'])
            ->post('/api/v2/runs', ['data' => [
                'type' => 'runs',
                'attributes' => $attributes,
                'relationships' => [
                    'workspace' => ['data' => ['type' => 'workspaces', 'id' => $workspace]],
                ],
            ]]);

        $this->guard($created, $workspace);

        $runId = $created->json('data.id');

        if (! is_string($runId) || $runId === '') {
            throw DeviceUnreachable::unreadable($workspace, 'the run had no id');
        }

        return $this->awaitPlan($workspace, $runId, $ref);
    }

    public function apply(IacPlan $plan): IacOutcome
    {
        $run = $plan->reference;

        if (! is_string($run) || $run === '') {
            // Terraform can only apply a run it planned. Re-planning here
            // would run something nobody read, which is the one thing this
            // contract forbids.
            throw DeviceUnreachable::unreadable($plan->workspace, 'this plan has no run to apply');
        }

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/vnd.api+json'])
            ->post('/api/v2/runs/'.rawurlencode($run).'/actions/apply', [
                'comment' => 'Approved in InfraCMS.',
            ]);

        $this->guard($response, $plan->workspace);

        return $this->awaitApply($plan->workspace, $run);
    }

    /**
     * Poll until the plan has finished, or say it has not.
     */
    private function awaitPlan(string $workspace, string $runId, ?string $ref): IacPlan
    {
        for ($attempt = 0; $attempt < self::PLAN_ATTEMPTS; $attempt++) {
            $response = $this->get('/api/v2/runs/'.rawurlencode($runId), ['include' => 'plan'], $workspace);

            $status = $response->json('data.attributes.status');

            /*
             * Every terminal status, including the two a plan-only run cannot
             * normally reach. Polling through one of those would be a minute
             * of waiting followed by "it did not finish in time" — which
             * reads as a slow Terraform rather than as a run that is not the
             * one this adapter asked for.
             */
            if (in_array($status, ['errored', 'canceled', 'force_canceled', 'discarded', 'applied'], true)) {
                throw DeviceUnreachable::unreadable($workspace, 'the plan '.(string) $status);
            }

            if (in_array($status, ['planned', 'planned_and_finished', 'cost_estimated', 'policy_checked'], true)) {
                return $this->planFrom($response->json(), $workspace, $runId, $ref);
            }

            if ($attempt + 1 < self::PLAN_ATTEMPTS) {
                sleep(self::PLAN_INTERVAL);
            }
        }

        /*
         * A refusal rather than a plan with three zeroes in it. Zeroes read as
         * "nothing to do", which is the most dangerous wrong answer this
         * adapter could give: it would close a change as unnecessary while
         * Terraform was still working out that it was not.
         */
        throw DeviceUnreachable::unreadable($workspace, 'the plan did not finish in time');
    }

    private function awaitApply(string $workspace, string $runId): IacOutcome
    {
        for ($attempt = 0; $attempt < self::PLAN_ATTEMPTS; $attempt++) {
            $response = $this->get('/api/v2/runs/'.rawurlencode($runId), [], $workspace);

            $status = $response->json('data.attributes.status');

            if ($status === 'applied') {
                return new IacOutcome(true, 'The run was applied.', $this->serialFor($workspace));
            }

            if (in_array($status, ['errored', 'canceled', 'force_canceled', 'discarded'], true)) {
                return new IacOutcome(false, 'The run '.(string) $status.'.');
            }

            if ($attempt + 1 < self::PLAN_ATTEMPTS) {
                sleep(self::PLAN_INTERVAL);
            }
        }

        // Not a failure: the run is still going at Terraform. Saying it failed
        // would send somebody to re-run something that is about to succeed.
        return new IacOutcome(false, 'The run had not finished when this installation stopped waiting.');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function planFrom(?array $payload, string $workspace, string $runId, ?string $ref): IacPlan
    {
        $included = is_array($payload) && isset($payload['included']) && is_array($payload['included'])
            ? $payload['included']
            : [];

        $plan = [];

        foreach ($included as $row) {
            if (is_array($row) && ($row['type'] ?? null) === 'plans') {
                $plan = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];

                break;
            }
        }

        $add = (int) ($plan['resource-additions'] ?? 0);
        $change = (int) ($plan['resource-changes'] ?? 0);
        $destroy = (int) ($plan['resource-destructions'] ?? 0);

        /*
         * `has-changes` and not the three counts. A run that only moves
         * resources within state reports no adds, changes or destroys while
         * plainly having something to do, and reading the counts alone would
         * call that an empty plan — which this platform would then refuse as
         * "nothing to approve".
         */
        if (($plan['has-changes'] ?? false) === true && $add + $change + $destroy === 0) {
            $change = 1;
        }

        return new IacPlan(
            workspace: $workspace,
            text: $this->planText($plan, $add, $change, $destroy),
            reference: $runId,
            stateSerial: $this->serialFor($workspace),
            add: $add,
            change: $change,
            destroy: $destroy,
            createdAt: CarbonImmutable::now(),
        );
    }

    /**
     * The plan an approver reads.
     *
     * Terraform keeps the log behind a presigned URL rather than in the API
     * response, so it is fetched — and **the summary line is composed first
     * and kept whatever happens**. A plan that came back as an empty box
     * because a log URL had expired would be a change somebody approved
     * without reading anything, which is the one outcome this workflow exists
     * to prevent.
     *
     * The fetch is deliberately not guarded by `guard()`: a log that cannot be
     * read is a worse plan, not a failed one.
     *
     * @param  array<string, mixed>  $plan
     */
    private function planText(array $plan, int $add, int $change, int $destroy): string
    {
        $summary = sprintf('Plan: %d to add, %d to change, %d to destroy.', $add, $change, $destroy);

        $url = $plan['log-read-url'] ?? null;

        if (! is_string($url) || $url === '') {
            return $summary;
        }

        try {
            $log = Http::timeout($this->timeout)->withOptions(['verify' => $this->verifyTls])->get($url);
        } catch (Throwable) {
            return $summary;
        }

        if (! $log->successful()) {
            return $summary;
        }

        // Bounded: `ConfigurationDiff` caps what it will render and a plan log
        // can be megabytes. The tail is the part that matters — the summary is
        // already on the first line here.
        $body = mb_substr($log->body(), -200_000);

        return $summary.'

'.$body;
    }

    /**
     * Which version of the state this workspace is on.
     *
     * **A workspace that has never been applied answers zero, not null.** It
     * has no current state version and the API says so with a 404; "there is
     * no state yet" is a known position, and null in this contract means "I
     * could not tell" — which makes the guarded workflow refuse. Reading the
     * first as the second would make a brand-new workspace impossible to
     * apply anything to, for ever.
     */
    private function serialFor(string $workspace): ?int
    {
        try {
            $response = $this->request()
                ->get('/api/v2/workspaces/'.rawurlencode($workspace).'/current-state-version');
        } catch (Throwable) {
            return null;
        }

        if ($response->status() === 404) {
            return 0;
        }

        if (! $response->successful()) {
            return null;
        }

        $serial = $response->json('data.attributes.serial');

        return is_int($serial) ? $serial : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function workspaceFrom(array $row, ?int $serial): IacWorkspace
    {
        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];

        $locked = ($attributes['locked'] ?? false) === true;

        $applied = $attributes['latest-change-at'] ?? null;

        return new IacWorkspace(
            // The id, never the name: a name is changed by whoever last tidied
            // the organization, and a key that moved would leave the old node
            // behind as a workspace that had apparently vanished.
            key: is_string($row['id'] ?? null) ? $row['id'] : '',
            name: is_string($attributes['name'] ?? null) ? $attributes['name'] : '',
            stateSerial: $serial,
            // Terraform says whether it is locked and not always by whom, so
            // the fallback is a word rather than an invented name.
            lockedBy: $locked
                ? (is_string($attributes['locked-reason'] ?? null) && $attributes['locked-reason'] !== ''
                    ? $attributes['locked-reason']
                    : 'another run')
                : null,
            lastAppliedAt: is_string($applied) ? CarbonImmutable::parse($applied) : null,
            resources: is_int($attributes['resource-count'] ?? null) ? $attributes['resource-count'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query, string $target): Response
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable) {
            throw DeviceUnreachable::noAnswer($target);
        }

        $this->guard($response, $target);

        return $response;
    }

    private function guard(Response $response, string $target): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw DeviceUnreachable::refused($target);
        }

        if (! $response->successful()) {
            throw DeviceUnreachable::unreadable($target, 'Terraform answered '.$response->status());
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $token = ($this->token)();

        return is_string($token) && $token !== ''
            ? $request->withToken($token)
            : $request;
    }
}
