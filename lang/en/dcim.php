<?php

declare(strict_types=1);

return [

    'title' => 'Datacenter',
    'intro' => 'Where everything physically is. Nothing here is discovered — a rack is not an API, so this is what somebody typed in.',

    'empty' => 'Nothing has been recorded yet',
    'empty_detail' => 'Add a datacenter, a room and a rack, and this becomes the answer to “where is there space” and “which cabinet do I send somebody to”.',

    'no_racks' => 'No racks in this room yet.',
    'free_units' => ':free of :units U free',
    'placed' => 'Recorded.',
    'removed' => 'Taken out of the rack.',
    'rack_created' => 'Rack added.',

    'rack' => [
        'elevation' => 'Elevation',
        // Read from the top down, which is how somebody standing in front of
        // the cabinet reads it — even though the units are numbered from the
        // bottom, which is how they are labelled on the rails.
        'elevation_hint' => 'Top to bottom, as you would see it. Units are numbered from the bottom, as they are on the rails.',
        'free' => 'Free',
        'continues' => 'continues',
        'add' => 'Put something in',
        'start_unit' => 'Lowest unit',
        'unit_height' => 'Units',
        'server' => 'Server',
        'no_server' => 'Not a server here',
        'label' => 'Or a name',
        'label_hint' => 'For a switch, a patch panel or anything else this platform does not sell.',
        'save' => 'Record it',
        'remove' => 'Take out',
        'remove_title' => 'Take :name out of :rack?',
        'remove_body' => 'This records that the unit is free. Nothing is powered off and nobody is sent anywhere — if the machine is still in the cabinet, this makes the diagram wrong.',
    ],

    'parts' => [
        'title' => 'Hardware',
        'intro' => 'Parts, and where each of them has been. A disk outlives the machine it was first fitted to, which is what a warranty claim turns on.',
        'empty' => 'No parts recorded',
        'empty_detail' => 'Record a serial and a warranty date, and this becomes the answer to “is this still covered” and “where has this disk been”.',
        'add' => 'Record a part',
        'created' => 'Recorded.',
        'fitted' => 'Fitted.',
        'removed' => 'Taken out.',
        'fit' => 'Fit it',
        'remove' => 'Take out',
        'remove_title' => 'Take :name out?',
        'remove_body' => 'This records that the part is no longer in that machine, and keeps the history. Nothing is unscrewed — if the part is still fitted, this makes the register wrong.',
        'on_the_shelf' => 'On the shelf',
        'in_warranty' => 'In warranty',
        'out_of_warranty' => 'Out of warranty',
        // Three answers, not two: nobody recorded one, which is a gap in the
        // register rather than an expiry — and drawing it as expired would
        // send somebody to argue with a vendor who is still obliged.
        'no_warranty' => 'No warranty recorded',
        'history' => 'Where it has been',
        'no_history' => 'This part has never been fitted to anything.',
        'fitted_on' => 'Fitted :date',
        'removed_on' => 'Taken out :date',
        'still_fitted' => 'Still fitted',
        'search' => 'Serial, asset tag or model',
        'any_kind' => 'Any kind',

        'columns' => [
            'part' => 'Part',
            'kind' => 'Kind',
            'where' => 'Where it is',
            'warranty' => 'Warranty',
        ],

        'fields' => [
            'kind' => 'Kind',
            'model' => 'Model',
            'serial' => 'Serial',
            'asset_tag' => 'Asset tag',
            'vendor' => 'Vendor',
            'purchased_on' => 'Bought',
            'warranty_until' => 'Warranty until',
            'server' => 'Machine',
            'choose_server' => 'Choose a machine',
            'note' => 'Note',
            'save' => 'Record it',
        ],

        'kinds' => [
            'disk' => 'Disk',
            'memory' => 'Memory',
            'cpu' => 'Processor',
            'power_supply' => 'Power supply',
            'network_card' => 'Network card',
            'optic' => 'Optic',
            'other' => 'Other',
        ],
    ],

    'racks' => [
        'add' => 'Add a rack',
        'name' => 'Name',
        'units' => 'Height in U',
        'used' => 'Occupied',
        'room' => 'Room',
        'save' => 'Add it',
    ],
];
