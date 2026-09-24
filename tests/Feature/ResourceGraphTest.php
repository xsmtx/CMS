<?php

declare(strict_types=1);

use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\ProjectCoreResources;
use App\Application\Infrastructure\ImpactSummary;
use App\Application\Infrastructure\OwnershipHistory;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Infrastructure\ResourceTree;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use App\Domain\Infrastructure\Exceptions\InvalidResource;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * The graph: what it says, what it refuses, and what it remembers.
 *
 * Four claims are made by ADR 0043 and each one is asserted here rather than
 * described. The graph is idempotent, it keeps history, it cannot be walked
 * upward across the boundary, and an impact figure is money per currency rather
 * than a number.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);
});

/** Two customers, two servers, four services — never one of anything. */
function estate(Organization $provider): array
{
    return app(OrganizationContext::class)->withoutBoundary(function () use ($provider): array {
        $servers = Server::factory()->count(2)->create(['organization_id' => $provider->id]);

        $customers = Customer::factory()->count(2)->create();

        $services = [];

        foreach ($customers as $index => $customer) {
            foreach ([0, 1] as $offset) {
                $services[] = Service::factory()->create([
                    'organization_id' => $customer->organization_id,
                    'customer_id' => $customer->id,
                    'server_id' => $servers[$offset]->id,
                    'status' => ServiceStatus::Active->value,
                    'currency_code' => $index === 0 ? 'EUR' : 'TRY',
                    'recurring_minor' => 1_000,
                ]);
            }
        }

        return ['servers' => $servers, 'customers' => $customers, 'services' => $services];
    });
}

function project(): object
{
    return app(RecordedRun::class)->handle(
        AutomationTask::Resources,
        app(ProjectCoreResources::class),
    );
}

it('labels a customer node with the customer name, not "Customer"', function (): void {
    // A node carries a cached label (ADR 0043), and the organization's own name
    // is the wrong thing to cache for a customer: `CreateCustomer` has no
    // personal name to use when an individual signs up with no company, so the
    // row is literally called "Customer". Four of those in an impact view tell
    // an operator nothing at all, which is what a browser showed.
    $customer = Customer::factory()->create([
        'organization_id' => Organization::factory()->create([
            'parent_id' => $this->provider->id,
            'type' => OrganizationType::Customer->value,
            'name' => 'Customer',
        ])->id,
        'company_name' => null,
        'legal_name' => null,
    ]);

    Contact::factory()->forCustomer($customer)->primary()->create([
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
    ]);

    project();

    $node = ResourceNode::query()
        ->where('kind', ResourceKind::Organization)
        ->where('node_key', $customer->organization_id)
        ->sole();

    expect($node->label)->toBe($customer->fresh()?->displayName())
        ->and($node->label)->not->toBe('Customer');
});

it('projects organizations, servers and services, and links them', function (): void {
    estate($this->provider);

    $run = project();

    expect($run->status)->toBe(RunStatus::Completed);

    $nodes = ResourceNode::query()->get();

    expect($nodes->where('kind', ResourceKind::Server)->count())->toBe(2)
        ->and($nodes->where('kind', ResourceKind::Service)->count())->toBe(4)
        // A customer is not a node. CRM already answers "what does this
        // customer have", and a second answer would eventually disagree.
        ->and($nodes->where('kind', ResourceKind::Customer)->count())->toBe(0);

    $edges = ResourceEdge::query()->get();

    expect($edges->where('relation', Relation::Hosts)->count())->toBe(4)
        ->and($edges->where('relation', Relation::Contains)->count())->toBe(2);
});

/**
 * ADR 0031's first required test, applied to inventory: run it twice and assert
 * the second changed nothing.
 */
it('changes nothing the second time it runs', function (): void {
    estate($this->provider);

    project();

    $before = ResourceNode::query()->count();
    $edgesBefore = ResourceEdge::query()->count();

    $second = project();

    expect($second->changed)->toBe(0)
        ->and($second->status)->toBe(RunStatus::Completed)
        // A run that changed nothing is still written. "Examined 7, changed 0"
        // is the answer to "why did nothing appear".
        ->and($second->examined)->toBeGreaterThan(0)
        ->and(ResourceNode::query()->count())->toBe($before)
        ->and(ResourceEdge::query()->count())->toBe($edgesBefore);
});

it('closes the old edge and opens a new one when a service moves server', function (): void {
    $estate = estate($this->provider);

    project();

    $service = $estate['services'][0];
    $service->server_id = $estate['servers'][1]->id;
    $service->save();

    project();

    $node = ResourceNode::query()
        ->where('kind', ResourceKind::Service)
        ->where('node_key', $service->id)
        ->firstOrFail();

    $edges = ResourceEdge::query()
        ->where('to_node_id', $node->id)
        ->where('relation', Relation::Hosts)
        ->get();

    expect($edges)->toHaveCount(2)
        ->and($edges->whereNull('ended_at'))->toHaveCount(1);

    // And the history reads as a move rather than as two simultaneous truths.
    $history = app(OwnershipHistory::class)->for($node, Relation::Hosts);

    expect($history)->toHaveCount(2)
        ->and($history[0]->isOpen())->toBeTrue()
        ->and($history[1]->isOpen())->toBeFalse();
});

it('retires a node whose service was terminated, and closes its edges', function (): void {
    $estate = estate($this->provider);

    project();

    $service = $estate['services'][0];
    $service->status = ServiceStatus::Terminated;
    $service->save();

    project();

    $node = ResourceNode::query()
        ->where('kind', ResourceKind::Service)
        ->where('node_key', $service->id)
        ->firstOrFail();

    expect($node->retired_at)->not->toBeNull();

    $open = ResourceEdge::query()
        ->where('to_node_id', $node->id)
        ->whereNull('ended_at')
        ->count();

    // Retired, not deleted: the node is what an incident review needs, and its
    // closed edges are what stop it counting towards impact.
    expect($open)->toBe(0);
});

it('answers the impact question in money per currency, never as a total', function (): void {
    $estate = estate($this->provider);

    project();

    $server = ResourceNode::query()
        ->where('kind', ResourceKind::Server)
        ->where('node_key', $estate['servers'][0]->id)
        ->firstOrFail();

    $impact = app(ImpactSummary::class)->for($server);

    expect($impact->services)->toBe(2)
        ->and($impact->customers)->toBe(2)
        ->and($impact->recurring->currencies())->toEqualCanonicalizing(['EUR', 'TRY'])
        ->and($impact->recurring->minorFor('EUR'))->toBe(1_000)
        ->and($impact->recurring->minorFor('TRY'))->toBe(1_000);
});

it('walks down from the organization to the services', function (): void {
    estate($this->provider);

    project();

    $root = ResourceNode::query()
        ->where('kind', ResourceKind::Organization)
        ->where('node_key', $this->provider->id)
        ->firstOrFail();

    $rows = app(ResourceTree::class)->below($root);

    expect($rows[0]->node->id)->toBe($root->id)
        ->and($rows[0]->depth)->toBe(0)
        ->and(count($rows))->toBe(7)
        ->and(max(array_map(static fn (object $row): int => $row->depth, $rows)))->toBe(2);
});

it('stops walking rather than looping when two nodes contain each other', function (): void {
    $graph = app(ResourceGraph::class);

    $left = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'left', 'Left');
    $right = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'right', 'Right');

    $graph->attach($left, $right, Relation::Contains);
    $graph->attach($right, $left, Relation::Contains);

    $rows = app(ResourceTree::class)->below($left);

    // Two switches each reporting the other as upstream is an ordinary Tuesday.
    expect($rows)->toHaveCount(2);
});

it('refuses an edge to a node outside the container organization', function (): void {
    $graph = app(ResourceGraph::class);

    $other = Organization::factory()->create([
        'type' => OrganizationType::Provider->value,
        'parent_id' => null,
    ]);

    $mine = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'mine', 'Mine');
    $theirs = $graph->upsertNode($other->id, ResourceKind::Service, 'theirs', 'Theirs');

    expect(fn (): object => $graph->attach($mine, $theirs, Relation::Hosts))
        ->toThrow(InvalidResource::class);
});

it('refuses a node that contains itself', function (): void {
    $graph = app(ResourceGraph::class);

    $node = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'one', 'One');

    expect(fn (): object => $graph->attach($node, $node, Relation::Contains))
        ->toThrow(InvalidResource::class);
});

it('refuses a kind that is not a plain name', function (): void {
    $graph = app(ResourceGraph::class);

    expect(fn (): object => $graph->upsertNode($this->provider->id, 'Switch Port', 'x', 'X'))
        ->toThrow(InvalidResource::class);
});

/**
 * The claim ADR 0043 makes about privacy, asserted from the customer's side.
 *
 * An edge belongs to its container, so a customer inside the provider's subtree
 * finds their own service and no edge above it — which is the whole reason
 * containment points downward.
 */
it('does not let a customer walk up from their service to the server', function (): void {
    $estate = estate($this->provider);

    project();

    $service = $estate['services'][0];

    app(OrganizationContext::class)->set($service->organization_id);

    $node = ResourceNode::query()
        ->where('kind', ResourceKind::Service)
        ->where('node_key', $service->id)
        ->first();

    expect($node)->not->toBeNull();

    $above = app(ResourceTree::class)->above($node);

    // Just itself: the edge that says which server hosts it belongs to the
    // provider, and the provider is an ancestor rather than a descendant.
    expect($above)->toHaveCount(1)
        ->and($above[0]->node->id)->toBe($node->id);

    expect(ResourceNode::query()->where('kind', ResourceKind::Server)->count())->toBe(0);
});
