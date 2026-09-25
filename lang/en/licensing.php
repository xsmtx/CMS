<?php

declare(strict_types=1);

return [
    'title' => 'Licence',
    'subtitle' => 'What this installation is licensed for, and when it last spoke to the vendor.',

    'activated' => 'Activated. This installation is licensed for the :edition edition.',
    'heartbeat_ok' => 'The licence server answered. Nothing needs doing.',
    'deactivated' => 'The licence has been released from this installation.',

    'audit' => [
        'activated' => 'Licence activated',
        'heartbeat' => 'Licence confirmed',
        'heartbeat_failed' => 'The vendor could not be reached',
        'token_refused' => 'Token refused',
        'deactivated' => 'Licence deactivated',
        'deactivate_unreachable' => 'Deactivated without reaching the vendor',
    ],
    'statuses' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'revoked' => 'Revoked',
        'expired' => 'Expired',
    ],

    'errors' => [
        'not_permitted' => 'Only the owner of this installation can see its licence.',
    ],
];
