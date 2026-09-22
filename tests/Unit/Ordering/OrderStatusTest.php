<?php

declare(strict_types=1);

use App\Domain\Ordering\OrderStatus;

it('starts a draft and submits it', function (): void {
    expect(OrderStatus::Draft->canTransitionTo(OrderStatus::Pending))->toBeTrue()
        ->and(OrderStatus::Draft->canTransitionTo(OrderStatus::Paid))->toBeFalse();
});

it('lets a free order skip payment', function (): void {
    // A zero-total order has nothing to wait for.
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Paid))->toBeTrue();
});

it('sends a suspicious order to review rather than refusing it outright', function (): void {
    expect(OrderStatus::Pending->canTransitionTo(OrderStatus::FraudReview))->toBeTrue()
        ->and(OrderStatus::FraudReview->canTransitionTo(OrderStatus::AwaitingPayment))->toBeTrue()
        ->and(OrderStatus::FraudReview->canTransitionTo(OrderStatus::Cancelled))->toBeTrue();
});

it('refuses to move a cancelled order', function (): void {
    foreach (OrderStatus::cases() as $target) {
        expect(OrderStatus::Cancelled->canTransitionTo($target))->toBeFalse();
    }
});

it('refuses to move a refunded order', function (): void {
    foreach (OrderStatus::cases() as $target) {
        expect(OrderStatus::Refunded->canTransitionTo($target))->toBeFalse();
    }
});

it('refuses to un-pay an order', function (): void {
    // Money moved. The way back is a refund, which is its own record.
    expect(OrderStatus::Paid->canTransitionTo(OrderStatus::AwaitingPayment))->toBeFalse()
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Pending))->toBeFalse()
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Refunded))->toBeTrue();
});

it('lets a failed order be retried', function (): void {
    expect(OrderStatus::Failed->canTransitionTo(OrderStatus::Provisioning))->toBeTrue()
        ->and(OrderStatus::Failed->canTransitionTo(OrderStatus::AwaitingPayment))->toBeTrue();
});

it('treats partial fulfilment as a stop on the way to active', function (): void {
    expect(OrderStatus::Provisioning->canTransitionTo(OrderStatus::PartiallyFulfilled))->toBeTrue()
        ->and(OrderStatus::PartiallyFulfilled->canTransitionTo(OrderStatus::Active))->toBeTrue();
});

it('knows which statuses mean the money arrived', function (): void {
    expect(OrderStatus::Paid->isPaid())->toBeTrue()
        ->and(OrderStatus::Active->isPaid())->toBeTrue()
        ->and(OrderStatus::Refunded->isPaid())->toBeTrue()
        ->and(OrderStatus::AwaitingPayment->isPaid())->toBeFalse();
});

it('knows which statuses need a human', function (): void {
    expect(OrderStatus::FraudReview->needsReview())->toBeTrue()
        ->and(OrderStatus::PaymentReview->needsReview())->toBeTrue()
        ->and(OrderStatus::Pending->needsReview())->toBeFalse();
});

it('offers an operator only the transitions a human should make', function (): void {
    // Marking an order paid is what a payment does, not what a button does.
    expect(OrderStatus::AwaitingPayment->manualTransitions())
        ->not->toContain(OrderStatus::Paid)
        ->and(OrderStatus::AwaitingPayment->manualTransitions())
        ->toContain(OrderStatus::Cancelled);
});

it('never offers a manual transition the machine forbids', function (): void {
    foreach (OrderStatus::cases() as $status) {
        foreach ($status->manualTransitions() as $target) {
            expect($status->canTransitionTo($target))->toBeTrue();
        }
    }
});

it('gives every status a translation key', function (): void {
    foreach (OrderStatus::cases() as $status) {
        expect(__($status->labelKey()))->not->toBe($status->labelKey());
    }
});
