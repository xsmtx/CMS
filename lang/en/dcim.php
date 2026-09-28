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

    'racks' => [
        'add' => 'Add a rack',
        'name' => 'Name',
        'units' => 'Height in U',
        'used' => 'Occupied',
        'room' => 'Room',
        'save' => 'Add it',
    ],
];
