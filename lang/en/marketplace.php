<?php

declare(strict_types=1);

/*
 * The marketplace's own vocabulary.
 *
 * Operator words. Every refusal an operator can hit lives in
 * `PackageRefused` rather than here, because each one names which check said no
 * and that sentence belongs next to the check.
 */

return [
    'title' => 'Marketplace',
    'intro' => 'Packages this installation may add. Nothing here runs until you install it and then enable it.',

    'installed' => 'Fetched and installed. Nothing of it runs yet — enable it on the Modules screen.',

    'empty' => [
        'title' => 'Nothing on offer',
        'description' => 'The vendor has nothing for this installation, or could not be reached. Everything already installed keeps working either way.',
    ],

    'unconfigured' => [
        'title' => 'No marketplace configured',
        'description' => 'This installation has no marketplace address, so there is nothing to browse. A module can still be added by putting its directory in the modules folder.',
    ],

    'disabled' => [
        'title' => 'Modules are switched off here',
        'description' => 'This installation has decided no third-party code runs on it. Nothing can be fetched until that changes.',
    ],

    'unsigned' => [
        'title' => 'No packaging key',
        'description' => 'Without the vendor\'s packaging key, a download cannot be proven to be theirs — so none will be accepted. This is not a setting to turn off.',
    ],

    'columns' => [
        'package' => 'Package',
        'kind' => 'Kind',
        'version' => 'Version',
        'size' => 'Size',
        'state' => 'State',
    ],

    'states' => [
        'available' => 'Available',
        'installed' => 'Installed',
        'outdated' => 'Newer version',
        'foreign' => 'Installed by hand',
    ],

    'install' => 'Install',
    'installing' => 'Fetching',
    'read_more' => 'About this package',
    'needs' => 'Needs :packages',
    'by' => 'by :provider',

    // The sentence that makes the four steps legible on the screen an operator
    // is standing on rather than only in an ADR.
    'how_it_works' => 'Installing writes a row and unpacks the files. It runs nothing: enabling, on the Modules screen, is the moment a package executes.',

    'foreign_note' => 'This module was placed in the modules folder by hand, so the marketplace will not replace it.',
];
