<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Document numbers belong to the seller.
 *
 * They were allocated against the organization that owns the document. A
 * customer is an organization of its own here, so every customer got their
 * own ORD-000001 and INV-000001, and the unique index — which was scoped to
 * that same organization — was satisfied by numbers that collided with
 * every other customer's.
 *
 * Three things happen here:
 *
 * 1. Sequences that belong to a customer organization are folded into their
 *    seller's, so the next number carries on past everything already issued
 *    rather than starting again at one.
 * 2. Documents whose numbers collide are renumbered from that sequence. The
 *    earliest of each group keeps the number, because it is the one most
 *    likely to have been sent to somebody.
 * 3. The unique index moves from (organization_id, number) to the number
 *    alone, so a collision is refused by the database rather than found
 *    later by a customer with two invoices called INV-000001.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, key: string}>
     */
    private array $documents = [
        ['table' => 'orders', 'key' => 'order'],
        ['table' => 'invoices', 'key' => 'invoice'],
        ['table' => 'credit_notes', 'key' => 'credit_note'],
    ];

    public function up(): void
    {
        $sellers = $this->sellers();

        foreach ($this->documents as $document) {
            if (! Schema::hasTable($document['table'])) {
                continue;
            }

            $this->renumber($document['table'], $document['key'], $sellers);
        }

        // Sequences under a customer organization are no longer read by
        // anything; their counts have been folded into the seller's above.
        DB::table('number_sequences')
            ->whereIn('organization_id', DB::table('organizations')
                ->where('type', 'customer')
                ->pluck('id'))
            ->delete();

        $this->moveIndex('orders', 'orders_number_unique');
        $this->moveIndex('invoices', 'invoices_number_unique');
        $this->moveIndex('credit_notes', 'credit_notes_number_unique');
    }

    public function down(): void
    {
        foreach (['orders', 'invoices', 'credit_notes'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_number_unique');
                $blueprint->unique(['organization_id', 'number'], $table.'_number_unique');
            });
        }
    }

    /**
     * Every organization mapped to the organization that sells to it.
     *
     * @return array<string, string>
     */
    private function sellers(): array
    {
        /** @var Collection<string, stdClass> $organizations */
        $organizations = DB::table('organizations')
            ->select(['id', 'parent_id', 'type'])
            ->get()
            ->keyBy('id');

        $sellers = [];

        foreach ($organizations as $id => $organization) {
            $current = $organization;

            while ($current->type === 'customer' && $current->parent_id !== null) {
                $parent = $organizations->get($current->parent_id);

                if ($parent === null) {
                    break;
                }

                $current = $parent;
            }

            $sellers[(string) $id] = (string) $current->id;
        }

        return $sellers;
    }

    /**
     * @param  array<string, string>  $sellers
     */
    private function renumber(string $table, string $key, array $sellers): void
    {
        /** @var Collection<int, stdClass> $rows */
        $rows = DB::table($table)
            ->select(['id', 'organization_id', 'number', 'created_at'])->oldest()
            ->orderBy('id')
            ->get();

        /** @var array<string, int> $highest */
        $highest = [];
        /** @var array<string, true> $taken */
        $taken = [];
        /** @var list<array{id: string, organization_id: string, number: string}> $collisions */
        $collisions = [];

        foreach ($rows as $row) {
            $seller = $sellers[(string) $row->organization_id] ?? (string) $row->organization_id;
            $value = $this->valueOf((string) $row->number);

            if ($value !== null) {
                $highest[$seller] = max($highest[$seller] ?? 0, $value);
            }

            if (isset($taken[(string) $row->number])) {
                $collisions[] = [
                    'id' => (string) $row->id,
                    'organization_id' => (string) $row->organization_id,
                    'number' => (string) $row->number,
                ];

                continue;
            }

            $taken[(string) $row->number] = true;
        }

        foreach ($collisions as $row) {
            $seller = $sellers[$row['organization_id']] ?? $row['organization_id'];
            $next = ($highest[$seller] ?? 0) + 1;
            $highest[$seller] = $next;

            DB::table($table)
                ->where('id', $row['id'])
                ->update(['number' => $this->format($row['number'], $next)]);
        }

        foreach ($highest as $seller => $value) {
            $this->advance($seller, $key, $value + 1);
        }
    }

    /**
     * The counter inside a number, or null if it does not carry one — a
     * draft invoice is named by random characters until it is issued.
     */
    private function valueOf(string $number): ?int
    {
        if (preg_match('/(\d+)$/', $number, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function format(string $number, int $value): string
    {
        $prefix = preg_replace('/\d+$/', '', $number) ?? '';
        $digits = mb_strlen($number) - mb_strlen($prefix);

        return $prefix.str_pad((string) $value, max($digits, 6), '0', STR_PAD_LEFT);
    }

    private function advance(string $organizationId, string $key, int $next): void
    {
        $existing = DB::table('number_sequences')
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->first();

        if ($existing === null) {
            // Left for the allocator to create with the configured prefix
            // and padding; recording the count here would guess at both.
            DB::table('number_sequences')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'key' => $key,
                'prefix' => $key === 'order' ? 'ORD-' : ($key === 'invoice' ? 'INV-' : 'CN-'),
                'next_value' => $next,
                'padding' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((int) $existing->next_value >= $next) {
            return;
        }

        DB::table('number_sequences')
            ->where('id', $existing->id)
            ->update(['next_value' => $next, 'updated_at' => now()]);
    }

    /**
     * Swap the composite unique for one on the number alone.
     *
     * The composite was also the index the organization foreign key leans
     * on, and MariaDB will not drop an index a constraint needs — so the
     * replacement goes in first.
     */
    private function moveIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! $this->hasIndexLedBy($table, 'organization_id', $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->index('organization_id', $table.'_organization_index');
            });
        }

        if ($this->hasIndexLedBy($table, 'organization_id', null, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropUnique($name);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->unique('number', $name);
            });
        }
    }

    /**
     * Whether some index other than $ignore starts with $column — or, when
     * $only is given, whether that one index does.
     */
    private function hasIndexLedBy(string $table, string $column, ?string $ignore = null, ?string $only = null): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            $name = (string) $index['name'];

            if ($name === $ignore || ($only !== null && $name !== $only)) {
                continue;
            }

            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
