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

/*
 * The Resource Graph projection.
 *
 * Hourly, `onOneServer` like the rest: two containers projecting the same
 * installation would each try to create the node the other had just created, and
 * one of them would lose the unique key and record a failure for a row that is
 * perfectly fine.
 */
Schedule::command('platform:run resources')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Telemetry collection.
 *
 * Every five minutes, and `withoutOverlapping` matters more here than anywhere
 * else on this page: a run that is still waiting on an unresponsive device must
 * not be joined by the next one, or an installation with one slow adapter ends up
 * with fifty processes queued against it.
 */
Schedule::command('platform:run telemetry')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * The adapter health sweep.
 *
 * Alongside collection rather than inside it: a source that is answering
 * slowly and a source that is gone are different facts, and an operator
 * looking at a quiet graph needs to know which one they have. The run asks
 * only the adapters whose own declared pace says they are due, so this is how
 * often the platform *considers* asking.
 */
/*
 * Topology discovery.
 *
 * Hourly, beside the projection rather than beside telemetry. A chassis does
 * not grow a port between one five-minute sweep and the next, and asking a
 * firewall's management plane to enumerate itself twelve times an hour is how
 * an inventory job starts making the firewall drop packets.
 */
/*
 * Just-in-time grants that have run out.
 *
 * Every five minutes, and nothing depends on it: whether a grant is live is
 * a question about its own timestamps, asked when somebody uses it. This is
 * how quickly the record catches up with what is already true.
 */
/*
 * Attacks, from whatever is mitigating them.
 *
 * Every five minutes, and the window comes from the rows rather than from the
 * clock: the sweep asks each source about everything since the last event it
 * reported, less an overlap. A worker that was down for an afternoon catches
 * up rather than losing an afternoon of attacks permanently.
 */
/*
 * Alert rules.
 *
 * Every minute, and the only task on this page that runs that often: an alert
 * an operator hears about nine minutes late is one they find out about from a
 * customer instead. It is cheap because every subject but `metric` reads a
 * table this installation already holds.
 */
Schedule::command('platform:run alerts')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run ddos')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run access-grants')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run topology')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run adapter-health')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run cleanup')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * The licence heartbeat.
 *
 * Hourly, and the task itself decides whether to speak to the vendor — it
 * calls only when the stored state says the deadline is half way past. So this
 * is how often the installation *asks itself*, not how often it telephones a
 * third party.
 *
 * `onOneServer`, like everything else here: two application servers
 * heartbeating the same installation would look to the vendor like two
 * activations of one licence.
 */
Schedule::command('platform:run licence')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Forgetting abuse evidence that is past its deadline.
 *
 * Daily, and deliberately not more often: a retention deadline measured in
 * months does not need checking every five minutes, and a deletion sweep that
 * ran constantly would be one nobody watched. It asks a question about rows,
 * so a scheduler that was down for a week catches up rather than leaving a
 * fortnight of somebody's data behind permanently.
 */
Schedule::command('platform:run abuse-retention')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Asking every certificate source what it currently has deployed.
 *
 * Hourly. A certificate's life is measured in weeks and the thing an operator
 * wants to hear about — a renewal that silently failed — is visible for days
 * before it matters; asking a control panel every five minutes for a list
 * that changes twice a month is a denial of service against your own
 * operator.
 */
Schedule::command('platform:run certificates')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Asking every DNS source about the zones this installation holds.
 *
 * Daily. A zone changes when somebody changes it, and an SPF record that has
 * been wrong for a month will still be wrong in an hour — asking a provider
 * for four hundred zones every five minutes is a rate limit and a bill.
 */
Schedule::command('platform:run zone-health')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * Asking every reputation source about this installation's own addresses.
 *
 * Daily, and deliberately not at the same minute as the zone sweep: both
 * talk to somebody else's API, and two of ours arriving together is the
 * kind of burst a rate limiter answers.
 */
/*
 * Asking every backup source what it is currently protecting.
 *
 * Hourly. A nightly job finishes at some hour of the night and an operator
 * wants to see it that morning.
 */
Schedule::command('platform:run backups')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

Schedule::command('platform:run reputation')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
