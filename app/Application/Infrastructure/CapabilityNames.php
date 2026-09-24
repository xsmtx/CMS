<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\Capability;
use Illuminate\Support\Str;

/**
 * What a capability is called, for somebody deciding whether to allow it.
 *
 * `PermissionNames` with a different array, and it exists for exactly the same
 * reason. A capability's value has dots in it, so
 * `__('infrastructure.capabilities.firewall.policy.write')` asks the translator
 * to walk four levels of nesting, gets nothing, and returns the key — which reads
 * as "untranslated" while looking like it works. The wording therefore lives in
 * one array keyed by the dotted value, read by this.
 *
 * The derived fallback matters more here than it does for permissions, because
 * `Capability` has fifty members and a phase that adds a contract will add its
 * capabilities before somebody writes the sentences. `firewall.policy.write`
 * reading as "Policy write" is legible; reading as the key is not.
 */
final class CapabilityNames
{
    /** @var array<string, mixed>|null */
    private ?array $wording = null;

    public function label(Capability|string $capability): string
    {
        $value = $capability instanceof Capability ? $capability->value : $capability;
        $entry = $this->wording()[$value] ?? null;
        $label = is_array($entry) ? ($entry['label'] ?? null) : null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        // The area is the heading above the row, so it is dropped from the
        // derived name rather than repeated in it.
        $parts = array_slice(explode('.', $value), 1);

        return Str::ucfirst(str_replace('_', ' ', implode(' ', $parts)));
    }

    public function description(Capability|string $capability): ?string
    {
        $value = $capability instanceof Capability ? $capability->value : $capability;
        $entry = $this->wording()[$value] ?? null;
        $description = is_array($entry) ? ($entry['description'] ?? null) : null;

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function wording(): array
    {
        if ($this->wording !== null) {
            return $this->wording;
        }

        $wording = trans('infrastructure.capabilities');

        return $this->wording = is_array($wording) ? $wording : [];
    }
}
