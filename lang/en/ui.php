<?php

declare(strict_types=1);

/*
 * The words the design-system primitives and the enterprise reference screens
 * draw in the browser. Published whole through `FrontEndTranslations` —
 * nothing here is operator-private.
 */
return [
    'common' => [
        'loading' => 'Loading…',
        'correlation_id' => 'Correlation ID',
        'columns' => 'Columns',
        'choose_columns' => 'Choose columns',
        'show_columns' => 'Show',
        'more_filters' => 'More filters',
        'search' => 'Search',
        'clear' => 'Clear',
        'clear_filters' => 'Clear filters',
        'edit' => 'Edit',
        'never' => 'Never',
        'on' => 'On',
        'off' => 'Off',
        'system' => 'System',
        'unknown' => 'Unknown',
        'any' => 'Any',
    ],

    'confirm' => [
        'confirm' => 'Confirm',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'reason' => 'Reason',
        'reason_hint' => 'Written to the audit record. The person reading it later is usually you.',
        'type_phrase' => 'Type :phrase to confirm',
    ],

    'pagination' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'results' => ':count result(s)',
    ],

    'selection' => [
        'selected' => ':count :noun selected',
    ],

    'dashboard' => [
        'title' => 'Dashboard',
        'attention' => 'Attention required',
        'all_clear' => 'Nothing needs attention.',
        'all_clear_detail' => 'No overdue invoices, no broken promises, nothing stuck.',
        'money_in' => 'Money in',
        'money_in_total' => ':total over the last twelve months.',
        'money_in_chart' => 'Money in, by month',
        'infrastructure' => 'Infrastructure',
        'run_health_checks' => 'Run health checks',
        'servers' => 'Servers',
        'setting_up' => 'Setting up',
        'suspended' => 'Suspended',
        'no_servers' => 'None registered',
        'servers_available' => ':active of :total available',
        'activity' => 'Recent activity',
        'activity_description' => 'Every sensitive action writes a record as it happens, with the actor and the reason.',
        'activity_when' => 'When',
        'activity_event' => 'Event',
        'activity_target' => 'Target',
        'activity_actor' => 'Actor',
        'activity_empty' => 'Nothing has happened yet',
        'activity_empty_detail' => 'Suspensions, refunds, credential rotations and permission changes appear here with the actor and the reason.',
    ],

    'clients' => [
        'title' => 'Clients',
        'count_one' => ':count client',
        'count_other' => ':count clients',
        'closed_hidden' => 'closed accounts hidden',
        'add' => 'Add client',
        'filter_label' => 'Filter clients',
        'search_placeholder' => 'Name or company',
        'email_placeholder' => 'Email address',
        'client_id' => 'client ID',
        'empty_filtered' => 'No clients match these filters',
        'empty_filtered_detail' => 'Closed accounts are hidden unless you include them. Clear the filters to see everyone.',
        'empty' => 'No clients yet',
        'empty_detail' => 'A client appears here when somebody orders from the storefront, or when you add one.',
    ],

    'client' => [
        'tabs_label' => 'Client',
        'tab_overview' => 'Overview',
        'tab_contacts' => 'Contacts',
        'tab_notes' => 'Notes',
        'export' => 'Export data',
        'edit' => 'Edit client',
        'since' => 'Client since :date',
        'anonymized' => "This customer's personal data has been erased. The commercial record remains so that invoices and service history stay intact.",

        'profile' => 'Profile',
        'status' => 'Status',
        'company' => 'Company',
        'legal_name' => 'Legal name',
        'tax_number' => 'Tax number',
        'currency' => 'Currency',
        'client_since' => 'Client since',

        'addresses' => 'Addresses',
        'default_address' => 'default',
        'no_address' => 'No address on file',
        'no_address_detail' => 'Invoices snapshot the billing address at issue time, so one is needed before billing starts.',

        'primary_contact' => 'Primary contact',
        'all_contacts' => 'All :count contacts',
        'last_sign_in' => 'Last sign-in :time',
        'no_primary' => 'No primary contact',
        'no_contacts' => 'No contacts yet',
        'no_contacts_detail' => 'A customer needs at least one contact so invoices and support replies reach a person.',

        'latest_notes' => 'Latest notes',
        'all_notes' => 'All :count notes',
        'pinned' => 'Pinned',
        'no_notes' => 'No notes',
        'no_notes_detail' => "Notes are internal unless marked visible, so a staff aside never appears in the customer's portal.",
        'notes_description' => 'Internal unless marked visible to the customer.',
        'visible_to_customer' => 'Visible to customer',

        'contacts_description' => 'The people who can sign in to the portal or receive mail for this client.',
        'add_contact' => 'Add contact',
        'primary' => 'Primary',
        'col_contact' => 'Contact',
        'col_email' => 'Email',
        'col_phone' => 'Phone',
        'col_portal' => 'Portal',
        'col_two_factor' => 'Two-factor',
        'col_last_sign_in' => 'Last sign-in',
        'portal_allowed' => 'Allowed',
        'portal_none' => 'No access',
        'more_actions' => 'More actions for :name',
        'view_as' => 'View portal as :name…',
        'remove' => 'Remove contact…',

        'erase_title' => 'Erasing personal data cannot be undone',
        'erase_detail' => 'Contacts lose their names, addresses and portal access. Invoices, service history and the audit trail are kept, under an anonymised name.',
        'erase_button' => 'Erase personal data…',
        'erase_confirm_title' => "Erase this client's personal data?",
        'erase_confirm_detail' => 'Irreversible. Contacts lose their names, addresses and access; the audit trail and the commercial record are kept.',
        'erase_confirm' => 'Erase personal data',

        'view_as_title' => 'View the portal as :name?',
        'view_as_detail' => 'You will see exactly what this contact sees. Your name and the reason are recorded in the audit trail.',
        'view_as_confirm' => 'View portal',

        'remove_title' => 'Remove :name?',
        'remove_detail' => 'They lose portal access and stop receiving mail for this client. Their past tickets and messages are kept.',
        'remove_confirm' => 'Remove contact',
    ],
];
