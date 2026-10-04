<?php

declare(strict_types=1);

/*
 * Who this business buys from, and what it agreed (§24).
 *
 * Operator vocabulary throughout. None of this is ever shown to a customer:
 * what a seller pays for transit is not a customer's business, and the
 * boundary is what keeps it that way on a reseller installation.
 */

return [
    'title' => 'Vendors',
    'intro' => 'Who you buy from and what you agreed. Nothing here is discovered — no API says what a transit contract costs or when the licences renew.',

    'empty' => 'No vendors yet',
    'empty_detail' => 'Add the companies you buy from: the datacenter, transit, hardware, the licences. Contracts hang off them.',

    'add' => 'Add a vendor',
    // The submit is not the heading above it. "Add a vendor" on a form headed
    // Add a vendor says nothing twice; a button says what pressing it does.
    'add_submit' => 'Add it',
    'delete' => 'Delete',
    'add_contract' => 'Add a contract',
    'saved' => 'Saved.',
    'deleted' => 'Deleted.',

    'name' => 'Name',
    'kind' => 'What they supply',
    'contact_name' => 'Who to ask for',
    'contact_email' => 'Email',
    // Said plainly, because the moment it is wanted is a Sunday.
    'contact_phone' => 'Telephone',
    'account_reference' => 'Our account number with them',
    'account_reference_hint' => 'The first thing any support conversation asks for.',
    'note' => 'Note',

    'kinds' => [
        'datacenter' => 'Datacenter',
        'transit' => 'Transit',
        'hardware' => 'Hardware',
        'software' => 'Software',
        'registrar' => 'Registrar',
        'backup' => 'Backup',
        'cloud' => 'Cloud',
        'ddos' => 'DDoS protection',
        'other' => 'Something else',
    ],

    'terms' => [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
        'triennial' => 'Every three years',
        // Real and necessary: a one-off hardware purchase has a vendor, a
        // price and a warranty, and no renewal date at all.
        'once' => 'One-off',
    ],

    'contracts' => [
        'title' => 'Contracts',
        'intro' => 'What was agreed, what it costs and when somebody has to decide again.',
        'empty' => 'No contracts recorded',
        'empty_detail' => 'A contract is what was agreed. What a month was actually charged is a cost entry, which is a different row on a different screen.',

        // Singular, and its own word: the section heading above the table is
        // "Vendors", and a heading borrowed into a column says "Vendors"
        // above one supplier's name.
        'vendor' => 'Vendor',
        'add_submit' => 'Add it',
        'contract_title' => 'What it covers',
        'reference' => 'Their reference',
        'term' => 'Term',
        'amount' => 'Price per term',
        'currency' => 'Currency',
        'starts_on' => 'Starts',
        'ends_on' => 'Ends',
        'ends_on_hint' => 'Leave empty for a rolling agreement with no end date. One with no end never appears on the expiry list.',
        'auto_renews' => 'Renews itself',
        'auto_renews_hint' => 'If nobody gives notice, it continues. This changes what the warning means rather than whether you get one.',
        'notice_days' => 'Notice they want (days)',
        'notice_days_hint' => 'On a contract that renews itself, the date that matters is the last day to say no — not the end.',

        'decide_by' => 'Decide by',
        'rolling' => 'No end date',
        'renews' => 'Renews itself',
        'ends' => 'Ends',
        'days_left' => ':count days',
        'overdue' => 'Passed',
    ],

    /*
     * Operational licences bought in quantity, and which machine is using
     * one. The screen opens on the **difference**, because a list of what was
     * bought is a receipt and the difference is the answer no vendor portal
     * can give.
     */
    'licences' => [
        'title' => 'Licences',
        'intro' => 'Seats bought in quantity, and which of your machines are using them. Your vendor knows the first half; only this installation knows the second.',

        'empty' => 'No licences recorded',
        'empty_detail' => 'Record what you buy in quantity - cPanel, CloudLinux, LiteSpeed, Imunify, Windows - and which machine holds each seat. The gap between the two is what this screen is for.',

        'add' => 'Add a licence',
        'add_submit' => 'Add it',
        'allocate' => 'Put one on a machine',
        'allocate_submit' => 'Allocate it',
        'release' => 'Take it back',

        'name' => 'What it is',
        'vendor' => 'Bought from',
        'contract' => 'Under which contract',
        'contract_hint' => 'Optional. Licences bought on a card with no paper behind them are ordinary.',
        'for_module' => 'Which machines need it',
        'for_module_hint' => 'The provisioning module a machine running this would be configured with. Leave it unsaid and this platform makes no claim about which machines need one - and offers no list of the ones that are missing it.',
        'for_module_any' => 'Do not say',
        // Three different absences, three different sentences. One word
        // reused for all of them is how a select ends up saying "Do not say"
        // where it means "not under a contract".
        'contract_any' => 'Not under one',
        'for_module_none' => 'Not said',
        'seats' => 'Seats bought',
        'unit_price' => 'Price per seat',
        'currency' => 'Currency',
        'server' => 'Machine',
        // Its own word. The section heading above the table is "On a
        // machine that has gone", and borrowing it would put that sentence
        // over the cell that says which of the two happened.
        'reason' => 'Why',
        'reference' => 'Their reference',
        'reference_hint' => 'The supplier line for this seat. Never a licence key: nothing here would read one, and a key is a credential.',

        'used' => 'In use',
        'spare' => 'Idle',
        'overage' => 'Over',
        'total' => 'Per period',

        'sections' => [
            // Each heading says what is wrong, not what the list contains: a
            // section called "Allocations" is a section nobody opens.
            'spare' => 'Paid for and idle',
            'spare_detail' => 'Seats nothing is using. Either somebody can give them back, or a machine is running without one.',
            'orphaned' => 'On a machine that has gone',
            'orphaned_detail' => 'The same money with a worse story: somebody believed these were in use.',
            'missing' => 'Running without one',
            'missing_detail' => 'Machines configured for a module whose licence pool holds no seat for them. This is the one that costs an outage rather than money.',
            'pools' => 'Everything bought',
        ],

        'reasons' => [
            'gone' => 'No longer in the fleet',
            'offline' => 'Switched off',
        ],

        'nothing_spare' => 'Every seat is on a machine.',
        'nothing_orphaned' => 'Every seat is on a machine that is still here.',
        'nothing_missing' => 'Nothing is running unlicensed, as far as this platform can tell.',

        'confirm' => [
            'release_title' => 'Take this seat back?',
            'release_body' => 'The seat on :name becomes idle. Nothing happens on the machine itself - this is a record of what you bought, not a licence server.',
            'delete_title' => 'Delete this licence?',
            'delete_body' => ':name goes, with its seat count and its price. A licence with seats allocated cannot be removed until those are taken back.',
        ],
    ],

    'confirm' => [
        'vendor_title' => 'Delete this vendor?',
        // Says what actually goes, which is the rule every confirmation here
        // follows: the contracts stay and have to be deleted first.
        'vendor_body' => ':name goes from the list. Their contracts are not deleted — a vendor with contracts cannot be removed until those are.',
        'contract_title' => 'Delete this contract?',
        'contract_body' => ':name goes, with its renewal date and its notice period. Any cost entries pointing at it keep their own figures.',
    ],

    'errors' => [
        'not_permitted' => 'You do not have access to vendors and contracts.',
        'ends_before_start' => 'A contract has to end after it starts.',
        'has_contracts' => 'This vendor still has contracts. Delete those first, or keep the vendor.',
        'has_allocations' => 'This licence still has seats on machines. Take those back first.',
        'already_allocated' => 'That machine already holds a seat of this licence.',
    ],
];
