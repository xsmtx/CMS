<?php

declare(strict_types=1);

return [
    'customer_created' => 'Customer created.',
    'customer_updated' => 'Customer updated.',
    'customer_anonymized' => 'Personal data erased. The commercial record remains.',
    'contact_saved' => 'Contact saved.',
    'contact_deleted' => 'Contact deleted.',
    'invalid_transition' => 'A customer cannot move from :from to :to.',
    'contact_not_on_customer' => 'That contact does not belong to this customer.',

    'statuses' => [
        'pending' => 'Pending',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ],

    'address_types' => [
        'billing' => 'Billing',
        'technical' => 'Technical',
        'legal' => 'Legal',
    ],

    'custom_field_types' => [
        'text' => 'Text',
        'textarea' => 'Long text',
        'number' => 'Number',
        'boolean' => 'Yes or no',
        'date' => 'Date',
        'select' => 'Choice',
    ],

    'custom_field_entities' => [
        'customer' => 'Customer',
        'contact' => 'Contact',
    ],
];
