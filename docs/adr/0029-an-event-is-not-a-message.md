# 0029 — An event is not a message

Status: accepted
Date: 2026-09-27

## Context

By Phase 8 the platform already has the things worth telling somebody
about: an invoice is issued, a payment fails, a service is suspended, a
domain is about to expire, a ticket is answered. Each of those is a domain
event, dispatched after its transaction closes
([ADR 0027](0027-contexts-meet-through-events.md)).

The obvious next step is to let each context send its own mail. Billing
knows an invoice was issued and has the invoice in hand; provisioning knows
a service was suspended. A `Mail::to(...)->send(...)` at each of those
points is one line, reads well, and works on the first try.

It also produces, within a phase or two, a platform where:

- there is no list of what this installation can send, so an operator
  cannot be shown one and cannot edit the wording;
- "did the customer get told?" has no answer, because nothing recorded it;
- an opt-out has to be re-implemented, correctly, at every send site;
- a mail transport being down takes a provisioning job with it;
- a customer who unsubscribed from marketing silently stops receiving
  "your card was declined".

Each of those is a small mistake at one call site and an operational
problem in aggregate.

## Decision

**A context raises an event. It never sends a message.**

Between the two sits one class, `Notifier`, and one enum,
`NotificationEvent`, which is the complete list of what this installation
can say. A context's listener translates its event into a
`NotificationEvent` plus a payload of already-resolved primitives — a
number, a name, a formatted amount, a URL — and hands it over. Nothing
below the notifier knows what an invoice is.

Four things follow from putting one class in the middle, and none of them
are available when sends are scattered:

**Every send is recorded, including the ones that do not happen.** A
`notification_deliveries` row is written for `sent`, `failed` *and*
`suppressed`. "We did not send it because they asked us not to" and "we
tried and it bounced" are different answers to an operator's question, and
a log that only records successes cannot give either.

**One channel failing never stops the others, and nothing thrown escapes.**
A dead SMTP host must not fail the job that suspended the service. The
notifier catches per channel, records the failure against that channel, and
carries on.

**Opt-out is applied once, in `ResolveRecipients`,** against four broad
categories rather than one switch per event. An operator adding a ninth
event does not add a ninth checkbox to every customer's preferences.

**Transactional messages bypass the opt-out entirely.** `PaymentFailed`,
`ServiceSuspended`, `ServiceTerminated`, `DomainExpiring` and
`InvoiceIssued` are not marketing: they are the platform telling somebody
that something they are paying for is about to stop. `isTransactional()`
lives on the enum so that the answer is attached to the event rather than
re-decided at each send site, and the template editor says "Always sent" on
those rows so an operator is never surprised.

**Wording resolves in three steps:** the operator's template in the
requested locale, then their template in the installation's default locale,
then the wording shipped in `lang/`. A message always has words. An
unmatched placeholder is **left as itself** rather than replaced with an
empty string, because `:invoice_number` in a test send is a visible mistake
and a blank space is not.

## Consequences

Adding an event is: a case on the enum, a listener that builds the payload,
shipped wording in `lang/en` and `lang/tr`, and a test. There is no
question of where the send belongs.

A channel is a contract with a registry that registers only what is
configured, which is the fifth time this platform has used that shape
(gateways, provisioning modules, registrars, channels). A test that wants
to exercise the clock rather than a mail transport registers only the
in-app channel and nothing else changes.

The cost is a layer between a context and a mail. That is the point: the
layer is where the list, the log, the opt-out and the failure isolation
live, and none of them can exist without it.

A second cost is that the payload is primitives, so a listener has to
resolve and format before handing over — including money, which is
formatted by the context that owns the currency and never by the template.
That is deliberate: a template that could reach a model could reach
anything on it.
