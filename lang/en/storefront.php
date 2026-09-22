<?php

declare(strict_types=1);

return [
    'meta_description' => 'Hosting automation platform. This installation is not open for orders yet.',
    'skip_to_content' => 'Skip to content',
    'primary_navigation' => 'Primary',

    'client_area' => 'Client area',
    'admin' => 'Admin',

    'headline' => ':brand is installed and running.',
    'subheadline' => 'The storefront has no catalog yet. Sign in to the admin area to configure products, or to the client area to manage an existing account.',

    'next_steps_title' => 'Before this page sells anything',
    'next_steps_body' => 'Three things have to exist first. The admin area walks through each one.',

    'next_steps' => [
        [
            'title' => 'Create a staff account',
            'body' => 'The first account owns the provider organization and every permission under it.',
        ],
        [
            'title' => 'Add products and pricing',
            'body' => 'Groups, billing cycles, configurable options and the currencies you sell in.',
        ],
        [
            'title' => 'Connect a payment gateway',
            'body' => 'Orders stay unpaid until a gateway can capture and confirm them.',
        ],
    ],

    'footer_note' => 'Install a storefront theme to replace this page.',
];
