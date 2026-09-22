# 0004 — Stable JSON error envelope

Status: accepted
Date: 2026-09-22

## Context

The API is a first-class product surface with third-party consumers. Clients
need to branch on failures programmatically. Branching on HTTP status alone
is too coarse (409 can mean three different things) and branching on a
message string breaks the moment the text is reworded or translated.

## Decision

Every non-successful JSON response uses exactly one shape:

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

- `code` is a member of the `ErrorCode` enum. Members may be added; an
  existing value is never renamed or repurposed, because that is a breaking
  API change requiring a new API version.
- `message` is translatable and safe to show a user.
- `details` is always a JSON object, never an array, so clients handle one
  shape.
- `request_id` is the correlation identifier, so one string ties a customer
  report to the logs.

Domain exceptions opt in by implementing `ProvidesErrorCode` and
`ProvidesErrorDetails`. Everything else is mapped by status. Internal
exception text is never exposed in production.

## Consequences

- Adding an error case means adding an enum member and a translation, which
  is deliberately slightly more work than inventing a message inline.
- HTML and Inertia responses are unaffected; the envelope applies to
  `/api/*` and to any request negotiating JSON.
