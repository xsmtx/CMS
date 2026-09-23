# Phase 8 — Support + Content + Notifications Plan

Status: approved for implementation
Date: 2026-09-27
Scope: V2 roadmap Phase 8 — ticketing with departments and SLA, the
knowledge base and announcements, and the notification system: templates,
channels and delivery records. Email piping into tickets and SMS are
explicitly later; the seams they need are built here.

---

## 1. Starting point

Four phases have written "Phase 8 will send this" and moved on. An order
is placed and nobody is told. A service is provisioned with credentials and
the customer finds them by opening the portal. A domain fails to register
and an operator sees it only if they look at the right screen.

Meanwhile `contacts` has carried `notify_invoices`, `notify_support`,
`notify_product` and `notify_marketing` since Phase 1, with nothing reading
them.

What this builds on:

- **Domain events** ([ADR 0027](../adr/0027-contexts-meet-through-events.md)).
  `OrderPaid` exists and has two subscribers. This phase adds the events the
  other phases wanted and a third kind of subscriber.
- **The provider-contract pattern**, proven four times: gateways, modules,
  registrars, and now channels.
- **Two guards and one organization boundary.** A ticket is owned by a
  customer and worked by staff, which makes it the first record both sides
  write to.

## 2. The central decision: an event is not a message

The obvious design sends an email from the listener. It works for one
channel, one locale and one recipient, and then every new requirement
changes every call site.

**A domain event says what happened. A notification decides who should be
told, in what language, through which channel, and whether they asked not
to be.** Those are four separate questions and none of them belong to the
code that took a payment.

So:

```text
OrderPaid ──> Notifier ──> template (event + locale + brand)
                       ├─> recipients (who, and did they opt out)
                       └─> channels ──> Mail
                                    ├─> Database (in-app)
                                    └─> Webhook
```

- **A template is data, not a class.** One row per (event, locale), with a
  subject and a body an operator can edit, preview and reset. A platform
  whose wording lives in PHP cannot be white-labelled, which is the whole
  product.
- **Every send is recorded.** `notification_deliveries` holds what was sent,
  to whom, on which channel, and whether it arrived. "Did the customer get
  the suspension warning" is the first question of every dispute.
- **A channel is a contract.** Mail, database and webhook ship; SMS is a
  fifth implementation of an interface that already exists.
- **Opt-outs are checked once, centrally.** `notify_invoices` and its
  siblings finally do something, and a transactional message a customer
  cannot switch off is marked as such rather than quietly ignoring them.

## 3. Tickets

```text
Department ──> Ticket ──> Reply (public | internal note)
                  │           └─> Attachment
                  ├─> watchers (contacts)
                  └─> links: service | domain | invoice | order
```

| State | Meaning |
| --- | --- |
| `open` | Waiting for us. |
| `answered` | We replied; waiting for the customer. |
| `customer_reply` | They came back. Back in the queue. |
| `on_hold` | Waiting for something outside the conversation. |
| `closed` | Done. A customer reply reopens it. |

**The clock is what makes a ticket a ticket.** `first_response_due_at` and
`resolution_due_at` are computed from the department's SLA when the ticket
is opened, and `first_responded_at` is stamped by the first **public** staff
reply — an internal note is not an answer to the customer and must not stop
their clock.

Priority affects the SLA, not the sort order alone. A department with no SLA
is a department whose tickets have no due dates, which is a real
configuration and not an error.

**Attachments** are stored outside the public directory, served through a
controller that checks ownership, and validated on extension **and** MIME.
An attachment is the one place in this platform where a customer uploads
bytes that staff will open.

## 4. Content

**Announcements** — title, body, published-at, optional expiry, and a flag
for whether they are public or only for signed-in customers. Rendered on the
storefront and the client dashboard.

**Knowledge base** — categories and articles, slugged, searchable, with a
view counter and a "was this helpful" that writes a number rather than a
conversation. Public by default; an article can be restricted to signed-in
customers.

Both are Markdown, rendered server-side and escaped. An operator writing an
article is not a reason to allow HTML from the database into a page.

## 5. Permissions added

| Permission | Notes |
| --- | --- |
| `support.tickets.view` | |
| `support.tickets.manage` | Reply, assign, close |
| `support.tickets.delete` | high risk |
| `support.departments.manage` | |
| `content.announcements.manage` | |
| `content.kb.manage` | |
| `notifications.view` | Delivery log |
| `notifications.manage` | Templates. high risk — they go to customers |
| `portal.tickets.view` | Customer scope |
| `portal.tickets.create` | Customer scope |

## 6. Events added

`ServiceProvisioned`, `ServiceSuspended`, `ServiceTerminated`,
`DomainRegistered`, `DomainExpiring`, `InvoiceIssued`, `PaymentReceived`,
`PaymentFailed`, `TicketOpened`, `TicketReplied`, `OrderPlaced`.

Each carries identifiers, not models, for the same reason `OrderPaid` does.
They are a public surface: Phase 12's modules will subscribe to them, so
their shape is a compatibility promise.

## 7. Screens

**Admin.** Tickets: queue with department, status and SLA-breach filters;
detail with the conversation, internal notes, assignment, canned responses
and the linked records. Departments. Announcements. Knowledge base.
Notification templates, with a preview and a test send. The delivery log.

**Client.** Tickets: list, open a new one against a department and
optionally a service, reply, attach. Announcements on the dashboard.
Knowledge base, searchable, on the storefront.

## 8. Testing

- The clock: an internal note does not stop the first-response timer; a
  public reply does. A breach is visible before it happens, not after.
- Isolation: a customer sees their own tickets; a reply marked internal
  never reaches a customer payload.
- Opt-outs: a contact who turned off invoice mail does not get one; a
  transactional message reaches them anyway and says why.
- Templates: a missing locale falls back; a template an operator has broken
  does not take down the send.
- Delivery: every send writes a row; a channel that throws marks the row
  failed and does not stop the other channels.
- Attachments: a file outside the allow-list is refused; a customer cannot
  download somebody else's.
- Markdown: a script tag in an article body is escaped.

## 9. Order of work

1. Notification core: channels, templates, recipients, deliveries, the
   `Notifier`.
2. The events, and listeners for the ones previous phases asked for.
3. Tickets: tables, state machine, SLA, replies, attachments.
4. Content: announcements and the knowledge base.
5. Admin screens, then client screens, then the storefront.
6. Translations, ADR, result document.
