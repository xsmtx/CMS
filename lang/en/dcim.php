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

        'errors' => [
            // Each one names what is in the way and where, because a rack
            // diagram that does not match the building sends somebody to
            // the wrong cabinet.
            'does_not_fit' => 'A :heightU device at unit :start would end above the top of :rack, which is :unitsU.',
            'overlaps' => ':device is already in units :from to :to.',
            'overlaps_unnamed' => 'Units :from to :to are already taken.',
            'occupied' => 'Unit :start of :rack was taken while this was being saved.',
            'bad_height' => 'A device occupies at least one unit.',
            'nothing_to_place' => 'Choose a server, or give this position a name.',
        ],
    ],

    'remote_hands' => [
        'title' => 'Remote hands',
        'intro' => 'Asking somebody at the datacenter to go and touch a machine. A record before it is a request — an audit that says a machine was opened is worth more than a ticket saying somebody was asked to open it.',
        'empty' => 'Nothing waiting',
        'empty_detail' => 'No task is open. Raise one when something needs a pair of hands in the building.',
        'requested' => 'Raised.',
        'moved' => 'Recorded.',
        'add' => 'Ask for something',
        'show_all' => 'Show everything',
        'show_open' => 'Show what is open',
        'waiting_since' => 'Waiting since :date',
        'scheduled_for' => 'For :date',
        'serials' => ':old came out, :new went in',
        'no_serials' => 'No serials recorded',
        'unplaced' => 'No rack or machine named',

        'fields' => [
            'summary' => 'What needs doing',
            'instructions' => 'Instructions',
            'instructions_hint' => 'In the words somebody standing in the aisle needs. Bay numbers, light colours, which way round.',
            'rack' => 'Rack',
            'server' => 'Machine',
            'part' => 'Part',
            'technician' => 'Technician',
            'technician_hint' => 'A name. They work for the datacenter and have no account here.',
            'scheduled_for' => 'Agreed for',
            'old_serial' => 'Serial that came out',
            'new_serial' => 'Serial that went in',
            'outcome' => 'What happened',
            'evidence' => 'Evidence',
            'evidence_hint' => 'A link to a ticket or a photograph somewhere else. Nothing is uploaded here.',
            'save' => 'Raise it',
            'none' => 'None',
        ],

        'states' => [
            'requested' => 'Waiting',
            // Its own state: a task agreed for Tuesday at two is not the same
            // as one nobody has looked at.
            'scheduled' => 'Agreed',
            'in_progress' => 'Somebody is there',
            'done' => 'Done',
            'cancelled' => 'Called off',
        ],

        'moves' => [
            'requested' => 'Put back to waiting',
            'scheduled' => 'Agree a window',
            'in_progress' => 'Somebody is there now',
            'done' => 'Close it',
            'cancelled' => 'Call it off',
        ],

        'errors' => [
            'cannot_move' => 'A task that is “:from” cannot become “:to”.',
            // The reason the whole record exists.
            'needs_outcome' => 'Say what happened before closing this.',
        ],

        'columns' => [
            'task' => 'Task',
            'where' => 'Where',
            'state' => 'State',
            'waiting' => 'Raised',
        ],
    ],

    'power' => [
        'feeds' => [
            'a' => 'Feed A',
            'b' => 'Feed B',
            'unknown' => 'Feed not stated',
        ],

        'sensors' => [
            'temperature' => 'Temperature',
            'humidity' => 'Humidity',
            'airflow' => 'Airflow',
            'leak' => 'Water',
            'smoke' => 'Smoke',
            'door' => 'Door',
        ],
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
