<?php

declare(strict_types=1);

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\TransactionKind;

it('lets only a draft be edited', function (): void {
    expect(InvoiceStatus::Draft->isEditable())->toBeTrue();

    foreach (InvoiceStatus::cases() as $status) {
        if ($status === InvoiceStatus::Draft) {
            continue;
        }

        expect($status->isEditable())->toBeFalse();
    }
});

it('treats everything after draft as an issued document', function (): void {
    expect(InvoiceStatus::Draft->isIssued())->toBeFalse()
        ->and(InvoiceStatus::Unpaid->isIssued())->toBeTrue()
        ->and(InvoiceStatus::Cancelled->isIssued())->toBeTrue();
});

it('issues a draft', function (): void {
    expect(InvoiceStatus::Draft->canTransitionTo(InvoiceStatus::Unpaid))->toBeTrue()
        // Nothing is paid before it is issued.
        ->and(InvoiceStatus::Draft->canTransitionTo(InvoiceStatus::Paid))->toBeFalse();
});

it('treats partial payment as a normal step rather than an error', function (): void {
    expect(InvoiceStatus::Unpaid->canTransitionTo(InvoiceStatus::PartiallyPaid))->toBeTrue()
        ->and(InvoiceStatus::PartiallyPaid->canTransitionTo(InvoiceStatus::Paid))->toBeTrue();
});

it('lets an overdue invoice come back when the customer pays', function (): void {
    expect(InvoiceStatus::Overdue->canTransitionTo(InvoiceStatus::Paid))->toBeTrue()
        ->and(InvoiceStatus::Overdue->canTransitionTo(InvoiceStatus::PartiallyPaid))->toBeTrue()
        ->and(InvoiceStatus::Overdue->canTransitionTo(InvoiceStatus::Unpaid))->toBeTrue();
});

it('refuses to move a cancelled invoice', function (): void {
    foreach (InvoiceStatus::cases() as $target) {
        expect(InvoiceStatus::Cancelled->canTransitionTo($target))->toBeFalse();
    }
});

it('refuses to move a refunded invoice', function (): void {
    // A refunded invoice is corrected by a credit note, not by moving.
    foreach (InvoiceStatus::cases() as $target) {
        expect(InvoiceStatus::Refunded->canTransitionTo($target))->toBeFalse();
    }
});

it('refuses to un-pay an invoice', function (): void {
    expect(InvoiceStatus::Paid->canTransitionTo(InvoiceStatus::Unpaid))->toBeFalse()
        ->and(InvoiceStatus::Paid->canTransitionTo(InvoiceStatus::PartiallyPaid))->toBeFalse()
        ->and(InvoiceStatus::Paid->canTransitionTo(InvoiceStatus::Refunded))->toBeTrue();
});

it('knows which statuses still owe money', function (): void {
    expect(InvoiceStatus::Unpaid->isOwed())->toBeTrue()
        ->and(InvoiceStatus::PartiallyPaid->isOwed())->toBeTrue()
        ->and(InvoiceStatus::Overdue->isOwed())->toBeTrue()
        ->and(InvoiceStatus::Paid->isOwed())->toBeFalse()
        ->and(InvoiceStatus::Cancelled->isOwed())->toBeFalse();
});

it('counts only a successful payment', function (): void {
    expect(PaymentStatus::Completed->isSuccessful())->toBeTrue()
        // Part of it came back, but money did move.
        ->and(PaymentStatus::PartiallyRefunded->isSuccessful())->toBeTrue()
        ->and(PaymentStatus::Pending->isSuccessful())->toBeFalse()
        ->and(PaymentStatus::Failed->isSuccessful())->toBeFalse()
        ->and(PaymentStatus::Refunded->isSuccessful())->toBeFalse();
});

it('treats pending as the only non-final payment state', function (): void {
    expect(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Completed->isFinal())->toBeTrue();
});

it('signs ledger rows by their kind rather than at the call site', function (): void {
    // A refund recorded as a positive number is a ledger that lies twice.
    expect(TransactionKind::Payment->increasesPaid())->toBeTrue()
        ->and(TransactionKind::CreditApplied->increasesPaid())->toBeTrue()
        ->and(TransactionKind::CreditNote->increasesPaid())->toBeTrue()
        ->and(TransactionKind::Refund->increasesPaid())->toBeFalse()
        ->and(TransactionKind::CreditAdded->increasesPaid())->toBeFalse();
});

it('knows which ledger rows move account credit', function (): void {
    expect(TransactionKind::CreditAdded->touchesCredit())->toBeTrue()
        ->and(TransactionKind::CreditApplied->touchesCredit())->toBeTrue()
        ->and(TransactionKind::Payment->touchesCredit())->toBeFalse();
});

it('gives every status a translation key', function (): void {
    foreach (InvoiceStatus::cases() as $status) {
        expect(__($status->labelKey()))->not->toBe($status->labelKey());
    }

    foreach (PaymentStatus::cases() as $status) {
        expect(__($status->labelKey()))->not->toBe($status->labelKey());
    }

    foreach (TransactionKind::cases() as $kind) {
        expect(__($kind->labelKey()))->not->toBe($kind->labelKey());
    }
});
