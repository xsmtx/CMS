<?php

declare(strict_types=1);

return [
    'title' => 'Addressing',
    'intro' => 'The address space this installation has, and who is holding each address.',

    'families' => [
        'v4' => 'IPv4',
        'v6' => 'IPv6',
    ],

    'pool_purposes' => [
        'infrastructure' => 'Infrastructure',
        'customer' => 'Customer',
    ],

    'address_states' => [
        'available' => 'Free',
        'reserved' => 'Reserved',
        'assigned' => 'Assigned',
        'quarantined' => 'Cooling off',
    ],

    'pools' => [
        'title' => 'Pools',
        'intro' => 'A pool is how address space is grouped before any particular network is.',
        'empty' => 'No pools yet. A pool holds the networks of one family, for one purpose.',
        'name' => 'Name',
        'family' => 'Family',
        'purpose' => 'Used for',
        'add' => 'New pool',
        'created' => 'Pool added.',
    ],

    'prefixes' => [
        'title' => 'Networks',
        'empty' => 'No networks yet.',
        'empty_filtered' => 'No network matches this filter.',
        'cidr' => 'Network',
        'pool' => 'Pool',
        'vlan' => 'VLAN',
        'site' => 'Site',
        'gateway' => 'Gateway',
        'used' => 'In use',
        'capacity' => 'Capacity',
        'utilisation' => 'Full',
        'too_large' => 'Too large to count',
        'parent' => 'Inside',
        'children' => 'Contains',
        'add' => 'New network',
        'created' => 'Network added.',
        'deleted' => 'Network removed.',
        'delete' => 'Remove network…',
        'delete_title' => 'Remove this network?',
        'delete_body' => 'The network is removed from the pool. Any networks inside it stay, and move up to whatever this one was inside. A network that still holds addresses cannot be removed.',
        'delete_confirm' => 'Remove network',
        'note' => 'Note',
        'search' => 'Search networks',
    ],

    'addresses' => [
        'title' => 'Addresses',
        'intro' => 'Only addresses somebody has done something with are listed. The rest of the network is free.',
        'empty' => 'Nothing has been done with any address in this network yet.',
        'address' => 'Address',
        'state' => 'State',
        'holder' => 'Held by',
        'held_since' => 'Since',
        'reverse_dns' => 'Reverse DNS',
        'allocate' => 'Take the next free address',
        'allocated' => ':address is reserved.',
        'release' => 'Release…',
        'released' => 'Address released.',
        'release_title' => 'Release this address?',
        'release_body' => 'The address stops being held and the record of who held it is kept. It is put aside to cool off rather than handed straight to somebody else, because an address that has just been released carries whatever reputation the last holder earned.',
        'release_confirm' => 'Release address',
        'reuse_now' => 'Put it straight back in the pool',
        'free_left' => ':count free',
    ],

    /*
     * The guarded configuration workflow (§6).
     *
     * The wording carries the workflow's own rules, because a screen that
     * showed the buttons and not the reasons would be a screen an operator
     * clicks through. "A backup is taken first" and "refused if the device has
     * moved" are sentences the dialog says out loud.
     */
    'changes' => [
        'title' => 'Device changes',
        'intro' => 'Configuration changes waiting on somebody, and what happened to the rest.',
        'empty' => 'Nothing is waiting',
        'empty_detail' => 'No change has been asked for. One is written down before it is applied, and applied only after somebody else has agreed.',
        'no_devices' => 'No devices have been found yet',
        'no_devices_detail' => 'Topology discovery writes a device onto the graph when an adapter answers for one. Until then there is nothing a change could be asked about.',
        'request' => 'Request a change',
        'request_intro' => 'Write down what should change and why. Asking is not applying.',
        'show_all' => 'Show everything',
        'show_open' => 'Show what is open',
        'about' => 'The request',
        'reason' => 'Why',
        'reason_hint' => 'What this is for. It is read by whoever has to agree to it, and it stays on the record.',
        'ticket' => 'Ticket',
        'ticket_hint' => 'Optional. Whichever system the conversation is in.',
        'intended' => 'The configuration it should have',
        'intended_hint' => 'The whole configuration, not a fragment. The diff is computed against what the device has now, so a fragment would read as everything else being deleted.',
        'diff' => 'What changes',
        'diff_intro' => 'Against the device as it was when this was asked for. It is read again immediately before it is applied.',
        'no_diff' => 'Nothing differs from what the device already has.',
        'decided' => 'Decided',
        'note' => 'Note',
        'approval_required' => 'Needs somebody else to agree',
        'approval_not_required' => 'No approval needed on this installation',
        'backed_up' => 'Backed up :at',
        'approve' => 'Approve',
        'approve_body' => 'The change becomes ready to apply. It is not applied by agreeing to it.',
        'reject' => 'Reject',
        'reject_body' => 'The change is refused and nothing goes to the device. The requester can ask again.',
        'cancel' => 'Withdraw',
        'cancel_body' => 'The change is withdrawn. Nothing goes to the device and the record stays.',
        'apply' => 'Apply to the device',
        'apply_body' => 'The configuration is backed up first, and the change is refused if the device is no longer the one this diff was read against. If the device does not keep it, the backup goes back on.',
        'columns' => [
            'summary' => 'Change',
            'device' => 'Device',
            'state' => 'State',
            'requester' => 'Asked by',
            'requested' => 'Asked',
        ],
        'states' => [
            'requested' => 'Written down',
            'awaiting_approval' => 'Waiting for approval',
            'authorized' => 'Ready to apply',
            'applying' => 'Applying',
            'completed' => 'Applied',
            'failed' => 'Failed',
            'rolled_back' => 'Rolled back',
            'rejected' => 'Rejected',
            'cancelled' => 'Withdrawn',
        ],
        'flash' => [
            'requested' => 'The change is written down.',
            'decided' => 'The change is decided.',
            'applying' => 'The change is on its way to the device.',
        ],
    ],
];
