<?php

declare(strict_types=1);

/*
 * The assistant's vocabulary (ADR 0050).
 *
 * Operator words. Every sentence here is read by somebody deciding whether a
 * customer's words may be sent to a vendor, or by somebody looking at a button
 * that did not work — so none of them is cheerful about it.
 */

return [
    'title' => 'Assistant',
    'intro' => 'A model can draft a reply, summarise a thread or suggest where a ticket belongs. It never sends anything: every draft lands in the box you were going to type in, and you edit it and press send.',

    'off' => 'No assistant is set up',
    'off_detail' => 'Nothing is sent anywhere. Install an AI provider module, choose it here, and turn on the features you want.',

    'provider' => 'Provider',
    'provider_hint' => 'Which enabled module answers. Only modules this installation has enabled appear here.',
    'provider_none' => 'None — the assistant is off',

    'instructions' => 'How it should write',
    'instructions_hint' => 'Your own wording, attached to every draft. "Answer in Turkish, formally, and never promise a refund" is the shape of it.',

    'features' => [
        'ticket_reply' => [
            'label' => 'Draft a ticket reply',
            'description' => 'The subject, the department, the customer’s name, the service and the conversation are sent. Internal notes are not.',
        ],
        'ticket_summary' => [
            'label' => 'Summarise a thread',
            'description' => 'The same, for whoever picks the ticket up next. The summary is read by your staff and goes nowhere else.',
        ],
        'ticket_triage' => [
            'label' => 'Suggest a department',
            'description' => 'The subject and the first message, to suggest where an unrouted ticket belongs. A suggestion beside the field, never the stored value.',
        ],
        'incident_update' => [
            'label' => 'Draft an incident update',
            'description' => 'The incident’s title and timeline. A person still publishes it.',
        ],
    ],

    // What a customer may end up reading, which is the distinction somebody
    // turning a feature on actually needs.
    'reaches_customer' => 'A customer may read this',
    'internal_only' => 'Read by your staff only',

    'draft' => 'Draft a reply',
    'drafting' => 'Writing…',
    'drafted' => 'Drafted by :model. Read it before you send it.',
    'summarise' => 'Summarise',

    /*
     * The task sentences actually sent to the model. Here rather than in the
     * prompt builder because they are wording, and an operator running a
     * Turkish desk wants the draft to come back in Turkish without having to
     * say so in their instructions.
     */
    'tasks' => [
        'ticket_reply' => 'Write the next reply to this customer, as the support team. Be brief and concrete. Do not invent facts, prices, dates or promises. If something is unknown, say what you will find out.',
        'ticket_summary' => 'Summarise this conversation for a colleague picking it up: what the customer wants, what has been tried, and what is outstanding.',
    ],

    'errors' => [
        'no_provider' => 'No AI provider is set up on this installation.',
        'not_enabled' => 'The :feature assistant is switched off.',
        'no_credential' => 'No API key is stored for :provider.',
        'unreachable' => ':provider did not answer. Write the reply yourself — nothing has been sent.',
        'refused' => ':provider would not answer this one.',
        'empty_answer' => ':provider answered with nothing.',
        'not_permitted' => 'You do not have access to the assistant’s settings.',
    ],

    'usage' => [
        'title' => 'What the assistant has cost',
        'intro' => 'One row per call. What was asked for and what it cost — never what was written.',
        'empty' => 'The assistant has not been used.',
        'feature' => 'Asked for',
        'who' => 'Who asked',
        'model' => 'Model',
        'tokens' => 'Tokens',
        'unreported' => 'Not reported',
        'when' => 'When',
        'outcomes' => [
            'answered' => 'Answered',
            'refused' => 'Refused',
        ],
    ],
];
