<?php

declare(strict_types=1);

use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Infrastructure\Notifications\Models\InAppNotification;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lives in the feature suite rather than the architecture suite because the
 * question is half schema: it needs a booted application and a migrated
 * database to know which tables carry the column.
 *
 * A model with an `organization_id` column and no boundary scope is a
 * tenancy leak waiting to be written — reads unfiltered, writes unstamped —
 * and nothing else in the codebase would notice.
 */
it('bounds every model that carries an organization', function (): void {
    $unscoped = [];

    foreach (ownedModels() as $class) {
        /** @var Model $model */
        $model = new $class;

        // Most owned models get the scope from the trait; the two whose
        // organization is nullable register the same named scope by hand,
        // because a row written by the system has no organization to stamp.
        if (! array_key_exists('organization', $model->getGlobalScopes())) {
            $unscoped[] = $class;
        }
    }

    expect($unscoped)->toBe([]);
});

it('stamps the organization on write for every model that can carry one', function (): void {
    // Nullable-organization models are exempt by design: a failed sign-in
    // has no actor to attribute, a webhook arrives before anyone knows
    // which organization it concerns, and a message to a staff member has
    // no customer behind it. Refusing to record any of them would be worse
    // than recording it without one. Each still carries the nullable
    // boundary scope, which the test above checks.
    $nullable = [
        AuditLog::class,
        GatewayEventRecord::class,
        InAppNotification::class,
        LoginHistory::class,
        NotificationDelivery::class,
    ];

    $unstamped = [];

    foreach (ownedModels() as $class) {
        if (in_array($class, $nullable, true)) {
            continue;
        }

        if (! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
            $unstamped[] = $class;
        }
    }

    expect($unstamped)->toBe([]);
});

/**
 * Every concrete infrastructure model whose table has an `organization_id`.
 *
 * @return list<class-string<Model>>
 */
function ownedModels(): array
{
    $classes = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Infrastructure')));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR);
        $class = 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;

        // Bounded by its own materialised path rather than by a column.
        if ($model instanceof Organization) {
            continue;
        }

        if (! Schema::hasColumn($model->getTable(), 'organization_id')) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}
