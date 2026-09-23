<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainAction;
use App\Domain\Domains\DomainName;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Domains\RegistrarRegistry;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Is this name free, and what would it cost.
 *
 * Two rules, and both of them are about not lying to a customer.
 *
 * **A registry that did not answer has not said the name is available.**
 * `AvailabilityResult` has three states for exactly this reason, and every
 * path through here preserves the third rather than collapsing it into a
 * cheerful "yes".
 *
 * **A name this installation already holds is taken**, whoever holds it.
 * Asking the registry would say the same thing, eventually, but asking
 * ourselves is instant and is right even when the registrar is down.
 *
 * The cache is short on purpose. A registry answer is worth a minute; a
 * search box will ask the same question five times in that minute, and a
 * name that was free an hour ago is not evidence of anything.
 */
final readonly class CheckDomainAvailability
{
    public function __construct(
        private TldCatalog $catalog,
        private RegistrarRegistry $registrars,
    ) {}

    /**
     * @return list<DomainOffer>
     */
    public function handle(string $input, string $currencyCode, int $years = 1): array
    {
        $name = $this->catalog->parse($input);
        $tld = $this->catalog->find($name->tld);

        if (! $tld instanceof Tld) {
            return [];
        }

        return [$this->offerFor($name, $tld, $currencyCode, $years)];
    }

    /**
     * The same label across every extension on sale, for the grid a search
     * box shows under the exact match.
     *
     * @return list<DomainOffer>
     */
    public function suggestions(string $label, string $currencyCode, int $limit = 6): array
    {
        $offers = [];

        foreach ($this->catalog->offeredIn($currencyCode) as $tld) {
            if (count($offers) >= $limit) {
                break;
            }

            try {
                $name = DomainName::of($label, $tld->extension);
            } catch (Throwable) {
                continue;
            }

            $offers[] = $this->offerFor($name, $tld, $currencyCode, $this->defaultTerm($tld, $currencyCode));
        }

        return $offers;
    }

    private function offerFor(DomainName $name, Tld $tld, string $currencyCode, int $years): DomainOffer
    {
        $years = $years > 0 ? $years : $this->defaultTerm($tld, $currencyCode);
        $price = $tld->priceFor(DomainAction::Register, $years, $currencyCode);

        if ($price === null) {
            // Not sold on that term in that currency. Not searched for
            // either: a registry call for a name nobody can buy is a call
            // wasted and a page a customer cannot act on.
            return DomainOffer::notSold($name, $years);
        }

        return new DomainOffer(
            name: $name,
            years: $years,
            price: $price,
            availability: $this->availability($name, $tld),
            transferPrice: $tld->allows_transfer
                ? $tld->priceFor(DomainAction::Transfer, $years, $currencyCode)
                : null,
        );
    }

    private function availability(DomainName $name, Tld $tld): AvailabilityResult
    {
        // Ours already. Instant, and right even when the registrar is down.
        if ($this->alreadyHeld((string) $name)) {
            return AvailabilityResult::taken($name);
        }

        $registrar = $tld->registrar === null ? null : $this->registrars->find($tld->registrar);

        if (! $registrar instanceof DomainRegistrar || ! $registrar->capabilities()->checkAvailability) {
            return AvailabilityResult::unknown($name, (string) __('domains.errors.cannot_check'));
        }

        $key = 'domains.availability:'.$registrar->key().':'.$name;

        /** @var AvailabilityResult $result */
        $result = Cache::remember(
            $key,
            (int) config('platform.domains.availability_ttl', 60),
            static function () use ($registrar, $name): AvailabilityResult {
                try {
                    return $registrar->checkAvailability($name);
                } catch (Throwable $exception) {
                    return AvailabilityResult::unknown($name, $exception->getMessage());
                }
            },
        );

        return $result;
    }

    private function alreadyHeld(string $name): bool
    {
        return Domain::query()
            ->where('name', $name)
            ->whereNotIn('status', ['cancelled', 'deleted'])
            ->exists();
    }

    private function defaultTerm(Tld $tld, string $currencyCode): int
    {
        $terms = $tld->termsFor(DomainAction::Register, $currencyCode);

        return $terms[0] ?? $tld->min_years;
    }
}
