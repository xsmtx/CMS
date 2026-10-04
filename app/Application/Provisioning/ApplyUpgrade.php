<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Billing\AddCredit;
use App\Domain\Provisioning\UpgradeState;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Provisioning\Models\ServiceUpgrade;
use App\Support\Audit\Facades\Audit;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Move the account, then move the record.
 *
 * Called from a listener once the invoice is paid, and from the queue screen
 * when a move costs nothing. Never from a controller directly: an upgrade that
 * could be applied by pressing a button would be an upgrade that could be
 * applied without being paid for.
 *
 * **The provider first, the row second.** `changePackage` is what actually
 * moves the account; rewriting the service's price before asking would leave a
 * customer billed for a plan they are not on when the provider refuses. The
 * same order `RunServiceOperation` uses everywhere else.
 *
 * **`failed` is a real end state** (ADR 0026): the money has moved and the
 * plan has not, and that is somebody's afternoon rather than a row to retry
 * silently. The reason the provider gave is written on it, redacted, because
 * a provider's message is evidence rather than vocabulary.
 *
 * **A new term starts today when the cycle changed**, and does not when it did
 * not. That is what `restarts_term` was frozen for: recomputing it here from
 * the cycles would be a second implementation of the same decision, and the
 * two would disagree the first time somebody edited a product.
 *
 * **A downgrade's credit is written here, not when it was asked for.** A
 * credit for a move that has not happened is money given away for nothing.
 */
final readonly class ApplyUpgrade
{
    public function __construct(
        private RunServiceOperation $operations,
        private AddCredit $credits,
        private SecretRedactor $redactor,
    ) {}

    public function handle(ServiceUpgrade $upgrade, ?Model $actor = null): ServiceUpgrade
    {
        if ($upgrade->state !== UpgradeState::Authorized) {
            return $upgrade;
        }

        $service = $upgrade->service;
        $target = Product::query()->find($upgrade->to_product_id);

        if ($service === null || ! $target instanceof Product) {
            return $this->fail($upgrade, 'The service or the plan it was moving to is no longer here.');
        }

        $upgrade->state = UpgradeState::Applying;
        $upgrade->save();

        try {
            $result = $this->operations->changePackage($service, $this->packageOf($target), $actor);
        } catch (Throwable $exception) {
            return $this->fail($upgrade, $this->redactor->redactString($exception->getMessage()));
        }

        if (! $result->isSuccessful()) {
            return $this->fail($upgrade, $this->redactor->redactString($result->message ?? ''));
        }

        $this->rewrite($upgrade, $target);
        $this->creditIfOwed($upgrade, $actor);

        $upgrade->state = UpgradeState::Completed;
        $upgrade->applied_at = CarbonImmutable::now();
        $upgrade->result = null;
        $upgrade->save();

        Audit::action('provisioning.upgrade.applied')
            ->by($actor)
            ->on($upgrade)
            ->forOrganization($upgrade->organization_id)
            ->withMetadata([
                'service' => $service->name,
                'to' => $upgrade->to_product_name,
                'restarts_term' => $upgrade->restarts_term,
            ])
            ->write();

        return $upgrade;
    }

    /**
     * What the service says it is, after the move.
     *
     * The price comes from the catalogue rather than from the frozen figure on
     * the upgrade: `to_recurring_minor` is what the *prorated* charge was, and
     * writing that onto the service would bill the customer a part-month for
     * ever.
     */
    private function rewrite(ServiceUpgrade $upgrade, Product $target): void
    {
        $service = $upgrade->service;

        if ($service === null) {
            return;
        }

        $price = $target->prices
            ->first(static fn (ProductPrice $row): bool => $row->billing_cycle === $upgrade->to_cycle
                && $row->currency_code === $upgrade->currency_code);

        $attributes = [
            'product_id' => $target->id,
            'name' => $target->name,
            'billing_cycle' => $upgrade->to_cycle->value,
        ];

        if ($price instanceof ProductPrice) {
            $attributes['recurring_minor'] = $price->recurring->minorUnits;
        }

        /*
         * A new term begins today only when the cycle changed — which is what
         * `restarts_term` was frozen for. Where it did not, the renewal date
         * stays exactly where it was: the customer paid the difference for the
         * remainder of a term they are still in.
         */
        if ($upgrade->restarts_term) {
            $from = CarbonImmutable::now()->startOfDay();

            $attributes['next_due_on'] = $upgrade->to_cycle->nextDueDate($from)?->toDateString();
            // Cleared, not moved: the old value is how far the *old* cycle had
            // been invoiced, and a new term has been invoiced for none of it.
            $attributes['renewal_invoiced_through'] = null;
        }

        $service->forceFill($attributes)->save();
    }

    /**
     * A downgrade's difference, as account credit.
     *
     * `AddCredit` rather than a refund: money that has been taken is not sent
     * back by a panel, and the ledger is where this product keeps what a
     * customer is owed (ADR 0024).
     */
    private function creditIfOwed(ServiceUpgrade $upgrade, ?Model $actor): void
    {
        if (! $upgrade->isDowngrade()) {
            return;
        }

        $customer = $upgrade->service?->customer;

        if ($customer === null) {
            return;
        }

        $this->credits->handle(
            $customer,
            Money::ofMinor(abs($upgrade->difference_minor), $upgrade->currency_code),
            (string) __('provisioning.upgrades.lines.downgrade_credit', [
                'from' => $upgrade->from_product_name,
                'to' => $upgrade->to_product_name,
            ]),
            $actor,
        );
    }

    /**
     * What the provider calls the plan.
     *
     * The product's own package name where it has one, and its slug where it
     * does not — a provider that was handed an empty string would be asked to
     * move an account to a package called nothing.
     */
    private function packageOf(Product $target): string
    {
        return (string) ($target->provisioning_package ?? $target->slug);
    }

    private function fail(ServiceUpgrade $upgrade, string $message): ServiceUpgrade
    {
        $upgrade->state = UpgradeState::Failed;
        $upgrade->result = $message === '' ? null : $message;
        $upgrade->applied_at = CarbonImmutable::now();
        $upgrade->save();

        return $upgrade;
    }
}
