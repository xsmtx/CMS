<?php

declare(strict_types=1);

return [
    'title' => 'Reports',
    'subtitle' => 'The monthly review: what recurs, what is owed, what is coming, and where it came from.',

    'aging' => [
        'current' => 'Not yet due',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        'over_90' => 'Over 90 days',
        'no_due_date' => 'No due date',
    ],

    'errors' => [
        'not_permitted' => 'You do not have access to the reports.',
    ],
];
