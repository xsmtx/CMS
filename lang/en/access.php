<?php

declare(strict_types=1);

return [
    'roles_saved' => 'Role saved.',
    'roles_deleted' => 'Role deleted.',

    'scopes' => [
        'staff' => 'Staff',
        'customer' => 'Customer',
    ],

    'roles' => [
        'super-admin' => 'Super Administrator',
        'administrator' => 'Administrator',
        'support' => 'Support Agent',
        'account-owner' => 'Account Owner',
        'portal-member' => 'Portal Member',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission names
    |--------------------------------------------------------------------------
    |
    | What a permission is called on the roles screen, and one line saying
    | what it lets somebody do. The slug is what the code checks; it is not
    | what somebody deciding whether an agent may terminate a service should
    | have to read.
    |
    */

    'permissions' => [
        'platform.health.view' => [
            'label' => 'See platform health',
            'description' => 'Open the health page and read every check.',
        ],
        'platform.queue.view' => [
            'label' => 'See the queue',
            'description' => 'Watch queued and failed jobs.',
        ],
        'platform.audit.view' => [
            'label' => 'Read the audit trail',
            'description' => 'See who did what, and when.',
        ],
        'automation.view' => [
            'label' => 'See automation runs',
            'description' => 'Read what the scheduled tasks did.',
        ],
        'automation.run' => [
            'label' => 'Run an automation task now',
            'description' => 'Start a sweep by hand instead of waiting for the schedule.',
        ],
        'automation.dunning.manage' => [
            'label' => 'Edit the dunning sequence',
            'description' => 'Change the reminder and suspension steps for unpaid invoices.',
        ],
        'operations.view' => [
            'label' => 'See operations',
            'description' => 'Watch long-running jobs and what happened to them.',
        ],
        'operations.manage' => [
            'label' => 'Retry or resolve an operation',
            'description' => 'Run a failed operation again, or mark it settled by hand.',
        ],
        'platform.maintenance.manage' => [
            'label' => 'Turn maintenance mode on and off',
            'description' => 'Close the storefront and client area. The admin area stays open.',
        ],
        'platform.modules.view' => [
            'label' => 'See installed modules',
            'description' => 'Read what is installed and what each one registered.',
        ],
        'platform.modules.manage' => [
            'label' => 'Install and enable modules',
            'description' => 'Enabling a module runs code this platform did not ship.',
        ],
        'access.roles.view' => [
            'label' => 'See roles',
            'description' => 'Read the roles and what each one grants.',
        ],
        'access.roles.manage' => [
            'label' => 'Create and edit roles',
            'description' => 'Decide what everybody else is allowed to do.',
        ],
        'access.permissions.view' => [
            'label' => 'See the permission list',
            'description' => 'Read every permission this installation declares.',
        ],
        'identity.staff.view' => [
            'label' => 'See staff accounts',
            'description' => 'Read the list of people who work here.',
        ],
        'identity.staff.manage' => [
            'label' => 'Create and edit staff accounts',
            'description' => 'Invite colleagues, change their roles, deactivate them.',
        ],
        'identity.contacts.view' => [
            'label' => 'See client users',
            'description' => 'Read the people on a customer\'s account.',
        ],
        'identity.contacts.manage' => [
            'label' => 'Create and edit client users',
            'description' => 'Add somebody to a customer, change what they can reach.',
        ],
        'identity.contacts.impersonate' => [
            'label' => 'Sign in as a client user',
            'description' => 'See the portal as they see it. Every session is recorded.',
        ],
        'identity.sessions.manage' => [
            'label' => 'End somebody\'s sessions',
            'description' => 'Sign a person out of every device.',
        ],
        'identity.login_history.view' => [
            'label' => 'Read sign-in history',
            'description' => 'See when and from where somebody signed in.',
        ],
        'crm.customers.view' => [
            'label' => 'See clients',
            'description' => 'Open a client record and read it.',
        ],
        'crm.customers.manage' => [
            'label' => 'Create and edit clients',
            'description' => 'Add a client, change their details, close an account.',
        ],
        'crm.customers.export' => [
            'label' => 'Export a client\'s data',
            'description' => 'Download everything held about one client.',
        ],
        'crm.customers.anonymize' => [
            'label' => 'Anonymise a client',
            'description' => 'Remove personal data and keep the financial history. This cannot be undone.',
        ],
        'crm.notes.view' => [
            'label' => 'Read client notes',
            'description' => 'See what colleagues wrote on an account.',
        ],
        'crm.notes.manage' => [
            'label' => 'Write client notes',
            'description' => 'Add and edit notes on an account.',
        ],
        'crm.tags.manage' => [
            'label' => 'Manage tags',
            'description' => 'Create the labels used to group clients and tickets.',
        ],
        'crm.custom_fields.manage' => [
            'label' => 'Manage custom fields',
            'description' => 'Decide what extra questions a client record asks.',
        ],
        'catalog.groups.view' => [
            'label' => 'See product groups',
            'description' => 'Read how the catalogue is organised.',
        ],
        'catalog.groups.manage' => [
            'label' => 'Edit product groups',
            'description' => 'Create and reorder the sections of the catalogue.',
        ],
        'catalog.products.view' => [
            'label' => 'See products',
            'description' => 'Read what is for sale and how it is configured.',
        ],
        'catalog.products.manage' => [
            'label' => 'Create and edit products',
            'description' => 'Add a plan, change its options, retire it.',
        ],
        'catalog.pricing.manage' => [
            'label' => 'Set prices',
            'description' => 'Change what anything costs, in any currency or cycle.',
        ],
        'catalog.currencies.manage' => [
            'label' => 'Manage currencies',
            'description' => 'Decide which currencies this installation sells in.',
        ],
        'orders.view' => [
            'label' => 'See orders',
            'description' => 'Open an order and read what was bought.',
        ],
        'orders.manage' => [
            'label' => 'Place and change orders',
            'description' => 'Take an order over the phone, or move one along.',
        ],
        'orders.review' => [
            'label' => 'Release or refuse a held order',
            'description' => 'Decide about an order the risk check stopped.',
        ],
        'promotions.view' => [
            'label' => 'See promotions',
            'description' => 'Read the discount codes and what they apply to.',
        ],
        'promotions.manage' => [
            'label' => 'Create and edit promotions',
            'description' => 'Add a discount code, change its rules, stop it.',
        ],
        'billing.invoices.view' => [
            'label' => 'See invoices and the ledger',
            'description' => 'Read invoices, payments and every movement of money.',
        ],
        'billing.invoices.manage' => [
            'label' => 'Raise and issue invoices',
            'description' => 'Create an invoice, issue it, cancel a draft.',
        ],
        'billing.payments.record' => [
            'label' => 'Record a payment',
            'description' => 'Write down money that arrived outside the platform.',
        ],
        'billing.refunds.manage' => [
            'label' => 'Refund a payment',
            'description' => 'Send money back to a customer.',
        ],
        'billing.credits.manage' => [
            'label' => 'Adjust account credit',
            'description' => 'Put credit on an account, or take it off.',
        ],
        'organizations.view' => [
            'label' => 'See organizations',
            'description' => 'Read the tree of resellers and customers.',
        ],
        'organizations.manage' => [
            'label' => 'Create and edit organizations',
            'description' => 'Add a reseller, move one, change what it may do.',
        ],
        'settings.view' => [
            'label' => 'See settings',
            'description' => 'Read how this installation is configured.',
        ],
        'settings.manage' => [
            'label' => 'Change settings',
            'description' => 'Edit branding, themes, tax, numbering and the rest.',
        ],
        'services.view' => [
            'label' => 'See services',
            'description' => 'Open a hosting service and read its details.',
        ],
        'services.manage' => [
            'label' => 'Edit services',
            'description' => 'Change a service\'s plan, price, cycle or renewal date.',
        ],
        'services.provision' => [
            'label' => 'Set up a service on a server',
            'description' => 'Create the account on the provider, or retry a failure.',
        ],
        'services.suspend' => [
            'label' => 'Suspend and unsuspend a service',
            'description' => 'Switch a customer\'s hosting off and back on.',
        ],
        'services.terminate' => [
            'label' => 'Terminate a service',
            'description' => 'Delete the account at the provider. This cannot be undone.',
        ],
        'infrastructure.view' => [
            'label' => 'See servers',
            'description' => 'Read the server list and what is on each one.',
        ],
        'infrastructure.manage' => [
            'label' => 'Add and edit servers',
            'description' => 'Register a server and hold its credentials.',
        ],
        'domains.view' => [
            'label' => 'See domains',
            'description' => 'Read the domain list and each name\'s status.',
        ],
        'domains.manage' => [
            'label' => 'Edit domains',
            'description' => 'Change nameservers, renewal, and the per-domain extras.',
        ],
        'domains.register' => [
            'label' => 'Register, transfer and renew domains',
            'description' => 'Spend money at a registrar on the customer\'s behalf.',
        ],
        'catalog.tlds.view' => [
            'label' => 'See domain pricing',
            'description' => 'Read which extensions are sold and at what price.',
        ],
        'catalog.tlds.manage' => [
            'label' => 'Edit domain pricing',
            'description' => 'Decide which extensions are sold, and for how much.',
        ],
        'support.tickets.view' => [
            'label' => 'See tickets',
            'description' => 'Open the queue and read a conversation.',
        ],
        'support.tickets.manage' => [
            'label' => 'Answer and route tickets',
            'description' => 'Reply, assign, change status, open one on a client\'s behalf.',
        ],
        'support.tickets.delete' => [
            'label' => 'Delete a ticket',
            'description' => 'Remove a conversation permanently.',
        ],
        'support.departments.manage' => [
            'label' => 'Manage support departments',
            'description' => 'Create a queue and state what it promises.',
        ],
        'content.announcements.manage' => [
            'label' => 'Publish announcements',
            'description' => 'Write what customers see on the storefront and portal.',
        ],
        'content.kb.manage' => [
            'label' => 'Edit the knowledgebase',
            'description' => 'Write and publish help articles.',
        ],
        'notifications.view' => [
            'label' => 'See what was sent',
            'description' => 'Read the delivery log for every message.',
        ],
        'notifications.manage' => [
            'label' => 'Edit message templates',
            'description' => 'Change the wording customers receive.',
        ],
        'portal.dashboard.view' => [
            'label' => 'Open the client area',
            'description' => 'See the portal home page.',
        ],
        'portal.profile.view' => [
            'label' => 'See their own account',
            'description' => 'Read the account details.',
        ],
        'portal.profile.manage' => [
            'label' => 'Edit their own account',
            'description' => 'Change the account\'s details and address.',
        ],
        'portal.contacts.manage' => [
            'label' => 'Manage the account\'s users',
            'description' => 'Invite colleagues and decide what they can reach.',
        ],
        'portal.security.manage' => [
            'label' => 'Manage their own security',
            'description' => 'Change the password, set up two-factor, end sessions.',
        ],
        'portal.billing.view' => [
            'label' => 'See their own invoices',
            'description' => 'Read invoices and what has been paid.',
        ],
        'portal.billing.pay' => [
            'label' => 'Pay an invoice',
            'description' => 'Take an invoice to checkout.',
        ],
        'portal.orders.view' => [
            'label' => 'See their own orders',
            'description' => 'Read what the account has ordered.',
        ],
        'portal.payment_methods.manage' => [
            'label' => 'Manage saved cards',
            'description' => 'Add and remove a stored payment method.',
        ],
        'portal.tokens.manage' => [
            'label' => 'Manage API tokens',
            'description' => 'Create and revoke tokens for the API.',
        ],
        'portal.services.view' => [
            'label' => 'See their own services',
            'description' => 'Read the account\'s hosting services.',
        ],
        'portal.domains.view' => [
            'label' => 'See their own domains',
            'description' => 'Read the account\'s domain names.',
        ],
        'portal.domains.manage' => [
            'label' => 'Manage their own domains',
            'description' => 'Change nameservers and renewal for the account\'s domains.',
        ],
        'portal.tickets.view' => [
            'label' => 'See their own tickets',
            'description' => 'Read the account\'s support conversations.',
        ],
        'portal.tickets.create' => [
            'label' => 'Open a ticket',
            'description' => 'Start a support conversation and reply to it.',
        ],
    ],

    'groups' => [
        'platform' => 'Platform',
        'access' => 'Access control',
        'billing' => 'Billing',
        'catalog' => 'Catalog',
        'crm' => 'Customers',
        'identity' => 'Identity',
        'ordering' => 'Orders',
        'organizations' => 'Organizations',
        'settings' => 'Settings',
        'services' => 'Services',
        'infrastructure' => 'Infrastructure',
        'domains' => 'Domains',
        'support' => 'Support',
        'content' => 'Content',
        'notifications' => 'Notifications',
        'automation' => 'Automation',
        'portal' => 'Client portal',
    ],
];
