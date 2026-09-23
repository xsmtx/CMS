<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * One row from the legacy system, as this platform sees it.
 *
 * The `externalId` is a string even when the legacy system used an integer,
 * because the mapping table has to hold ids from systems that use UUIDs, and a
 * column that is sometimes an integer is a column somebody compares loosely.
 *
 * `label` exists for the report. An operator reading four hundred failures
 * needs to recognise the rows, and "client 4182" is not a customer they can
 * telephone about.
 */
final readonly class ImportRecord
{
    /**
     * @param  array<string, mixed>  $data  the legacy row, as read
     */
    public function __construct(
        public ImportDomain $domain,
        public string $externalId,
        public array $data,
        public ?string $label = null,
    ) {}

    /**
     * A legacy value, or null.
     *
     * Reading through a method rather than the array directly so that a column
     * a legacy system spells differently is handled in one place per source
     * rather than in every mapper.
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function text(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? $default : $trimmed;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    public function decimal(string $key): string
    {
        $value = $this->get($key);

        // A legacy decimal arrives as a string or a float depending on the
        // driver. Normalised here rather than in eight mappers, and never
        // turned into a float on the way through: `Money::ofDecimal` wants the
        // digits, not an approximation of them.
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0' : '0';
    }
}
