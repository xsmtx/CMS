<?php

declare(strict_types=1);

/*
 * Intelligence (§21, §22).
 *
 * The vocabulary of the five classes is the part worth reading twice.
 * "Nobody could ask" is not a fault and must not read like one: a provider
 * that did not answer has told us nothing about the account, and an operator
 * who reads it as a problem with the customer will go and look in the wrong
 * place.
 */
return [
    'reconciliation' => [
        'title' => 'Reconciliation',
        'intro' => 'What this platform believes, against what each provider reports. Nothing here is put right on its own — a sweep that fixed what it found would suspend a customer because a panel was slow to answer.',
        'empty' => 'Everything agrees',
        'empty_detail' => 'Every service this platform can ask about is in the state the provider says it is in.',
        'empty_all' => 'Nothing has been compared yet',
        'empty_all_detail' => 'The sweep runs hourly. Run it now from Utilities → Automation if you would rather not wait.',

        'show_all' => 'Show closed ones too',
        'show_open' => 'Show what is still open',
        'dismiss' => 'It is deliberate',
        'dismissed' => 'Recorded. It will not be raised again while that holds.',
        'undismiss' => 'Raise it again',
        'undismissed' => 'It will be raised again on the next sweep.',

        'columns' => [
            'subject' => 'What',
            'class' => 'Conclusion',
            'expected' => 'We say',
            'found' => 'They say',
            'since' => 'Since',
            'answer' => 'Answer',
        ],

        'nothing_said' => 'The provider did not say',
        // A dash there would read as missing data. Nothing here owning
        // it is the whole of what an orphan is.
        'nobody_here' => 'No record here',
        'matched_on' => 'Looked for :field',
        'on_server' => 'on :server',
        'through' => 'through :module',
        // A sentence rather than a dash: a difference nobody could ask about
        // is a finding about the connection, not about the customer.
        'unknown_detail' => 'This says nothing about the account itself.',

        'dismiss_title' => 'Stop raising this?',
        'dismiss_body' => 'The comparison still runs and still clears itself when it is put right. What changes is that nobody is shown it.',
        'reason' => 'Why this one is deliberate',
        'reason_hint' => 'Read by whoever inherits this queue. “Built by hand for the migration, remove in March.”',
        'until' => 'Until',
        'until_hint' => 'Leave empty to keep it dismissed until somebody undoes it.',
        'dismissed_until' => 'Dismissed until :date',
        'dismissed_indefinitely' => 'Dismissed',
        'chosen' => 'Recorded. Nothing has been done yet.',
        'decided' => 'Recorded.',
        'applied' => 'Done.',
        'apply_failed' => 'It did not work. The row says what the provider said.',
        'suggestion' => 'Suggested',
        'choose' => 'Something else',
        'approve' => 'Agree',
        'reject' => 'Turn down',
        'apply' => 'Do it',
        'decide_reason' => 'Why (optional)',
        'chosen_by_operator' => 'Chosen by an operator',
        'confirm_remote_title' => 'This changes the customer’s account',
        'confirm_local_title' => 'This changes our record',
        'type_to_confirm' => 'Type the name of the service to confirm.',
    ],

    'leakage' => [
        'title' => 'Revenue leakage',
        'intro' => 'Money that quietly stopped arriving. Every line here is arithmetic over rows this platform already owns — no provider is asked and nothing is assumed.',
        'empty' => 'Nothing is leaking',
        'empty_detail' => 'Every active service, domain and addon has been invoiced, and every payment is attached to something.',
        'at_stake' => 'At stake',
        // An adjective, because `useTranslations()` has no
        // `trans_choice`: a choice string in the browser renders its
        // own pipe, and “1 findings” is the other half of that trap.
        'count' => ':count open',
        'show_all' => 'Show closed ones too',
        'show_open' => 'Show what is still open',
        // Not "owed": nobody has been invoiced, so nothing is owed. The
        // figure is what would have been invoiced had anybody asked.
        'total_hint' => 'What would have been invoiced, not what is owed.',
        'dismiss' => 'It is deliberate',
        'undismiss' => 'Raise it again',

        'columns' => [
            'subject' => 'What',
            'kind' => 'Why',
            'customer' => 'Whose',
            'amount' => 'At stake',
            'since' => 'Since',
        ],

        'kinds' => [
            'service_not_billed' => 'Not invoiced',
            'domain_not_renewed' => 'No renewal invoice',
            'addon_not_billed' => 'Missing from the invoice',
            'unmatched_payment' => 'Paid, attached to nothing',
        ],

        'descriptions' => [
            'service_not_billed' => 'Active and priced, and no invoice has mentioned it since it fell due.',
            'domain_not_renewed' => 'Past its renewal date, set to auto-renew, and no renewal invoice was raised.',
            'addon_not_billed' => 'Its service was invoiced and this was left off that invoice.',
            'unmatched_payment' => 'Money received and attached to no invoice — a customer who has paid and may still be chased for it.',
        ],

        'no_customer' => 'Nobody here',
    ],

    'actions' => [
        'accept_suspension' => 'Record it as suspended',
        'accept_activation' => 'Record it as active',
        'accept_termination' => 'Record it as terminated',
        'restore_service' => 'Ask the provider to restore it',
        'suspend_service' => 'Ask the provider to suspend it',
        'remove_service' => 'Ask the provider to destroy it',
        'investigate' => 'Somebody should look',
    ],

    'action_descriptions' => [
        'accept_suspension' => 'Changes our record only. The account stays exactly as the provider has it.',
        'accept_activation' => 'Changes our record only. The account stays exactly as the provider has it.',
        'accept_termination' => 'Changes our record only. Nothing is billed for it after this.',
        'restore_service' => 'Goes out to the provider and asks for the account to be unsuspended.',
        'suspend_service' => 'Goes out to the provider and asks for the account to be suspended. The customer loses their site.',
        'remove_service' => 'Goes out to the provider and destroys the account and its data. There is no undo, and nothing here can put it back.',
        'investigate' => 'Records that somebody has read this and that no automatic answer fits.',
    ],

    'proposal_states' => [
        'proposed' => 'Suggested',
        'approved' => 'Agreed, not yet done',
        'applied' => 'Done',
        'failed' => 'Did not work',
        'rejected' => 'Turned down',
        // Not a conclusion about the account: the difference moved.
        'stale' => 'Out of date',
    ],

    'errors' => [
        'not_open' => 'A proposal that is “:state” cannot be decided again.',
        'not_approved' => 'Nothing is carried out until somebody approves it.',
        // The fingerprint check's refusal, for a comparison rather than a device.
        'stale' => 'The difference this was written about is no longer what it was. The next sweep will suggest something for what is true now.',
        'not_available' => 'A finding of this kind cannot be answered with “:action”.',
        'nothing_to_act_on' => 'There is no service behind this finding to act on.',
    ],

    'classes' => [
        'healthy' => 'Agrees',
        'drift' => 'Different',
        'orphan' => 'Nobody’s',
        'missing' => 'Not there',
        // Not a fault, and deliberately not worded as one.
        'unknown' => 'Nobody could ask',
    ],

    /*
     * How sure a match is. An identity the provider itself returned is a
     * stronger claim than a name somebody typed in two places, and an
     * operator deciding whether to destroy something deserves to know
     * which one was used.
     */
    'matched' => [
        'external_id' => 'the provider’s own id',
        'domain' => 'a matching domain name',
        'address' => 'the address itself',
    ],

    'resources' => [
        'virtual_machine' => 'Virtual machine',
        'site' => 'Site',
        'ip_address' => 'Address',
        'certificate' => 'Certificate',
        'service' => 'Service',
    ],
];
