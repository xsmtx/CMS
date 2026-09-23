# 0034 — A write is replayable, and so is a delivery

Status: accepted
Date: 2026-09-23

## Context

Two problems that look unrelated and are the same problem.

**Inbound.** A client POSTs an order. The request succeeds, and the
response is lost — a proxy timeout, a dropped connection, a worker
restart. The client does not know whether the order exists. Retrying risks
two; not retrying risks none. There is no correct choice available to it.

**Outbound.** An operator's billing system was down for an hour when
`invoice.paid` fired. The event is gone unless something kept it, and the
operator finds out when their accounts do not balance.

Both are a system that acted and could not be sure the other side heard.

## Decision

**The thing that happened is a record, and the record can be replayed.**
It is the third time this platform has reached for that sentence — the
ledger ([ADR 0024](0024-the-ledger-is-the-truth.md)), the operations centre
([ADR 0032](0032-an-operation-is-visible-before-it-finishes.md)), and now
this.

### Inbound: idempotency keys

A write route accepts `Idempotency-Key`. The first request stores the key,
a fingerprint of the request, and **the response it produced**.

- **Same key, same request** → the stored response is replayed verbatim,
  status code included, with `Idempotent-Replay: true`. Storing only "this
  key was used" would let the platform refuse the retry and still never
  tell the client what happened — which leaves it exactly where the key was
  supposed to rescue it from.
- **Same key, different request** → `idempotency_key_conflict`. A key
  reused across two different writes is a bug in the client, and returning
  the first answer would hide it behind a response that looks correct.
- **Same key, first request still running** → conflict, not a guess.
- **No key** → the write proceeds. The header is offered, not demanded:
  requiring it would break every client that sends one request and reads
  the answer, which is most of them. Two identical tickets is also a real
  thing a customer might want.

**Only a success is remembered.** A refused write did not write anything,
so the key is released. Holding it would mean a client that fixed its
payload could never retry, because the corrected body conflicts with the
fingerprint of the one that failed — and "send it again with the same key"
has to be the right advice in every case.

That rule is expressed as a status check rather than a try/catch, because
Laravel's routing pipeline converts a validation failure into a 422
*response* before any middleware sees it. A try/catch would never fire for
the commonest refusal, which is precisely the case that matters.

Keys are scoped to the token, never to the installation: two integrations
generating UUIDs must not be able to collide, and a key is only ever a
promise to the client that sent it.

### Outbound: webhook endpoints and deliveries

`webhook_endpoints` are rows with an encrypted secret, a set of subscribed
events and an active flag. Every attempt is a `webhook_deliveries` row.

- **The payload is stored, not rebuilt.** An event is a statement about a
  moment; re-rendering it from current rows a week later would post a
  different fact under the same event id, which is exactly what a receiver
  deduplicating on that id cannot survive.
- **`event_id` is stable** across every attempt, every endpoint and every
  manual redelivery — the same promise this platform asks of its own
  clients with an idempotency key, made in the other direction.
- **Signed over the exact bytes**, HMAC-SHA256 of `timestamp.body`, so a
  receiver can do what this platform does with incoming gateway webhooks:
  check before parsing, and reject a replay by the age of the timestamp.
  Signing a re-encoded body produces a signature the receiver cannot
  reproduce, which is the classic way this goes wrong.
- **A 3xx is a failure, not a hop.** Following a redirect would post signed
  customer data to wherever it points, and a webhook receiver that
  redirects is misconfigured.
- **Retry lives in the row, not in a delayed job.** A delayed job is a
  promise held by Redis; a row with a date in the past survives a flush.
  The sweep is an ordinary automation task
  ([ADR 0031](0031-a-run-is-a-record.md)).
- **An endpoint that keeps failing is switched off, not deleted.** A dead
  URL posted to forever is a slow denial of service against this platform's
  own queue, and the operator whose endpoint it is finds out faster from a
  disabled switch than from silence. Their configuration survives so they
  can fix the URL and switch it back on.
- **The secret is shown once.** Anybody holding it can forge an event from
  us; a platform that will re-read it on request has turned every listing
  into a way to steal it.

## Consequences

An integrator can retry anything safely, and a receiver can deduplicate
anything safely. The two halves use the same vocabulary, which means an
integrator who has understood one has understood the other.

The cost is two tables and a middleware that has to run on every write.
Both hold customer data — a stored response body, a stored payload — so
both are cleaned up on a retention the cleanup task owns, rather than kept
forever.

What is deliberately **not** solved: there is no dead-letter queue and no
alert when an endpoint is disabled. An operator finds out by looking. That
is Phase 12's operations work, and `Notifier` is already the voice it will
use.
