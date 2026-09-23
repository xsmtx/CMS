<?php

declare(strict_types=1);

return [
    'title' => 'Settings',
    'description' => 'What this installation calls itself, and what it looks like.',

    'saved' => 'Branding saved.',
    'theme_saved' => 'The theme has been changed.',
    'legal' => 'Legal',

    'identity' => [
        'title' => 'Identity',
        'description' => 'The name customers see, and the one a court sees. They differ often enough that an invoice needs both.',
        'trading_name' => 'Trading name',
        'legal_name' => 'Legal name',
        'legal_name_hint' => 'Printed on invoices. Falls back to the trading name.',
        'tax_id' => 'Tax number',
        'address' => 'Address',
        'country' => 'Country',
        'portal_name' => 'Portal name',
        'portal_name_hint' => 'What the customer area calls itself, if not the trading name.',
    ],

    'contact' => [
        'title' => 'Contact',
        'description' => 'Shown in the storefront footer and on documents.',
        'support_email' => 'Support email',
        'support_phone' => 'Support phone',
        'website_url' => 'Website',
    ],

    'appearance' => [
        'title' => 'Appearance',
        'description' => 'Colours override the design tokens everything else is built on, so one change reaches every button, badge and link.',
        'logo_url' => 'Logo',
        'logo_dark_url' => 'Logo for dark backgrounds',
        'favicon_url' => 'Favicon',
        'url_hint' => 'An HTTPS address. A logo over plain HTTP is blocked on a checkout page.',
        'accent_color' => 'Accent colour',
        'accent_contrast' => 'Text on the accent',
        'colour_hint' => 'Hex, such as #2563eb.',
        'font_family' => 'Font stack',
        'font_hint' => 'A CSS font stack. Loading a webfont is a theme\'s job.',
    ],

    'documents' => [
        'title' => 'Email and invoices',
        'description' => 'The identity messages go out under. The credentials that send them stay in configuration.',
        'email_from_name' => 'From name',
        'email_from_address' => 'From address',
        'email_footer' => 'Email footer',
        'invoice_footer' => 'Invoice footer',
        'invoice_footer_hint' => 'Payment terms, a registration number, whatever your jurisdiction expects.',
    ],

    'legal_links' => [
        'title' => 'Legal links',
        'description' => 'Shown in the storefront footer. Every jurisdiction wants a different set.',
        'label' => 'Label',
        'url' => 'Address',
        'add' => 'Add a link',
        'remove' => 'Remove',
        'none' => 'No links yet.',
    ],

    'vendor' => [
        'title' => 'Platform mark',
        'description' => 'The line in the storefront footer that credits this platform.',
        'hide' => 'Hide :mark',
        'not_entitled' => 'Removing the platform mark is not included in this licence.',
    ],

    'themes' => [
        'title' => 'Themes',
        'description' => 'A theme is templates, assets and a manifest. Behaviour belongs in a module.',
        'current' => 'In use',
        'parent' => 'Extends :parent',
        'by' => 'by :author',
        'apply' => 'Use this theme',
        'refused' => 'This theme cannot be used:',
        'none' => 'Only the core theme is installed.',
        'inherits' => 'Falls back to the theme it extends for anything it does not override.',
    ],

    'inherited' => [
        'title' => 'What customers see',
        'description' => 'With anything you have not set filled in from the organization above you.',
        'from_parent' => 'Inherited',
    ],

    'surfaces' => [
        'storefront' => 'Storefront',
        'client' => 'Client area',
        'admin' => 'Admin panel',
    ],

    'features' => [
        'branding_remove_vendor_mark' => 'Remove the platform mark',
    ],

    'errors' => [
        'not_permitted' => 'You do not have permission to change settings.',
        'no_organization' => 'This account has no organization to brand.',
    ],
];
