# InfraCMS API

Everything lives under `/api/v1`. The machine-readable description is
[`openapi.json`](openapi.json), which is **generated from the routes** — a
test fails if the committed copy drifts from them, so it is never out of
date. Errors are described in [`errors.md`](errors.md).

## Authenticating

A bearer token, issued from the portal under **Developer → API tokens**. It
is shown once, when it is created.

```bash
curl https://example.test/api/v1/profile \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

A token belongs to a person. It inherits their permissions, and revoking
their portal access revokes the token with it — which is the property that
makes a token safe to issue at all.

## Scopes

A token carries scopes, and **a scope only ever narrows**. It describes what
the person issuing the token agreed to share with one integration; it can
never let that token do something its owner could not do themselves. A
token with `services:write` held by somebody without permission to see
services reaches nothing.

`:write` does not imply `:read`. Ask for both if you need both.

| Scope | What it reaches |
| --- | --- |
| `profile:read` `profile:write` | The contact and customer behind the token |
| `services:read` `services:write` | Hosting accounts; write is suspend and unsuspend, never terminate |
| `domains:read` `domains:write` | Domains; write is nameservers and renew |
| `invoices:read` | Invoices and their lines. Paying is not possible with a token |
| `orders:read` | Orders and what was on them |
| `tickets:read` `tickets:write` | Support conversations, excluding internal notes |
| `webhooks:read` `webhooks:write` | Endpoints, deliveries and redelivery |

## Money

Always an integer in minor units with an ISO 4217 code. Never a float, and
never a formatted string.

```json
{ "total": { "amount": 1499, "currency": "EUR" } }
```

`1499` is 14.99 in a two-decimal currency. Format it yourself, for your own
user's locale — and never convert between currencies, because this platform
does not either.

## Collections

```text
GET /api/v1/services?status=active&sort=-created_at&per_page=50
```

```json
{
  "data": [],
  "meta": { "page": 1, "per_page": 50, "total": 0, "last_page": 1 },
  "links": { "next": null, "prev": null }
}
```

Filters and sorts are declared per endpoint and listed in `openapi.json`.
**An undeclared one is a `422`, not silence.** A typo that is quietly
ignored gives you a wrong list and no way to discover why.

`per_page` is capped at 100. Asking for more gives you 100 and says so in
`meta.per_page`.

## Retrying safely

Send `Idempotency-Key` on any write. If you do not hear the answer, send
the identical request with the same key: you get the original response back,
status code and all, with `Idempotent-Replay: true`.

```bash
curl -X POST https://example.test/api/v1/tickets \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Idempotency-Key: 6f1e2a7c-..." \
  -H "Content-Type: application/json" \
  -d '{"department_id":"01J...","subject":"Disk full","body":"Uploads stopped."}'
```

Rules worth knowing:

- The same key with a **different** body is a `409`. That is a bug in your
  client, and we would rather tell you than hide it.
- A **refused** write releases the key. Fix the payload and send it again
  with the same key.
- Without a key, two identical requests create two things. That is
  sometimes what you want, so the header is offered rather than demanded.

## Rate limits

Per token, not per IP — two integrations behind one office connection are
two clients. Writes are limited harder than reads. A `429` carries
`Retry-After`; honour it.

## Webhooks

Register an endpoint and we post events to it as they happen.

```bash
curl -X POST https://example.test/api/v1/webhooks \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://you.test/hooks","events":["invoice.paid"]}'
```

The response carries a `secret`. **Store it now** — it is never readable
again.

Choosing no events means all of them.

### Verifying a delivery

Every request carries:

| Header | |
| --- | --- |
| `X-InfraCMS-Event` | `invoice.paid` |
| `X-InfraCMS-Event-Id` | Stable across retries and redeliveries |
| `X-InfraCMS-Timestamp` | Unix seconds |
| `X-InfraCMS-Signature` | `HMAC-SHA256(timestamp + "." + raw body, secret)` |

```php
$expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

if (! hash_equals($expected, $signature)) {
    abort(401);
}
```

Compare against the **exact bytes you received**, before parsing. Reject
anything older than five minutes, and deduplicate on
`X-InfraCMS-Event-Id` — you will see the same id again if a retry or a
redelivery happens.

### What we do when you are down

A non-2xx or a timeout is a failure, and so is a redirect: we never follow
one, because that would post signed customer data somewhere you did not
configure. Failures are retried with a growing gap, up to six times. An
endpoint that fails twenty times in a row is switched off — your
configuration is kept, so fix the URL and switch it back on.

Every attempt is visible under **Developer → Webhooks**, along with a
button to send any delivery again.

## What is not here in v1

- **Placing an order.** It needs a cart, an agreed price, a tax decision and
  a risk decision — the whole checkout, which is a surface of its own.
- **Paying an invoice.** Money, from a credential nobody types. It will get
  its own scope and its own argument.
- **Terminating a service.** Destroying an account unattended is not
  something a token should be able to do.
- **Anything staff-facing.** Admin tokens need their own scopes, limits and
  audit story.
