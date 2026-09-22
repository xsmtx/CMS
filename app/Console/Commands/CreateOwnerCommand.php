<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\AccountStatus;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first staff account.
 *
 * A fresh installation has a provider organization and no way to sign in.
 * This is that way, and it is a console command rather than a web setup page
 * because whoever can run artisan already owns the server; a public
 * "create the first admin" URL is a race worth losing.
 */
final class CreateOwnerCommand extends Command
{
    protected $signature = 'identity:create-owner
                            {--name= : Display name}
                            {--email= : Email address}
                            {--password= : Password (prompted when omitted)}';

    protected $description = 'Create the first staff account and give it the super-admin role';

    public function handle(
        SyncPermissions $syncPermissions,
        PermissionRegistry $registry,
        OrganizationContext $organizations,
    ): int {
        $provider = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first();

        if ($provider === null) {
            $this->error('No provider organization exists. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?? text('Name', required: true);
        $email = $this->option('email') ?? text('Email address', required: true);

        // Never echoed, never passed on an argv that shells record in
        // history unless the operator insists.
        $secret = $this->option('password') ?? password('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $secret],
            [
                'name' => ['required', 'string', 'max:191'],
                'email' => ['required', 'email:filter', 'max:191', 'unique:staff_users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $syncPermissions->handle($registry);

        $staff = $organizations->runAs($provider->id, fn (): StaffUser => StaffUser::query()->create([
            'organization_id' => $provider->id,
            'name' => $name,
            'email' => $email,
            'password' => $secret,
            'status' => AccountStatus::Active->value,
        ]));

        $staff->assignRole(SystemRole::SuperAdmin);

        Audit::action('identity.staff.created')
            ->bySystem('identity:create-owner')
            ->on($staff)
            ->because('First staff account created during installation.')
            ->forOrganization($provider->id)
            ->withMetadata(['role' => SystemRole::SuperAdmin->value])
            ->write();

        $this->info(sprintf('Created %s <%s> as super administrator.', $name, $email));
        $this->line('Sign in at '.url('/admin/login'));

        return self::SUCCESS;
    }
}
