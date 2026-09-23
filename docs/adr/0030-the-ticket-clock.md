# 0030 — A ticket has one clock, and one place that moves it

Status: accepted
Date: 2026-09-27

## Context

Support software measures two things: how long a customer waited for a
first human answer, and how long the whole thing took. WHMCS operators
expect both, expressed per department.

The obvious model is a due date per priority per department — four
priorities times two measures is eight numbers an operator configures for
every queue they create. Most of them will be wrong, because nobody tunes
eight numbers per queue; they will be left at whatever was seeded.

Separately, a ticket's status changes from several directions: a customer
replies, staff replies, staff closes it, a customer reopens a closed one by
replying to it. Each of those is a place where `resolved_at`,
`first_responded_at` and `last_reply_at` can be set — or forgotten.

## Decision

**An operator states the SLA once, at normal priority, and the platform
scales it.** A department carries `first_response_minutes` and
`resolution_minutes`; priority multiplies them. "Billing answers within
four hours" is one number, and an urgent ticket in that queue is due in
one.

**A department with no SLA is a real configuration**, not an error to be
defaulted away. Not every queue is measured, and a due date invented for a
queue nobody measures is a false signal on an operator's screen.

**One class moves a ticket's status: `TransitionTicket`.** It owns the
clock fields, and `TicketStatus::canTransitionTo()` owns what is reachable
from where. `ReplyToTicket` does not set a status directly; it decides
which status a reply implies and asks `TransitionTicket` for it.

That last rule was written because the first version did set the status
directly, and a customer replying to a closed ticket reopened it while
`resolved_at` kept the old value — the ticket was open and resolved at the
same time. The bug is not that a line was forgotten; it is that there were
two places where it could be.

## Consequences

Adding a status means adding a case and its transitions in one enum, and
the reply path follows automatically.

A department's SLA can be changed without touching existing tickets: the
due dates already written onto a ticket stay as they were. A clock that
moves retroactively is not a measurement.

A ticket's own dates are copied onto it when it is opened rather than read
through the department, for the same reason an order line copies the
catalog ([ADR 0021](0021-order-lines-copy-the-catalog.md)): the thing being
measured must not change underneath the measurement.
