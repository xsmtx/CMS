<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which languages this installation speaks, and what they are called.
 *
 * One place, because there were already two private copies — the client form
 * and the notification template editor each built the same list from the same
 * config with the same upper-cased label, and a third was about to be written
 * for the switch in the topbar.
 *
 * The label is the code (`EN`, `TR`) rather than the language's own name.
 * It is what the template editor has always shown, it is unambiguous next to
 * a flag nobody drew, and it does not need translating in either direction.
 */
final readonly class Locales
{
    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        /** @var list<string> $locales */
        $locales = array_values(array_filter(
            (array) config('platform.locales', ['en']),
            static fn (mixed $locale): bool => is_string($locale) && $locale !== '',
        ));

        return $locales === [] ? ['en'] : $locales;
    }

    public static function allows(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::supported(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            static fn (string $locale): array => [
                'value' => $locale,
                'label' => mb_strtoupper($locale),
            ],
            self::supported(),
        ));
    }
}
