<?php

declare(strict_types=1);

use App\Domain\Catalog\BillingCycle;
use Carbon\CarbonImmutable;

it('knows how many months each cycle spans', function (): void {
    expect(BillingCycle::Monthly->months())->toBe(1)
        ->and(BillingCycle::Quarterly->months())->toBe(3)
        ->and(BillingCycle::SemiAnnually->months())->toBe(6)
        ->and(BillingCycle::Annually->months())->toBe(12)
        ->and(BillingCycle::Biennially->months())->toBe(24)
        ->and(BillingCycle::Triennially->months())->toBe(36)
        ->and(BillingCycle::OneTime->months())->toBe(0);
});

it('separates recurring cycles from one-time', function (): void {
    expect(BillingCycle::Monthly->isRecurring())->toBeTrue()
        ->and(BillingCycle::OneTime->isRecurring())->toBeFalse()
        ->and(BillingCycle::recurring())->not->toContain(BillingCycle::OneTime);
});

it('advances a due date by the cycle', function (): void {
    $from = CarbonImmutable::parse('2026-01-15');

    expect(BillingCycle::Monthly->nextDueDate($from)->toDateString())->toBe('2026-02-15')
        ->and(BillingCycle::Annually->nextDueDate($from)->toDateString())->toBe('2027-01-15');
});

it('does not overflow a short month', function (): void {
    // Naive month arithmetic turns 31 January into 3 March, which would bill
    // a customer a month early for the rest of the subscription's life.
    $from = CarbonImmutable::parse('2026-01-31');

    expect(BillingCycle::Monthly->nextDueDate($from)->toDateString())->toBe('2026-02-28');
});

it('leaves a one-time charge without a next due date', function (): void {
    expect(BillingCycle::OneTime->nextDueDate(CarbonImmutable::parse('2026-01-15')))->toBeNull();
});

it('orders cycles shortest first with one-time last', function (): void {
    $cycles = BillingCycle::cases();

    usort($cycles, fn (BillingCycle $a, BillingCycle $b): int => $a->sortOrder() <=> $b->sortOrder());

    expect(array_map(fn (BillingCycle $cycle): string => $cycle->value, $cycles))
        ->toBe([
            'monthly',
            'quarterly',
            'semi_annually',
            'annually',
            'biennially',
            'triennially',
            'one_time',
        ]);
});
