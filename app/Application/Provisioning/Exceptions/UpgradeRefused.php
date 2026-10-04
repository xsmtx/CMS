<?php

declare(strict_types=1);

namespace App\Application\Provisioning\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A move between plans that will not be going ahead, and why.
 *
 * One constructor per reason, for the reason `PackageRefused` gives: "the
 * upgrade failed" makes a plan that is not sold in this currency, a service
 * with no term to prorate out of, and an account that is already on the plan
 * look identical in an audit log — and they are three different
 * conversations, two of which a customer can fix themselves.
 *
 * Every one of these is refused **before** an invoice is raised. A customer
 * who has paid for a move this platform then declines is the one outcome this
 * family must not produce.
 */
final class UpgradeRefused extends PlatformException
{
    public static function notRecurring(string $what): self
    {
        return new self(
            (string) __('provisioning.upgrades.errors.not_recurring', ['name' => $what]),
            ['name' => $what],
        );
    }

    public static function noTerm(string $service): self
    {
        return new self(
            (string) __('provisioning.upgrades.errors.no_term', ['name' => $service]),
            ['name' => $service],
        );
    }

    /**
     * A price exists for a cycle and a currency only when a row exists for it
     * (ADR 0021), and there is no rate anywhere in this product to make one.
     */
    public static function notSold(string $product, string $currency): self
    {
        return new self(
            (string) __('provisioning.upgrades.errors.not_sold', [
                'name' => $product,
                'currency' => $currency,
            ]),
            ['name' => $product, 'currency' => $currency],
        );
    }

    public static function samePlan(): self
    {
        return new self((string) __('provisioning.upgrades.errors.same_plan'));
    }

    public static function notUpgradable(string $status): self
    {
        return new self(
            (string) __('provisioning.upgrades.errors.not_upgradable'),
            ['status' => $status],
        );
    }

    /**
     * One at a time. Two open requests against one service would race each
     * other to the provider, and the second would be priced against a term
     * the first is about to change.
     */
    public static function alreadyRequested(): self
    {
        return new self((string) __('provisioning.upgrades.errors.already_requested'));
    }

    public static function notWithdrawable(string $state): self
    {
        return new self(
            (string) __('provisioning.upgrades.errors.not_withdrawable'),
            ['state' => $state],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
