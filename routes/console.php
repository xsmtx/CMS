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

/*
 * The automation tasks.
 *
 * Every one of them asks a question about state rather than about elapsed
 * time (ADR 0031), so the exact minute does not matter and a missed run is
 * caught up by the next one. `withoutOverlapping` because a sweep that is
 * still going when the next one starts should be left alone, and
 * `onOneServer` because two application containers must not both invoice
 * the same renewal.
 */
Schedule::command('platform:heartbeat')
    ->everyFiveMinutes()
    ->onOneServer();

Schedule::command('platform:run retries')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run overdue')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run renewals')
    ->dailyAt('00:40')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// After renewals, so an invoice raised this morning is not chased this
// morning as well.
Schedule::command('platform:run dunning')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run domain-expiry')
    ->dailyAt('01:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run sync')
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run webhooks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run cleanup')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
