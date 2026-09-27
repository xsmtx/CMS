<?php

declare(strict_types=1);

return [
    /*
     * The abuse desk (§13).
     *
     * A case ends in a decision rather than in "resolved": an incident is the
     * platform's fault and ends when the platform is working again, and this
     * ends when somebody has decided something. "We acted", "nothing needed
     * doing" and "the complaint was wrong" are three different answers, and a
     * word that flattened them would tell the next reader nothing.
     */
    'abuse' => [
        'title' => 'Abuse',
        'intro' => 'What somebody outside is complaining about, who it was about at the time, and what was done.',

        'kinds' => [
            'phishing' => 'Phishing',
            'malware' => 'Malware',
            'spam' => 'Spam',
            'brute_force' => 'Brute force',
            'compromise' => 'Compromised account',
            'vulnerability' => 'Vulnerability',
            'blocklist' => 'Blocklist listing',
            'copyright' => 'Copyright',
            'other' => 'Something else',
        ],

        'states' => [
            'open' => 'Nobody has looked',
            'investigating' => 'Being looked at',
            'waiting_customer' => 'With the customer',
            'actioned' => 'Acted on',
            'no_action' => 'Nothing needed doing',
            'rejected' => 'Complaint was wrong',
        ],

        'actions' => [
            'suspend_service' => 'Suspend the service',
            'stop_outbound_mail' => 'Stop outbound mail',
            'force_password_reset' => 'Force a password reset',
            'contact_customer' => 'Contact the customer',
        ],

        'action_states' => [
            'pending' => 'On its way',
            'done' => 'Done',
            'manual' => 'Somebody has to do it',
            'failed' => 'Failed',
        ],

        'evidence_kinds' => [
            'url' => 'A link',
            'ip_address' => 'An address',
            'domain' => 'A domain',
            'mail_message_id' => 'A message id',
            'log_excerpt' => 'A log excerpt',
            'file_hash' => 'A file hash',
            'complaint_reference' => 'The complainant’s own reference',
            'note' => 'A note',
        ],

        'empty' => 'No open complaints',
        'empty_detail' => 'Nothing is waiting on the abuse desk. An empty list here is the good outcome.',
        'open' => 'Record a complaint',
        'open_intro' => 'What arrived, when it happened, and what it named. Attribution is worked out against when it happened, not against now.',
        'summary' => 'What is being complained about',
        'kind' => 'What kind',
        'severity' => 'How urgent',
        'source' => 'Who complained',
        'source_hint' => 'A spam trap, a brand team, a person. Left empty when the complaint did not say.',
        'external_reference' => 'Their reference',
        'subject_type' => 'What it named',
        'subject_value' => 'The address or domain',
        'subject_hint' => 'An address is matched on its bytes, so any spelling of it works.',
        'occurred_at' => 'When it happened',
        'occurred_at_hint' => 'Not when it arrived. A report about last Tuesday belongs to whoever held the address last Tuesday.',
        'subjects' => [
            'ip' => 'An address',
            'domain' => 'A domain',
            'none' => 'Neither',
        ],
        'opened' => 'The case is recorded.',
        'record' => 'Record it',

        'unattributed' => 'Nobody',
        'unattributed_detail' => 'This platform could not say whose this was — an address in a range nobody recorded, a domain that is not ours, or a complaint that is simply wrong. It is kept anyway: somebody still has to answer it.',
        'attributed_at' => 'Attributed as it stood on :at.',

        'timeline' => 'What has been done',
        'timeline_intro' => 'Append-only. What the desk believed when it replied is what a dispute is argued from.',
        'about' => 'The complaint',
        'note' => 'Write what happened',
        // The field, not the heading above it. A label that repeats its
        // section says nothing twice, and a textarea with no label at all is
        // an unlabelled edit box to a screen reader.
        'note_body' => 'What happened',
        'note_state' => 'Where it is now',
        'note_save' => 'Write it',
        'noted' => 'The note is written.',

        'act' => 'Act on it',
        'act_intro' => 'Every one of these is a person pressing a button. Nothing here is automatic, because no abuse signal is right every time.',
        'act_action' => 'What to do',
        'act_service' => 'Which service',
        // Its own word. Borrowing the list's "Whose" column put the
        // operator's name under a heading that means the customer — the
        // third time a header has been lifted from somewhere it fitted.
        'act_decider' => 'Decided by',
        'act_no_service' => 'There is no service to suspend: this platform could not say whose the complaint was. Attribute it first, or choose another action.',
        'act_reason' => 'Why',
        'act_reason_hint' => 'Sent with the action and written onto the service’s own history, not kept here.',
        'acted' => 'The decision is recorded.',
        'manual_note' => 'This platform has no way to carry that out, so it is recorded as work for somebody.',

        'evidence' => 'Evidence',
        'evidence_intro' => 'References, never the thing itself. Each one is deleted once its retention deadline passes — a complaint holds somebody else’s data.',
        'evidence_kind' => 'What kind',
        'evidence_reference' => 'The reference',
        'evidence_keep' => 'Keep it',
        'evidence_kept' => 'The reference is kept.',
        'evidence_until' => 'Until :date',
        'evidence_none' => 'Nothing kept.',

        'columns' => [
            'case' => 'Complaint',
            'kind' => 'Kind',
            'state' => 'State',
            'customer' => 'Whose',
            'occurred' => 'Happened',
            'actions' => 'Actions',
        ],

        'show_all' => 'Show everything',
        'show_open' => 'Show what is open',
    ],
];
