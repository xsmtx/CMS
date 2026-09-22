<?php

declare(strict_types=1);

namespace App\Support\Audit\Contracts;

/**
 * Implemented by models that can describe themselves in an audit trail in a
 * way a human reviewer will recognise a year later.
 */
interface AuditLabel
{
    public function auditLabel(): string;
}
