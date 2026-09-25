<?php

declare(strict_types=1);

return [
    /*
     * Where a product sits in its life: on sale, off the menu but still
     * renewing, or finished. `VocabularyTest` walks every enum in app/Domain
     * that names itself, and these were printing their own key.
     */
    'statuses' => [
        'active' => 'Active',
        'hidden' => 'Hidden',
        'retired' => 'Retired',
    ],

    'groups' => [
        'title' => 'Product groups',
        'subtitle' => 'How products are arranged on the storefront.',
        'create' => 'New group',
        'edit' => 'Edit group',
        'empty' => 'No product groups yet.',
        'saved' => 'Group saved.',
        'deleted' => 'Group deleted.',
        'products_count' => ':count product|:count products',
    ],

    'products' => [
        'title' => 'Products',
        'subtitle' => 'What customers can buy.',
        'create' => 'New product',
        'edit' => 'Edit product',
        'empty' => 'No products in this group yet.',
        'sold_out' => 'Sold out',
        'stock_remaining' => ':count left',
        'unlimited_stock' => 'Unlimited',
        'requires_domain' => 'Collects a domain at checkout',
        'saved' => 'Product saved.',
        'deleted' => 'Product deleted.',
    ],

    'pricing' => [
        'title' => 'Pricing',
        'subtitle' => 'One row per billing cycle, one column per currency.',
        'recurring' => 'Recurring',
        'setup' => 'Setup fee',
        'not_sold' => 'Not sold',
        'not_sold_hint' => 'Leave a cell empty to stop selling on that cycle.',
        'add_currency' => 'Add currency',
        'save' => 'Save prices',
        'saved' => 'Prices saved.',
        'negative_not_allowed' => 'A product price cannot be negative.',
    ],

    'options' => [
        'title' => 'Configurable options',
        'subtitle' => 'Choices that change what the product is, priced as a difference.',
        'create' => 'New option group',
        'edit' => 'Edit option group',
        'empty' => 'No configurable options yet.',
        'required' => 'Required',
        'default' => 'Default',
        'add_choice' => 'Add choice',
        'saved' => 'Options saved.',
        'deleted' => 'Option group deleted.',
    ],

    'addons' => [
        'title' => 'Addons',
        'subtitle' => 'Separate lines a customer can add or drop later.',
        'create' => 'New addon',
        'edit' => 'Edit addon',
        'empty' => 'No addons yet.',
        'saved' => 'Addon saved.',
        'deleted' => 'Addon deleted.',
    ],

    'currencies' => [
        'title' => 'Currencies',
        'subtitle' => 'What the installation trades in, and at what rate.',
        'create' => 'Add currency',
        'edit' => 'Edit currency',
        'base' => 'Base currency',
        'base_hint' => 'Every other rate is quoted against this one.',
        'rate' => 'Rate',
        'rate_hint' => 'Used for reporting. Prices are never converted at checkout.',
        'active' => 'Active',
        'history' => 'Rate history',
        'rate_format' => 'The rate must be a decimal number such as 42.12345678.',
        'captured_at' => 'Recorded',
        'saved' => 'Currency saved.',
        'deleted' => 'Currency removed.',
    ],

    'status' => [
        'active' => 'Active',
        'hidden' => 'Hidden',
        'retired' => 'Retired',
        'hidden_hint' => 'Off the menu, still reachable by direct link.',
        'retired_hint' => 'Cannot be ordered.',
    ],

    'cycles' => [
        'one_time' => 'One time',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annually' => 'Semi-annually',
        'annually' => 'Annually',
        'biennially' => 'Every two years',
        'triennially' => 'Every three years',
    ],

    'cycle_short' => [
        'one_time' => 'once',
        'monthly' => '/mo',
        'quarterly' => '/qtr',
        'semi_annually' => '/6 mo',
        'annually' => '/yr',
        'biennially' => '/2 yr',
        'triennially' => '/3 yr',
    ],

    'types' => [
        'shared_hosting' => 'Shared hosting',
        'reseller' => 'Reseller hosting',
        'vps' => 'VPS',
        'dedicated' => 'Dedicated server',
        'ssl' => 'SSL certificate',
        'email' => 'Email',
        'license' => 'License',
        'service' => 'Service',
        'other' => 'Other',
    ],

    'option_types' => [
        'select' => 'Dropdown',
        'radio' => 'Radio buttons',
        'checkbox' => 'Checkbox',
        'quantity' => 'Quantity',
    ],

    'storefront' => [
        'title' => 'Hosting plans',
        'subtitle' => 'Pick a plan. Change it whenever you like.',
        'starting_at' => 'From',
        'order_now' => 'Order now',
        'configure' => 'Configure',
        'features' => 'What you get',
        'empty' => 'Nothing is for sale here yet.',
        'setup_fee' => ':amount setup',
        'no_setup_fee' => 'No setup fee',
        'ordering_soon' => 'Ordering opens soon',
    ],

    'errors' => [
        'duplicate_price_cell' => 'The price matrix has two entries for :cycle in :currency.',
        'group_not_empty' => 'This group still holds :count product(s). Move or retire them first.',
        'unknown_group' => 'That product group does not exist.',
        'base_currency_locked' => ':code is the base currency and cannot be removed.',
        'currency_in_use' => ':code is still used by :count price(s). Deactivate it instead.',
    ],
];
