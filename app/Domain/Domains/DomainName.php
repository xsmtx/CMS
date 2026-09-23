<?php

declare(strict_types=1);

namespace App\Domain\Domains;

use App\Domain\Domains\Exceptions\InvalidDomainName;
use Stringable;

/**
 * A domain name, validated and split.
 *
 * Parsing lives here rather than in a form request because the order line,
 * the provisioning call and the registrar adapter all need the same split,
 * and three implementations of "everything after the first dot" is three
 * chances to disagree about `co.uk`.
 *
 * Two ways in, because there are two questions:
 *
 * - `parse()` splits at the first dot. It is right for a **hostname** — the
 *   name a hosting account is set up for — where nobody is being sold the
 *   extension and `www.example.co.uk` splitting as `www` + `example.co.uk`
 *   costs nothing.
 * - `parseWithin()` splits against the extensions an installation actually
 *   sells, longest match first. It is the only correct one when the TLD is
 *   the thing being priced: `co.uk` is two labels, `uk` is one, and no
 *   amount of counting dots will tell them apart.
 */
final readonly class DomainName implements Stringable
{
    private function __construct(
        public string $value,
        public string $sld,
        public string $tld,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Split at the first dot.
     *
     * For a hostname, where the extension is not being sold.
     */
    public static function parse(string $input): self
    {
        $normalised = self::normalise($input);

        if (! str_contains($normalised, '.') || ! self::isValid($normalised)) {
            throw InvalidDomainName::for($input);
        }

        $labels = explode('.', $normalised);
        $sld = array_shift($labels);

        return new self($normalised, $sld, implode('.', $labels));
    }

    /**
     * Split against the extensions this installation sells.
     *
     * The only correct split when the TLD is what is being priced. Longest
     * match wins, so `co.uk` beats `uk`.
     *
     * @param  list<string>  $knownTlds
     */
    public static function parseWithin(string $input, array $knownTlds): self
    {
        // `www.` is stripped here and not in `parse()`: somebody typing
        // `www.example.co.uk` into a search box wants to buy
        // `example.co.uk`, while `www.example.com` is a perfectly good
        // hostname for a hosting account.
        $normalised = (string) preg_replace('#^www\.#', '', self::normalise($input));

        if (! str_contains($normalised, '.') || ! self::isValid($normalised)) {
            throw InvalidDomainName::for($input);
        }

        $matched = null;

        foreach ($knownTlds as $tld) {
            $suffix = '.'.mb_strtolower($tld);

            if (! str_ends_with($normalised, $suffix)) {
                continue;
            }

            if ($matched === null || mb_strlen($tld) > mb_strlen($matched)) {
                $matched = mb_strtolower($tld);
            }
        }

        if ($matched === null) {
            throw InvalidDomainName::unsupportedTld($input);
        }

        $sld = mb_substr($normalised, 0, -1 * (mb_strlen($matched) + 1));

        if ($sld === '' || str_contains($sld, '.')) {
            // Either nothing before the extension, or a subdomain. Neither
            // is a name somebody can register.
            throw InvalidDomainName::for($input);
        }

        return new self($normalised, $sld, $matched);
    }

    /**
     * A name and an extension already known to be separate.
     */
    public static function of(string $sld, string $tld): self
    {
        $sld = self::normalise($sld);
        $tld = mb_strtolower(ltrim(self::normalise($tld), '.'));

        if ($sld === '' || $tld === '' || str_contains($sld, '.')) {
            throw InvalidDomainName::for($sld.'.'.$tld);
        }

        $value = $sld.'.'.$tld;

        if (! self::isValid($value)) {
            throw InvalidDomainName::for($value);
        }

        return new self($value, $sld, $tld);
    }

    /**
     * Strip what people paste: a scheme, a path, surrounding space and a
     * trailing dot.
     */
    private static function normalise(string $input): string
    {
        $value = mb_strtolower(trim($input));
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = explode('/', $value)[0];

        return trim($value, '.');
    }

    /**
     * Labels are letters, digits and hyphens, not starting or ending with
     * a hyphen. Punycode passes as-is; a Unicode name is converted by the
     * caller before it reaches here.
     */
    private static function isValid(string $value): bool
    {
        return preg_match(
            '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
            $value,
        ) === 1;
    }
}
