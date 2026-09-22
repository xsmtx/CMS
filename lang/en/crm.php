<?php

declare(strict_types=1);

return [
    'save' => 'Save',

    'fields' => [
        'company_name' => 'Company',
        'legal_name' => 'Legal name',
        'tax_id' => 'Tax ID',
        'line_one' => 'Address',
        'line_two' => 'Address line 2',
        'city' => 'City',
        'region' => 'Region',
        'postal_code' => 'Postal code',
        'country' => 'Country',
    ],

    'customer_created' => 'Customer created.',
    'customer_updated' => 'Customer updated.',
    'customer_anonymized' => 'Personal data erased. The commercial record remains.',
    'contact_saved' => 'Contact saved.',
    'contact_deleted' => 'Contact deleted.',
    'profile_updated' => 'Your details have been updated.',
    'profile_not_editable' => 'Only the account owner can change the company details.',
    'contacts_not_manageable' => 'Only the account owner can manage who reaches this account.',
    'contact_not_removable' => 'This contact cannot be removed from here.',
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
