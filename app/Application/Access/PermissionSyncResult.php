<?php

declare(strict_types=1);

namespace App\Application\Access;

/**
 * Outcome of mirroring the permission registry into the database.
 */
final readonly class PermissionSyncResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $orphaned
     * @param  list<string>  $restored
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $orphaned = [],
        public array $restored = [],
    ) {}

    public function changedAnything(): bool
    {
        return $this->created !== []
            || $this->updated !== []
            || $this->orphaned !== []
            || $this->restored !== [];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'created' => count($this->created),
            'updated' => count($this->updated),
            'orphaned' => count($this->orphaned),
            'restored' => count($this->restored),
        ];
    }
}
