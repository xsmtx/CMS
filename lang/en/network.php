<?php

declare(strict_types=1);

return [
    'title' => 'Addressing',
    'intro' => 'The address space this installation has, and who is holding each address.',

    /*
     * What a guarded change is about (§25). Two workflows on one table,
     * because who asked, why, which ticket, what changed and who agreed are
     * identical questions - and two queues would be two places to forget to
     * look.
     */
    'targets' => [
        'device' => 'A device',
        'workspace' => 'A workspace',
    ],

    'workspace' => [
        'title' => 'Workspace',
        'ref' => 'Which revision',
        'ref_hint' => 'A branch, a tag or a commit. Leave it empty to plan whatever the workspace already tracks — this platform never guesses a branch.',
        'plan' => 'What it would do',
        'plan_intro' => 'Produced by the tool against the state as it was when this was asked for. The state is read again immediately before it runs, and the plan that was approved is the one that runs.',
        'tracked' => 'Whatever the workspace tracks',
        'add' => 'Add',
        'change' => 'Change',
        'destroy' => 'Destroy',
        // Said on the record rather than left to be discovered: a device can
        // be put back and a workspace cannot.
        'no_rollback' => 'A workspace cannot be rolled back. Reverting one is another plan, with its own approval.',
    ],

    /*
     * Why a device change will not go ahead — read through
     * `ChangeRefused::worded()`. The exception's own message is English and
     * belongs in the log; this is what somebody reads, on a screen or on a
     * phone.
     */
    'errors' => [
        'workspace_not_readable' => 'No adapter on this installation may read :workspace, so there is no plan to show.',
        'workspace_locked' => ':workspace is locked by :holder, so nothing was applied.',
        'workspace_moved' => 'The state of :workspace is not the one this plan was built against. Ask for it again so the plan is against what the workspace holds now.',
        'no_state_serial' => 'The adapter for :workspace cannot say which version of the state it is looking at, so a plan cannot be applied safely.',
        'nothing_to_plan' => 'The plan for :workspace changes nothing, so there is nothing to approve.',
        'no_runner' => 'No adapter on this installation is permitted to run a plan for :workspace. An operator turns that on per adapter, deliberately.',
        'nothing_to_apply' => 'A change has to say what the configuration should become.',
        'device_not_readable' => 'No adapter on this installation may read :device, so there is nothing to compare a change against.',
        'device_moved' => 'The configuration on :device is not the one this change was reviewed against. Request it again so the diff is against what the device says now.',
        'not_applicable' => 'A change that is :state cannot be applied.',
        'not_decidable' => 'A change that is :state is already decided.',
        'not_withdrawable' => 'A change that is :state can no longer be withdrawn.',
        'own_approval' => 'A change has to be approved by somebody other than the person who asked for it.',
        'no_writer' => 'No adapter on this installation is permitted to change :device. An operator turns that on per adapter, deliberately.',
        'backup_failed' => 'The configuration of :device could not be backed up, so nothing was applied.',
    ],

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
        // Not "Device changes" any more: half the queue is workspaces,
        // and a page heading names what is on the page.
        'title' => 'Guarded changes',
        'intro' => 'Changes waiting on somebody, and what happened to the rest. Nothing here is applied without a reason and a second person.',
        'empty' => 'Nothing is waiting',
        'empty_detail' => 'No change has been asked for. One is written down before it is applied, and applied only after somebody else has agreed.',
        'no_devices' => 'Nothing has been found to change yet',
        'no_devices_detail' => 'Discovery writes a device or a workspace onto the graph when an adapter answers for one. Until then there is nothing a change could be asked about.',
        'request' => 'Request a change',
        // The submit, which is not its own heading and not the button
        // that opened the form.
        'request_submit' => 'Ask for it',
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
        'target' => 'What it is about',
        'apply_body' => 'The configuration is backed up first, and the change is refused if the device is no longer the one this diff was read against. If the device does not keep it, the backup goes back on.',
        // A different promise, because the steps are different. Saying
        // "backed up first" about a workspace would be a sentence this
        // platform could not keep.
        'apply_workspace_body' => 'The change is refused if the workspace is locked, or if its state is no longer the one this plan was built against. The plan that was approved is the one that runs.',
        'columns' => [
            'summary' => 'Change',
            'device' => 'Device',
            // Names what is in the cell, which is a device on half the
            // rows and a workspace on the other half.
            'subject' => 'What it is about',
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

    /*
     * Just-in-time access (§17).
     *
     * The wording carries the rule, because a screen that showed the form and
     * not the reason would be a screen somebody fills in out of habit: a grant
     * runs out on its own, and it is never given to the person giving it.
     */
    'access' => [
        'title' => 'Temporary access',
        'intro' => 'Capabilities somebody holds for a window rather than permanently. Each one ends on its own.',
        'empty' => 'Nobody currently holds temporary access.',
        'grant' => 'Grant access',
        'grant_intro' => 'Give somebody one more thing than they usually have, until a time. Never to yourself.',
        'holder' => 'For',
        'granter' => 'Given by',
        'capability' => 'What they may do',
        'minutes' => 'For how long, in minutes',
        'minutes_hint' => 'At least five, and at most what this installation allows. Longer than that is a permission with extra steps.',
        'reason' => 'Why',
        'ticket' => 'Ticket',
        'expires' => 'Ends',
        'revoke' => 'End it now',
        'revoke_title' => 'End this access now?',
        'revoke_body' => 'They lose the capability immediately. The record of who had it, and why, is kept.',
        'capabilities' => [
            'infrastructure_connect' => 'Open a panel session from Connect',
        ],
        'flash' => [
            'granted' => 'The access is granted, and it ends on its own.',
            'revoked' => 'The access has ended.',
        ],
    ],

    /*
     * Attacks (§7).
     *
     * The event and the attribution, never the flow series — a NetFlow
     * collector inside a billing database is a time-series store nobody sized.
     */
    'ddos' => [
        'title' => 'Attacks',
        'intro' => 'What something reported hitting an address here, and whose service was behind it.',
        'empty' => 'Nothing has been reported',
        'empty_detail' => 'Attacks arrive from an adapter that watches for them. Until one is enabled there is nothing to show, which is not the same as nothing having happened.',
        'running' => 'Still running',
        'unattributed' => 'Nobody was holding this address',
        'unattributed_detail' => 'An attack on an address this installation does not recognise, or one nobody held at the time. Worth looking at either way.',
        'impact' => 'What was behind the addresses',
        'impact_intro' => 'The services attacked in this period, and what they bill. From the services themselves, never from the graph.',
        'services' => 'Services hit',
        'customers' => 'Customers affected',
        'recurring' => 'Recurring value',
        'columns' => [
            'target' => 'Address',
            'customer' => 'Customer',
            'started' => 'Started',
            'duration' => 'Lasted',
            'peak' => 'Peak',
            'vectors' => 'Shape',
            'mitigation' => 'Mitigation',
        ],
        'filters' => [
            'all' => 'Show everything',
            'running' => 'Show what is running',
        ],
        'vectors' => [
            'udp_flood' => 'UDP flood',
            'icmp_flood' => 'ICMP flood',
            'dns_reflection' => 'DNS reflection',
            'ntp_reflection' => 'NTP reflection',
            'memcached_reflection' => 'Memcached reflection',
            'ssdp_reflection' => 'SSDP reflection',
            'syn_flood' => 'SYN flood',
            'ack_flood' => 'ACK flood',
            'http_flood' => 'HTTP flood',
            'tls_handshake' => 'TLS handshake',
            'other' => 'Something else',
        ],
    ],
];
