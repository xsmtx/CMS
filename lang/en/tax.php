<?php

declare(strict_types=1);

/*
 * The vocabulary of a screen whose subject core deliberately does not
 * understand (ADR 0045). Every word here is about *stating* a rule, never about
 * any country's law — there is no list of jurisdictions in this file and there
 * must not be one.
 */

return [
    'title' => 'Tax',
    'intro' => 'What to charge, where. This platform ships no rates: these are yours to state, and your accountant\'s to check.',

    'rules' => [
        'title' => 'Tax rules',
        'intro' => 'One row per thing you charge. The most specific rule wins — a region beats its country, and a country beats a rule with no place on it.',
        'empty' => 'No rules yet, so nothing is charged. Add the rate for the country you sell from first.',
        'saved' => 'Saved.',
        'deleted' => 'Removed.',
        'add' => 'Add a rule',
        'edit' => 'Edit rule',
        'anywhere' => 'Anywhere',
        'columns' => [
            'name' => 'Called',
            'rate' => 'Rate',
            'place' => 'Where',
            'level' => 'Level',
            'applies_to' => 'On',
            'customer' => 'For',
            'dates' => 'Dates',
            'state' => 'State',
        ],
        'fields' => [
            'name' => 'What the invoice calls it',
            'name_hint' => 'VAT, KDV, GST, PST, IVA — whatever your customers expect to read.',
            'rate' => 'Rate (%)',
            'rate_hint' => 'Up to four decimal places, so 9.975 is exact.',
            'country' => 'Country (ISO code)',
            'country_hint' => 'Two letters. Leave empty to charge it everywhere.',
            'region' => 'Region or state',
            'region_hint' => 'Leave empty for the whole country.',
            'postcode' => 'Postcode',
            'postcode_hint' => 'A whole postcode, or a prefix with * — 100* matches 10001.',
            'level' => 'Level',
            'level_hint' => 'A second tax on the same supply sits at level 2.',
            'compound' => 'Charge this on the level 1 tax as well as on the amount',
            'applies_to' => 'Applies to',
            'customer_kind' => 'Applies to customers',
            'exempts' => 'A business elsewhere with a tax id pays nothing',
            'exemption_note' => 'What the invoice says when that happens',
            'exemption_note_hint' => 'For example: Reverse charge, article 196.',
            'priority' => 'Priority',
            'priority_hint' => 'Higher wins when two equally specific rules could apply.',
            'starts_on' => 'From',
            'ends_on' => 'Until',
            'dates_hint' => 'A rate change is a new rule. Give the old one an end date so past invoices stay explicable.',
            'is_active' => 'Active',
            'notes' => 'Notes',
        ],
    ],

    'settings' => [
        'title' => 'How tax behaves',
        'intro' => 'The handful of answers that are not a rate.',
        'saved' => 'Saved.',
        'prices_include_tax' => 'Catalog prices already include tax',
        'prices_include_tax_hint' => 'Changes what a price means rather than what is added to it. Common for consumer prices, wrong for most business-to-business selling.',
        'rounding' => 'Round tax',
        'rounding_hint' => 'Per line, or once on the invoice total. The two differ by a cent or two, and which one is required is a real difference.',
        'tax_id_label' => 'What to call a tax id on forms',
        'tax_id_label_hint' => 'VAT number, Vergi No, ABN, GSTIN. "VAT number" is wrong in most of the world.',
        'require_tax_id_for_business' => 'Ask a business customer for a tax id',
        'exemption_note' => 'Default exemption note',
    ],

    'preview' => [
        'title' => 'Try it',
        'intro' => 'What your rules do to one amount, through the same calculator an invoice uses.',
        'amount' => 'Amount (minor units)',
        'amount_hint' => '1000 is 10.00.',
        'currency' => 'Currency',
        'country' => 'Country',
        'region' => 'Region',
        'postcode' => 'Postcode',
        'tax_id' => 'Tax id',
        'is_business' => 'A business',
        'applies_to' => 'Selling',
        'run' => 'Work it out',
        'net' => 'Before tax',
        'tax' => 'Tax',
        'gross' => 'Total',
        'nothing' => 'No rule applies, so nothing is charged.',
        'exempt' => 'Nothing charged: :reason',
    ],

    'applies_to' => [
        'all' => 'Everything',
        'products' => 'Products',
        'domains' => 'Domains',
        'addons' => 'Addons',
        'manual' => 'Manual invoice lines',
    ],

    'customer_kinds' => [
        'all' => 'Everyone',
        'individual' => 'Individuals',
        'business' => 'Businesses',
    ],

    'rounding' => [
        'per_line' => 'Per line',
        'per_invoice' => 'Once on the total',
    ],

    'errors' => [
        'not_permitted' => 'Only the owner of this installation can change tax.',
    ],
];
