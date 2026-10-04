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

        /*
         * A token this installation will not accept. Each is a security
         * event rather than a configuration problem, which is why each has
         * its own sentence — "licence invalid" tells an incident review
         * nothing.
         */
        'bad_signature' => 'The licence token was not signed by this vendor.',
        'malformed' => 'The licence token could not be read: :why.',
        'another_installation' => 'The licence token was issued to a different installation.',
        'issued_in_future' => 'The licence token is dated in the future. Either the clocks disagree or somebody is constructing tokens.',
        'replayed' => 'The licence token is older than the one this installation already holds.',
        'no_public_key' => 'This distribution has no licence public key, so no token can be verified.',
    ],
];
