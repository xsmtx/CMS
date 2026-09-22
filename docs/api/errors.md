# API error contract

Every non-successful JSON response from InfraCMS uses one shape. Clients
branch on `error.code`, never on the HTTP status alone and never on the
message text.

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The submitted data could not be accepted.",
    "details": { "email": ["The email field is required."] },
    "request_id": "01K5Q8ZP4F8T7M0QX9J0K3N2VB"
  }
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `code` | string | Stable machine identifier. New values may appear; existing values are never renamed or repurposed. |
| `message` | string | Translated, safe to show a user. Never contains internal detail in production. |
| `details` | object | Always an object, never an array. `{}` when there is nothing to add. |
| `request_id` | string | The correlation identifier. Quote it in a support request. |

## Codes

| Code | HTTP | Meaning | Retry the same request? |
| --- | --- | --- | --- |
| `validation_failed` | 422 | Input was rejected. `details` maps field → messages. | No, fix the input |
| `unauthenticated` | 401 | No valid credentials or token. | No |
| `forbidden` | 403 | Authenticated, but not permitted — or the resource is outside your organization. | No |
| `not_found` | 404 | No such resource. | No |
| `method_not_allowed` | 405 | Wrong HTTP method for this resource. | No |
| `conflict` | 409 | The request conflicts with current state. | No |
| `invalid_state_transition` | 409 | The resource cannot move to that state from where it is. | No |
| `precondition_failed` | 412 | A required precondition was not met. | No |
| `idempotency_key_conflict` | 409 | The key was already used with a *different* payload. | No, use a new key |
| `payload_too_large` | 413 | Request body exceeds the limit. | No |
| `unsupported_media_type` | 415 | Content type not accepted. | No |
| `rate_limited` | 429 | Too many requests. Honour `Retry-After`. | Yes, after backoff |
| `external_service_failure` | 502 | An upstream provider failed or timed out. | Yes, with backoff |
| `service_unavailable` | 503 | A dependency is unavailable, or maintenance mode is on. | Yes, with backoff |
| `server_error` | 500 | Unexpected failure. Recorded with the correlation ID. | Yes, once |

A `403` never distinguishes "does not exist" from "not yours". Returning
`404` for another organization's resource would leak its existence; the
platform returns the same answer either way.

## Correlation identifiers

Every response carries `X-Correlation-Id`, and the error envelope repeats it
as `request_id`. One value ties together the HTTP request, the jobs it
dispatched, the provider calls those made and the audit rows they wrote.

Send your own `X-Correlation-Id` to have it adopted end to end. It is only
honoured when the installation sets `CORRELATION_ID_TRUST_INBOUND=true` and
the value is a well-formed ULID or UUID; anything else is replaced.

## Handling failures

```ts
const response = await fetch('/api/v1/services', { headers })

if (!response.ok) {
  const { error } = await response.json()

  switch (error.code) {
    case 'validation_failed':
      return showFieldErrors(error.details)
    case 'rate_limited':
      return retryAfter(response.headers.get('Retry-After'))
    case 'unauthenticated':
      return reauthenticate()
    default:
      return reportToSupport(error.request_id)
  }
}
```

Treat an unrecognised `code` as a generic failure and fall back to the HTTP
status class. New codes are additive and must not break an existing client.

## Non-JSON requests

The envelope applies to `/api/*` and to any request that negotiates JSON.
Browser requests to the Storefront, Client Area and Admin Panel receive the
framework's HTML error pages instead.
