<?php

declare(strict_types=1);

/*
 * Setup, which used to be a dropdown and is now a page.
 *
 * Two sections: what an operator configures, and what connects this platform to
 * something else. The second is owner-only and every tile in it is a way an
 * administrator account becomes control of the estate.
 */

return [
    'heading' => 'Setup',
    'description' => 'Everything you configure, and everything that connects this platform to something else.',

    'sections' => [
        'setup' => [
            'title' => 'Setup',
            'description' => 'The catalog, the people who work here and what this installation says.',
        ],
        'integrations' => [
            'title' => 'Apps & Integrations',
            'description' => 'Open to the owner of this installation only. Each of these reaches something outside the platform, or past it.',
        ],
    ],

    'errors' => [
        'super_admin_only' => 'Apps and Integrations is open to super administrators only. Enabling a module runs code this platform did not ship, and adding a server hands out credentials to a machine.',
        'nothing_to_setup' => 'Your role opens none of the setup screens.',
    ],

    'areas' => [
        'modules' => [
            'label' => 'Modules',
            'description' => 'Packages that add payment gateways, provisioning, registrars and more. Nothing runs until you enable it.',
            'unit' => 'enabled',
        ],
        'servers' => [
            'label' => 'Servers',
            'description' => 'The machines accounts are created on, and the credentials that reach them.',
            'unit' => 'configured',
        ],
        'licence' => [
            'label' => 'Licence',
            'description' => 'What this installation is licensed to do, and when it last spoke to the vendor.',
            'unit' => '',
        ],
        'import' => [
            'label' => 'Import',
            'description' => 'Bring customers, services and invoices in from another system. It writes rows directly.',
            'unit' => '',
        ],
        'products' => [
            'label' => 'Products',
            'description' => 'What is for sale, at which price, on which billing cycle.',
            'unit' => 'products',
        ],
        'product_groups' => [
            'label' => 'Product groups',
            'description' => 'How products are arranged on the storefront and in the order form.',
            'unit' => 'groups',
        ],
        'promotions' => [
            'label' => 'Promotions',
            'description' => 'Discount codes, what they apply to and how often they may be used.',
            'unit' => 'promotions',
        ],
        'tlds' => [
            'label' => 'Domain extensions',
            'description' => 'The extensions you sell, their registrar and what they cost.',
            'unit' => 'extensions',
        ],
        'staff' => [
            'label' => 'Staff members',
            'description' => 'The people who sign in to this panel, and which roles they hold.',
            'unit' => 'staff',
        ],
        'roles' => [
            'label' => 'Roles',
            'description' => 'Who may do what. A role is a set of permissions, not a person.',
            'unit' => 'roles',
        ],
        'settings' => [
            'label' => 'General settings',
            'description' => 'What this installation calls itself, where it is, and how it behaves.',
            'unit' => '',
        ],
        'notification_templates' => [
            'label' => 'Notification templates',
            'description' => 'The wording of everything this platform sends. Editing one changes what every customer is told.',
            'unit' => 'templates',
        ],
    ],
];
