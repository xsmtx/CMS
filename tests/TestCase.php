<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Audit\Contracts\AuditRecorder;
use App\Support\Audit\Facades\Audit;
use App\Support\Audit\FakeAuditRecorder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Replace the audit recorder with an in-memory fake and return it, so a
     * test can assert on what a feature intended to record without reading
     * the database back.
     */
    protected function fakeAudit(): FakeAuditRecorder
    {
        $fake = new FakeAuditRecorder;

        $this->app->instance(AuditRecorder::class, $fake);
        Audit::swap($fake);

        return $fake;
    }
}
