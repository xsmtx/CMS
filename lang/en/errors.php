<?php

declare(strict_types=1);

return [
    'validation_failed' => 'The submitted data could not be accepted.',
    'unauthenticated' => 'You need to sign in to continue.',
    'forbidden' => 'You do not have permission to perform this action.',
    'not_found' => 'The requested resource could not be found.',
    'method_not_allowed' => 'That action is not available on this resource.',
    'conflict' => 'The request conflicts with the current state of the resource.',
    'invalid_state_transition' => 'This item cannot move to that state from where it is now.',
    'precondition_failed' => 'A required precondition was not met.',
    'idempotency_key_conflict' => 'This idempotency key was already used with a different request.',
    'payload_too_large' => 'The submitted data is too large.',
    'unsupported_media_type' => 'That content type is not supported.',
    'rate_limited' => 'Too many requests. Please try again shortly.',
    'external_service_failure' => 'An upstream provider did not respond correctly. Please try again.',
    'service_unavailable' => 'The service is temporarily unavailable. Please try again shortly.',
    'server_error' => 'Something went wrong on our side. The incident has been recorded.',
];
