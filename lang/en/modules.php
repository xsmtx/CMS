<?php

declare(strict_types=1);

return [
    'installed' => 'Module installed. It is not running until you enable it.',
    'enabled' => 'Module enabled.',
    'disabled' => 'Module disabled.',
    'upgraded' => 'Module upgraded.',
    'uninstalled' => 'Module uninstalled. Its own tables were left in place.',
    'configured' => 'Module settings saved.',

    'errors' => [
        'not_permitted' => 'You do not have permission to manage modules.',
    ],

    'types' => [
        'payment_gateway' => [
            'label' => 'Payment gateway',
            'description' => 'Takes money, and can see what an invoice is for.',
        ],
        'provisioning' => [
            'label' => 'Provisioning',
            'description' => 'Creates and suspends accounts on your servers.',
        ],
        'registrar' => [
            'label' => 'Registrar',
            'description' => 'Registers and renews domain names.',
        ],
        'notification_channel' => [
            'label' => 'Notification channel',
            'description' => 'Delivers messages the platform decides to send.',
        ],
        'fraud' => [
            'label' => 'Fraud',
            'description' => 'Decides whether an order should be held for review.',
        ],
        'tax' => [
            'label' => 'Tax',
            'description' => 'Works out what tax to charge.',
        ],
        'report' => [
            'label' => 'Report',
            'description' => 'Adds a screen that reads what is already recorded.',
        ],
        'admin_widget' => [
            'label' => 'Admin widget',
            'description' => 'Adds a panel to the admin dashboard.',
        ],
        'client_widget' => [
            'label' => 'Client widget',
            'description' => 'Adds a panel to the customer dashboard.',
        ],
        'infrastructure' => [
            'label' => 'Infrastructure',
            'description' => 'Discovers servers, addresses and the things that hang off them.',
        ],
        'ai' => [
            'label' => 'AI provider',
            // The sentence an operator needs before installing one, said
            // plainly rather than softened.
            'description' => 'Drafts replies and summaries. What a ticket contains is sent to this vendor, and nothing is drafted until you turn a feature on.',
        ],
        'addon' => [
            'label' => 'Addon',
            'description' => 'Several of the above. Read what it registers.',
        ],
    ],

    'states' => [
        'installed' => 'Installed, not running',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'failed' => 'Stopped',
    ],

    'extension_points' => [
        'gateway' => 'Payment gateway',
        'provisioning_module' => 'Provisioning module',
        'registrar' => 'Registrar',
        'channel' => 'Notification channel',
        'risk_evaluator' => 'Fraud check',
        'tax_calculator' => 'Tax calculation',
        'health_check' => 'Health check',
        'permission' => 'Permission',
        'navigation' => 'Menu item',
        'widget' => 'Dashboard widget',
        'infrastructure_adapter' => 'Infrastructure adapter',
        // Named as what it is rather than as "AI": an operator reading a
        // module's row is being told a third party may receive their
        // customers' words.
        'ai_provider' => 'AI provider',
    ],
    'settings' => [
        'title' => 'Settings',
        'save' => 'Save settings',
        'secret_set' => 'Set. Leave blank to keep it.',
        'secret_unset' => 'Not set.',
    ],
];
