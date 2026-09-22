<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * What a module can actually do.
 *
 * Asked rather than assumed, so the interface offers a suspend button only
 * where suspending means something, and core never calls a method an
 * adapter cannot honour. A registrar-style module that can create and
 * terminate but not suspend is a normal thing, not a broken one.
 */
final readonly class ModuleCapabilities
{
    /**
     * @param  list<string>  $productTypes  Empty means "any".
     */
    public function __construct(
        public bool $create = true,
        public bool $suspend = false,
        public bool $unsuspend = false,
        public bool $terminate = false,
        public bool $changePackage = false,
        public bool $sync = false,
        public bool $testConnection = false,

        /** The module issues credentials the customer needs to be told. */
        public bool $issuesCredentials = false,

        /** The module runs against a server; a manual one does not. */
        public bool $needsServer = true,

        public array $productTypes = [],
    ) {}

    public function supports(ServiceOperation $operation): bool
    {
        return match ($operation) {
            ServiceOperation::Create => $this->create,
            ServiceOperation::Suspend => $this->suspend,
            ServiceOperation::Unsuspend => $this->unsuspend,
            ServiceOperation::Terminate => $this->terminate,
            ServiceOperation::ChangePackage => $this->changePackage,
            ServiceOperation::Sync => $this->sync,
            ServiceOperation::TestConnection => $this->testConnection,
        };
    }
}
