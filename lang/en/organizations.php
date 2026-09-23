<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_permitted' => 'You do not have access to organizations.',
        'email_taken' => 'That address already has an account on this installation.',
        'no_provider' => 'This installation has no provider organization. Seed one before adding a reseller.',
        'no_role' => 'This installation has no administrator role. Seed the system roles before adding a reseller.',
        'reseller_only' => 'That organization is not a reseller.',
    ],

    'resellers' => [
        'title' => 'Resellers',
        'subtitle' => 'Who sells your products under their own name. A reseller owns its customers and sees nothing else.',
        'add' => 'Add Reseller',
        'created' => ':name has been created. Its owner reaches the panel through the password reset flow.',
        'empty' => 'No resellers yet.',
        'name' => 'Trading name',
        'slug' => 'Slug',
        'owner' => 'Owner',
        'owner_name' => 'Owner name',
        'owner_email' => 'Owner email',
        'customers' => 'Customers',
        'products' => 'Products',
        'created_at' => 'Created',
        'availability' => 'What they may sell',
        'availability_saved' => 'Availability saved.',
        'availability_empty' => 'A reseller with nothing ticked sells nothing. Absence is a refusal here, not a shortcut for everything.',
    ],

    'types' => [
        'provider' => 'Provider',
        'reseller' => 'Reseller',
        'customer' => 'Customer',
    ],
];
