# Phase 8 — Support, Content and Notifications Result

Status: complete
Date: 2026-09-27
Plan: `phase-8-plan.md`
Next phase: Phase 9 (Automation) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **774 passed, 2707 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 20 migrations on MariaDB 11.8 | clean |

Phase 7 finished at 718 tests; this phase adds 56.

## 2. The decisions this phase turns on

**An event is not a message**
([ADR 0029](../adr/0029-an-event-is-not-a-message.md)).

Four phases have now written "Phase 8 will send this" in a comment. The
cheap way to discharge that debt is a `Mail::to(...)->send(...)` at each of
those points: one line, reads well, works first time. It also produces a
platform with no list of what it can say, no answer to "did the customer
get told?", an opt-out re-implemented at every send site, a dead SMTP host
that fails the job which suspended a service, and a customer who
unsubscribed from marketing quietly not hearing that their card was
declined.

So a context raises an event and never sends a message. Between them sit
one enum — the complete list of what this installation can say — and one
class, `Notifier`. Every send writes a row, **including the ones that do
not happen**: `suppressed` and `failed` are different answers to an
operator's question, and a log of successes can give neither. One channel
failing never stops the others, and nothing thrown escapes.

**A ticket has one clock, and one place that moves it**
([ADR 0030](../adr/0030-the-ticket-clock.md)).

A department states its SLA once at normal priority and priority scales it,
rather than eight numbers per queue that nobody will tune. A queue with no
SLA is a real configuration, not an error to default away — an invented due
date is a false signal on an operator's screen. And `TransitionTicket` owns
the status and the clock fields, because the first version let
`ReplyToTicket` set a status directly and a reopened ticket kept its
`resolved_at`.

## 3. Problems found

**`ReplyToTicket` bypassed `TransitionTicket`.** A customer replying to a
closed ticket reopened it while `resolved_at` kept the old value: open and
resolved at once. The bug was not a forgotten line, it was that there were
two places the line could go. Replies now decide which status they imply
and ask the one class that owns it.

**Laravel splits translation keys on dots.**
`notifications.messages.invoice.issued.subject` is unreachable, because
`invoice.issued` is read as two levels of nesting.
`NotificationEvent::translationKey()` underscores the event value, so the
enum stays dotted and the lang files stay flat.

**The Support role held none of the support permissions.** Found by a test
asking whether staff could read a ticket attachment: the role literally
called Support could not view a ticket, because every phase since 5 added
permissions to `CorePermissions` and none of them revisited the seeded
roles. Support now gets tickets, content and the delivery log — not the
delete permission, and not the template editor, which changes what every
customer is told.

**A customer could not open a ticket at all.** A customer is an
organization of its own and a department belongs to the seller, so the
ownership boundary — "this customer and everything below" — never contains
one. The symptom was a 404 from the department lookup and an empty
department list on the form.

**And the fix was wrong the first time, in a way worth writing down.**
`withoutBoundary()` returned a *builder*, which was then executed by the
caller. A global scope is applied when a query runs, not when it is built,
so the boundary was back by the time `first()` was called and the result
was still empty — with no error anywhere. `SellerDepartments` now runs
every query inside the callback and hands back rows.

**`InAppNotification` broke the owned-models test.** It carried
`organization_id` with no scope on it. Rather than exempt it, the boundary
grew `applyToNullable()` — a notification to a staff member has no customer
organization, and `whereIn(...)->orWhereNull(...)` is the honest expression
of that.

**A `use RuntimeException;` in a Pest test file makes the suite exit 1**
with every test passing and nothing printed. Cost an hour of bisecting a
green suite. A test file has no namespace, so the import is unnecessary as
well as harmful.

**`StorefrontContentController` failed the layering test** by reaching for
an Eloquent builder. `CLAUDE.md` says the fix is the code, so the
visibility rule moved to `VisibleContent` — which is where it belonged
anyway, since four screens ask the same question.

**Rector removed an unused parameter and left the call sites.** It was
right: staff have no per-event opt-out, so `staffFor()` never needed the
event. Second time this pattern has appeared.

**The arch tests ran out of memory at 512 MB.** Silently: the suite
reported all green and the process exited non-zero. The test script now
runs at 1 GB.

## 4. What was built

### Tickets

Departments with SLA, tickets with a number allocated in the seller's name
([ADR 0025](../adr/0025-documents-are-numbered-in-the-sellers-name.md)),
replies, **internal notes**, attachments, priority, assignment, canned
responses and a status enum that knows its own transitions.

`publicReplies` rather than `replies`, everywhere on the customer's side
and without exception. An internal note is a message between colleagues
about the person reading that screen, and one forgotten `where` puts it in
front of them; two relations make that impossible rather than unlikely.

### Attachments

The one place somebody outside hands this platform bytes that staff will
later open, so it is deliberately suspicious. Extension **and** MIME
checked against allow-lists — either alone is a string the browser sent.
The stored name is generated and the browser's filename is display-only,
never a path. Nothing lands in a public directory; a controller answers
"may this person read this file", and answers 404 rather than 403, because
a 403 confirms the file exists.

### Content

Announcements and a knowledge base, both readable without signing in, both
with three visibilities. A draft is never returned to anyone. Markdown is
**escaped first and rendered second**: an article is written by a
colleague, but it is stored in a database and reaches every customer's
browser, and being written by a colleague is not a security property.

### Notifications

Eleven events across four categories. Three channels — mail, in-app and
webhook — each behind the same contract-and-registry shape this platform
has now used five times, registering only what is configured.

Templates are editable per event and per locale, with a preview, a test
send to yourself, and a reset that deletes the override rather than
rewriting it with the shipped wording. An unmatched placeholder is left as
itself: `:invoice_number` in a test send is a visible mistake, a blank
space is not.

The delivery log is the operator's answer to "did they get told?", and it
distinguishes *sent*, *failed* and *suppressed* on the screen because they
are three different conversations with a customer.

### Screens

Admin: the ticket queue with the SLA state an operator opens it for, a
ticket with public replies and internal notes visibly separated, the
announcement and article editors, the template editor and the delivery log.

Client: tickets, a reply box, notifications and the four opt-out switches —
with "always sent" stated in words on the transactional ones rather than
shown as a switch that does nothing.

Storefront: the knowledge base, an article, and announcements, in Blade,
themeable in Phase 11.

## 5. Files

```text
app/Domain/Support/        TicketStatus, TicketPriority, ArticleVisibility,
                           Events/{TicketOpened,TicketReplied}
app/Domain/Notifications/  NotificationEvent, NotificationChannel,
                           NotificationCategory, NotificationAudience,
                           NotificationRecipient, RenderedMessage,
                           DeliveryStatus, Contracts/{DeliversNotifications,
                           DeliveryOutcome}
app/Application/Support/   OpenTicket, ReplyToTicket, TransitionTicket,
                           StoreAttachment, SellerDepartments, Exceptions/
app/Application/Content/   VisibleContent
app/Application/Notifications/
                           Notifier, RenderTemplate, ResolveRecipients,
                           Listeners/{SendEventNotifications,
                           SendTicketNotifications}
app/Infrastructure/Support/Models/
                           Department, Ticket, TicketReply,
                           TicketAttachment, CannedResponse
app/Infrastructure/Content/Models/
                           Announcement, KbArticle, KbCategory
app/Infrastructure/Notifications/
                           ChannelRegistry, Channels/{Mail,Database,Webhook},
                           Mail/TemplatedMessage,
                           Models/{NotificationTemplate,
                           NotificationDelivery,InAppNotification}
app/Http/                  Controllers/Admin/{Ticket,Content,
                           NotificationTemplate},
                           Controllers/Client/{Ticket,Notification},
                           Controllers/StorefrontContentController,
                           Controllers/Support/AttachmentController,
                           Requests/Support/, Requests/Client/
app/Policies/              Ticket
app/Support/View/          Markdown
database/migrations/       support and notification tables (11)
resources/js/Pages/        Admin/Support/{Index,Show},
                           Admin/Content/{Announcements,Articles},
                           Admin/Notifications/{Templates,Log},
                           Client/Support/{Index,Create,Show},
                           Client/Notifications/Index
resources/views/           storefront/{knowledge-base,
                           knowledge-base-article,announcements}
lang/{en,tr}/              support.php, notifications.php
docs/adr/                  0029, 0030
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **Email piping** | A ticket can be opened and answered in the browser, not by replying to the notification. Piping needs an inbound route, a parser that strips quoted history and signatures, a loop guard and a spam decision — a phase's worth of work on its own, and done badly it posts a customer's entire mail thread into a ticket. |
| **SMS and Slack channels** | The contract and the registry take them without changes. Neither can be written honestly without an account to test against, which is the same reason PayPal and three control panels were deferred. |
| **Webhook retries** | `WebhookChannel` posts once, with a timeout, and records the failure. A queued retry with backoff belongs with the rest of Phase 9's scheduling rather than as a second retry mechanism here. |
| **Ticket escalation and auto-close** | The clock records breaches; nothing acts on one. "Close a ticket answered three weeks ago" and "escalate a breached one" are scheduled runs, which is Phase 9. |
| **Article search beyond full-text** | `whereFullText` on title and body, which MariaDB does well enough for a few hundred articles. Anything more is a search engine, and adding one before an installation has articles would be furniture for an empty room. |
| **Customer-facing SLA display** | A department's promise is visible to staff. Showing "answered within four hours" to a customer turns an internal target into a commitment, which is an operator's decision rather than a default. |
| **Per-contact notification routing** | Preferences are per contact, and every contact who can reach the portal is written to. Routing invoices to one address and outages to another is a real request that needs its own screen. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **No message has been delivered by a real transport** | Mail is asserted against `Mail::fake()`, webhooks against faked HTTP. The templates, the fallbacks and the delivery rows are proven; SMTP, DKIM and deliverability are not. |
| **The client screens have still not been driven in a browser** | Signing in to the portal needs a password typed into a form, which I do not do. The screens are proven by feature tests asserting the rendered Inertia page, which is not the same as having used them. |
| **A webhook endpoint is trusted with what it is sent** | The payload carries the rendered message, which may include a customer name and an invoice number. An operator pointing a webhook at a third party is sending customer data there, and nothing in the product says so at the moment they save it. |
| **Attachment checking is an allow-list, not an antivirus** | A `.pdf` that is genuinely a PDF and genuinely malicious passes every check here. The controller forces a download and sends `nosniff`, which protects this origin and not the operator who opens the file. |
| **Suppression is recorded, never surfaced to the customer** | A customer who opted out of service notices and then misses a suspension has an accurate log entry explaining it. They were not warned at the moment they switched it off. |
| **In-app notifications are not real-time** | They appear on page load. Broadcasting is configured and unused. |

## 8. Exact next recommended task

**Phase 9 — Automation**, which is what turns everything built so far into
something that runs without somebody watching it.

1. The scheduled runs: renewals, invoice generation, overdue reminders,
   suspension and termination, domain expiry — each idempotent, each
   reporting what it did.
2. Dunning as a configurable sequence rather than hard-coded days, with the
   notification events already in place as its voice.
3. A run history an operator can read: what ran, what it touched, what it
   refused to touch and why.

The three pieces this phase leaves ready for it: `Notifier` is the voice,
`expiringWithin()` and the SLA queries are the questions, and every write
path from Phases 4, 6 and 7 is already idempotent.
