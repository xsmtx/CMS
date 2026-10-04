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
    ],
];
