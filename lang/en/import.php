<?php

declare(strict_types=1);

return [
    'title' => 'Import',
    'subtitle' => 'Bring a previous system across. Nothing is written until you ask for a live run.',

    'started' => 'The import has been queued. This screen shows it as it goes.',

    'domains' => [
        'customers' => 'Clients',
        'contacts' => 'Contacts',
        'products' => 'Products',
        'services' => 'Products/Services',
        'domains' => 'Domains',
        'invoices' => 'Invoices',
        'transactions' => 'Transactions',
        'tickets' => 'Tickets',
    ],

    'modes' => [
        'dry_run' => 'Dry run',
        'live' => 'Live import',
    ],

    'statuses' => [
        'pending' => 'Queued',
        'running' => 'Running',
        'completed' => 'Finished',
        'failed' => 'Could not run',
    ],

    'outcomes' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'skipped' => 'Already imported',
        'failed' => 'Not imported',
    ],

    'errors' => [
        'not_permitted' => 'Only the owner of this installation can import from another system.',
    ],
];
