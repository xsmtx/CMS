<?php

declare(strict_types=1);

use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | There is no meaningful "default" guard in this application: every
    | surface names the guard it authenticates against. The default is set to
    | `staff` only because framework internals that resolve a guard without
    | being told one are, in practice, always admin-side.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'staff'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'staff_users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    |
    | Staff and customers are separate authenticatables with separate session
    | cookies. That separation is the reason two tables exist: a bug in guard
    | resolution cannot hand a customer a staff session, because the two never
    | share storage.
    |
    */

    'guards' => [
        'staff' => [
            'driver' => 'session',
            'provider' => 'staff_users',
        ],

        'client' => [
            'driver' => 'session',
            'provider' => 'contacts',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'staff_users' => [
            'driver' => 'eloquent',
            'model' => StaffUser::class,
        ],

        'contacts' => [
            'driver' => 'eloquent',
            'model' => Contact::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset
    |--------------------------------------------------------------------------
    |
    | Separate brokers and separate token tables, so a staff reset token can
    | never be redeemed on the client area or the reverse.
    |
    */

    'passwords' => [
        'staff_users' => [
            'provider' => 'staff_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'contacts' => [
            'provider' => 'contacts',
            'table' => 'contact_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | How long a confirmed password stays valid before a high-risk action
    | asks again. Three hours is Laravel's default and too long for actions
    | that grant access or delete data, so this is deliberately shorter.
    |
    */

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 900),

];
