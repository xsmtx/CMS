<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Infrastructure\Shared\Models\CurrencyRecord;
use Illuminate\Contracts\Session\Session;

/**
 * Which currency the storefront is showing.
 *
 * The visitor's choice is remembered in the session and always validated
 * against the currencies the installation actually trades in, so a crafted
 * value cannot make the storefront quote a currency nobody configured.
 *
 * Choosing a currency changes which prices are shown, never how they are
 * calculated: each one was entered by an operator and is displayed as typed.
 */
final readonly class StorefrontCurrency
{
    private const string SESSION_KEY = 'storefront.currency';

    public function __construct(private Session $session) {}

    public function current(): ?string
    {
        $available = $this->available();

        if ($available === []) {
            return null;
        }

        $chosen = $this->session->get(self::SESSION_KEY);

        if (is_string($chosen) && in_array($chosen, $available, true)) {
            return $chosen;
        }

        return $this->base() ?? $available[0];
    }

    public function choose(string $code): bool
    {
        $code = strtoupper($code);

        if (! in_array($code, $this->available(), true)) {
            return false;
        }

        $this->session->put(self::SESSION_KEY, $code);

        return true;
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        /** @var list<string> $codes */
        $codes = CurrencyRecord::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('code')
            ->pluck('code')
            ->all();

        return $codes;
    }

    private function base(): ?string
    {
        $code = CurrencyRecord::query()
            ->where('is_active', true)
            ->where('is_base', true)
            ->value('code');

        return is_string($code) ? $code : null;
    }
}
