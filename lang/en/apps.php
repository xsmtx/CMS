<?php

declare(strict_types=1);

return [
    'heading' => 'Apps & Integrations',
    'description' => 'Everything that connects this platform to something else. Open to the owner of this installation only.',

    'errors' => [
        'super_admin_only' => 'Apps and Integrations is open to super administrators only. Enabling a module runs code this platform did not ship, and adding a server hands out credentials to a machine.',
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
    ],
];
