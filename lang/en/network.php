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
];
