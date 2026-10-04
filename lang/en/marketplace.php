<?php

declare(strict_types=1);

/*
 * The marketplace's own vocabulary.
 *
 * Operator words, including the refusals.
 *
 * They used to live only in `PackageRefused`, on the reasoning that the
 * sentence belongs next to the check that said no. Half of that is still
 * true: the class names which check refused, one constructor each, and that
 * distinction is what an audit log needs. What was wrong is that the same
 * English sentence was then shown to the operator — so a Turkish installation
 * refusing a package answered in English, on a screen that was otherwise
 * entirely Turkish. The class keeps the log sentence; this is what somebody
 * reads.
 */

return [
    /*
     * Why a package was not fetched. Every one is named separately in code,
     * because "the download failed" would make a broken mirror and an attack
     * look identical in an audit log (ADR 0047).
     */
    'errors' => [
        'not_offered' => 'The marketplace does not offer a package called :slug.',
        'disabled' => 'No marketplace is configured for this installation, so there is nothing to fetch from.',
        'unreachable' => 'The vendor did not answer for :slug.',
        'too_large' => 'The package :slug is larger than this installation will download (:limitBytes bytes).',
        'digest' => 'The package :slug does not match the digest the catalogue published for it.',
        'signature' => 'The package :slug is not signed by this vendor.',
        'unsigned' => 'This distribution has no packaging public key, so no package can be verified.',
        'unreadable_archive' => 'The package :slug is not an archive this platform can open.',
        // The one an attacker would try: `../../../.env` is a valid entry
        // name, and an archive chooses its own paths.
        'unsafe_path' => 'The package :slug contains an entry that would be written outside it (:entry).',
        'slug_mismatch' => 'The package offered as :offered calls itself :declared.',
        'not_ours' => 'The module :slug was installed from disk, so the marketplace will not replace it.',
    ],

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
