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
        'margin' => 'Margin %',
        'margin_hint' => 'Added to the provider price. Leave it empty for the provider price; nothing and zero are different answers.',
        'prices' => 'Exact prices',
        'prices_hint' => 'An exact number beats any margin, because somebody who typed a number meant that number. Clearing it removes the row.',
        'price_saved' => 'Price saved.',
        'no_prices' => 'No exact prices. Every product is sold at the provider price plus its margin.',
        'balance' => 'Balance',
        'balance_hint' => 'A positive balance is what the reseller holds with you. Negative means they owe you.',
        'no_balance' => 'No movements yet.',
        'record_entry' => 'Record a movement',
        'entry_recorded' => 'Recorded. The balance is now :balance.',
        'amount' => 'Amount',
        'occurred_at' => 'When the money moved',
        'description' => 'Description',
        'statement' => 'Statement',
        'recorded_by' => 'Recorded by',
        'enabled' => 'May sell',
    ],

    'ledger_kinds' => [
        'payment' => 'Payment received',
        'credit' => 'Credit granted',
        'charge' => 'Charge',
        'withdrawal' => 'Paid back out',
    ],

    'types' => [
        'provider' => 'Provider',
        'reseller' => 'Reseller',
        'customer' => 'Customer',
    ],
];
