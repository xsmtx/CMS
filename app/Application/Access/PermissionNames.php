<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\PermissionDefinition;
use Illuminate\Support\Str;

/**
 * What a permission is called, for somebody deciding whether to grant it.
 *
 * The slug is what the code checks. It is not what an operator weighing up
 * whether a support agent may terminate a service should have to read, and a
 * screen full of dotted identifiers is a screen where the wrong box gets
 * ticked.
 *
 * One class rather than a helper on each screen, because the roles form and
 * the new-client form both name permissions, and two vocabularies for one
 * thing is how a product ends up calling one capability two names.
 *
 * **The wording is looked up by slug in one array, not with `__()`.** A slug
 * has dots in it, and `__('access.permissions.crm.customers.view')` asks the
 * translator to walk five levels of nesting rather than to find one key
 * called `crm.customers.view`. It does not fail loudly — it returns the key,
 * which reads as "no translation available" and quietly sent every label
 * down the fallback path.
 *
 * A module's permission has no core translation, so the slug is turned into
 * words rather than shown raw: `acme.custom_reports.view` reads as "Custom
 * reports view". Not perfect, and better than a dotted identifier.
 */
final class PermissionNames
{
    /** @var array<string, mixed>|null */
    private ?array $wording = null;

    public function label(PermissionDefinition|string $permission): string
    {
        $slug = $this->slugOf($permission);
        $entry = $this->wording()[$slug] ?? null;
        $label = is_array($entry) ? ($entry['label'] ?? null) : null;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        // The group is the heading above the row, so it is dropped from the
        // derived name rather than repeated in it.
        $parts = array_slice(explode('.', $slug), 1);

        return Str::ucfirst(str_replace('_', ' ', implode(' ', $parts)));
    }

    /**
     * The sentence under the name.
     *
     * Null rather than a placeholder: a missing description should read as
     * absent, not as broken.
     */
    public function description(PermissionDefinition|string $permission): ?string
    {
        $entry = $this->wording()[$this->slugOf($permission)] ?? null;
        $description = is_array($entry) ? ($entry['description'] ?? null) : null;

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * Read once. A roles screen names eighty of these in one render.
     *
     * @return array<string, mixed>
     */
    private function wording(): array
    {
        if ($this->wording !== null) {
            return $this->wording;
        }

        $wording = trans('access.permissions');

        return $this->wording = is_array($wording) ? $wording : [];
    }

    private function slugOf(PermissionDefinition|string $permission): string
    {
        return $permission instanceof PermissionDefinition ? $permission->slug : $permission;
    }
}
