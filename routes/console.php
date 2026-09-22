<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| The scheduler heartbeat proves the scheduler container is alive; Phase 12
| alerts on a missing heartbeat. Automation tasks are registered by Phase 7.
|
*/

Schedule::command('platform:permissions:sync')
    ->daily()
    ->onOneServer()
    ->runInBackground();
