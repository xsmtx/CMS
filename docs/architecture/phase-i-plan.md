# Phase I — Mobile: the surface, not the app

Status: planned
Date: 2026-09-29
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §26
Decides: ADR 0049 (a staff API token), building on ADR 0033, 0034, 0044

---

## 1. What this phase is, and what it deliberately is not

ADR 0044 already decided mobile: **React Native with Expo, in a separate
repository**, consuming `/api/v1` and the types generated from
`docs/api/openapi.json`. So Phase I builds **no application**. Nothing in this
repository gains a `mobile/` directory, an Expo config or a React component.

What this phase builds is the four things ADR 0044's consequences say the apps
cannot exist without, every one of which is a decision about this product's
security rather than about a phone:

1. **A staff API surface**, which handoff #1 deliberately did not build.
2. **A token lifetime**, because today's tokens never expire.
3. **Refresh rotation with reuse detection**, because a long-lived bearer
   string in a pocket is the thing rotation exists to survive.
4. **A device inventory with remote revocation**, because "I left my phone in
   a taxi" has to have an answer that is not "we revoke everything you have".

The customer app can already be built against today's API. The staff app
cannot, and that is the gap this phase closes.

---

## 2. The decision that has to come first (ADR 0049)

ADR 0033's rule is that the API is a surface, not a system: a token's contact
goes on the `client` guard, and `CurrentActor`, `CurrentCustomer`, the
boundary and every policy then behave exactly as they do for a browser. A
staff equivalent is one line of middleware and a very large decision, because
**a staff token for an Administrator would be a bearer string that holds every
staff permission** — an Administrator holds them all by design, which is the
same fact that made `resellers.administer` and `tax.manage` gates rather than
permissions.

So the ADR settles five things, and the shape of them is already implied by
what this product has decided before:

**A staff token is always scoped, and a scope only ever narrows.** ADR 0033's
rule exactly: each scope declares its required permissions, and a token whose
holder lacks them reaches nothing. `:write` never implies `:read`. There is no
`*`.

**A staff token always expires, and there is no unlimited option.** A client
token may live forever because a server-to-server integration is a machine in
a rack that somebody owns. A staff token exists for a phone. An installation
that wants an unattended staff integration wants a service account with a role,
which this product already has, and not a bearer string that is an operator.

**High-risk actions are absent from the staff API, not hidden in the app.**
§26 names them — firewall configuration, device reboot, service termination,
restore, mass actions, physical power control — and says some may be web-only
by policy. The honest implementation is that those routes do not exist on the
API at all: a client can be rewritten, so leaving a button out of an app
enforces nothing. What *is* on the API are the acts §26 actually asks a phone
to perform: acknowledging, assigning, replying, approving, requesting.

**A staff token is issued by its own holder, behind the password challenge**,
and never on somebody else's behalf. `AccessGrants` needed the same rule for
the same reason: a permission can say who may issue and cannot say *whose*
token it is.

**Revoking a staff account revokes its tokens.** The property that makes a
token safe to issue at all, and the contact rule already in
`AuthenticateApiToken` applied to the other guard.

---

## 3. Token lifetime and refresh rotation

Two token kinds where there is currently one.

| | Access | Refresh |
| --- | --- | --- |
| Lifetime | minutes (default 15) | days (default 30) |
| Sent with | every request | only to the refresh endpoint |
| Scopes | the device's, narrowed | none — it can only mint |
| On use | `last_used_at` | **consumed, and a new one issued** |

**Rotation without reuse detection is theatre.** The entire value of rotating
a refresh token is that a stolen copy is detectable: when a refresh token that
has already been exchanged is presented again, either the thief or the owner is
holding a stale one, and there is no way to tell which. So the whole family is
revoked and both parties have to sign in again. Detecting and ignoring it would
leave the thief with a working session and the owner none the wiser.

**A refresh token is stored hashed, like an access token**, and the table keeps
the family rather than the value: an id, the device, the token it replaced, and
when it was used. That chain is what makes reuse detectable at all.

**Existing tokens are not broken.** Every `personal_access_tokens` row in an
installation today has a null `expires_at` and belongs to a contact. They keep
working exactly as they do: the migration adds columns and adds nothing to the
old rows. An integration that has run for a year must not stop because this
phase shipped — the `move_core_adapters_into_modules` rule.

---

## 4. The device inventory

`api_devices`: the thing a refresh family hangs off, and the thing somebody
revokes when a phone is lost.

- Owned by the person (a morph, like a token), carrying a name the person gave
  it, the platform it reported, when it was last seen and — when it is gone —
  when and why it was revoked.
- **Revoking a device is one act that ends everything it holds**: its refresh
  family and its outstanding access tokens, in one transaction. A revocation
  that left an access token alive for fourteen more minutes would be a
  revocation somebody trusts and should not.
- **A revoked device is kept, not deleted.** "This phone was revoked on the
  14th, from Ankara" is the record somebody wants afterwards, and a row that
  vanished is a question that cannot be answered. `SessionRegistry` learned the
  same thing.
- It sits beside the existing session list on the Security screen rather than
  on a screen of its own: a person looking for "where am I signed in" should
  find both answers in one place.

---

## 5. What the staff API actually exposes

Every endpoint calls an application use case that already exists. If no use
case exists, the endpoint is not the place to write one (ADR 0033).

**Read** — the operational dashboard, alerts, incidents and their timelines,
impacted customers and services, resource and service health, backup failures,
abuse cases, tickets, maintenance windows and change records, Remote Hands
tasks, and rack/server lookup by node key or asset tag (which is what §26's
barcode scanning resolves to — a lookup, not a scanner).

**Write** — the six acts §26 asks a phone to perform: post an incident update,
resolve an incident, reply to a ticket, complete or update a Remote Hands task,
decide a network change, and request or grant JIT access. Every one of them
already has a use case, a policy and an audit row.

**Absent by policy** — firewall apply, power actions, service termination,
restore, drain, and every bulk endpoint. Named in one list, and a test walks
the staff API's routes and fails if one of them appears.

---

## 6. Push, and why it is still not here

Phase D declined a `push` channel with its reason recorded, and this phase
does not change that. `NotificationChannel` is a closed enum and a member
nothing implements is a member nothing can set: `ChannelRegistry` would hold no
entry, `Notifier` would deliver nothing, and `notification_deliveries` would
record nothing — the `AddonStatus` rule.

What *does* change is that the table push needs now exists. A device row with a
platform and a last-seen date is exactly where a push subscription lives, so
when a provider module is written the channel has somewhere to address. The
categories §26 lists (`invoice`, `payment`, `domain`, `ticket`, `service`,
`incident`, `security`, `backup`, `maintenance`, `approval`, `on_call`) map
onto `NotificationEvent`'s existing members and its audience split; quiet hours
are a preference on a person and belong with the rest of them, not with a
transport.

That is the honest position: the seam is ready, the transport is not, and
half-building it would be a channel that silently delivers nothing.

---

## 6a. Where it stands (2026-09-29)

Steps 1, 2, 5 and 6 are done, and step 3 is half done.

| Step | State |
| --- | --- |
| 1. Token lifetime, refresh family, reuse detection | **in** — both guards |
| 2. Device inventory and remote revocation | **in** — on the Security screen |
| 3. Staff guard, `StaffApiScope`, the read surface | **partly** — the guard, the scopes and alerts / incidents / remote hands. Tickets, infrastructure health, abuse, changes, maintenance, access and the rack lookup are not routed yet |
| 4. The staff write surface | **partly** — incident update, incident resolve, remote hands move. Ticket reply, deciding a change and JIT request / grant are not routed yet |
| 5. The web-only list, enforced by a test | **in** — `StaffApiSurfaceTest` |
| 6. `platform:openapi` | **in**, and it had to be taught about the staff middleware first |

What is left is more of the same shape rather than anything undecided: each
remaining endpoint calls a use case that already exists, behind a scope that
already has its permissions declared and its wording in both languages.

---

## 7. Sequence

1. ADR 0049, then the token lifetime, the refresh family and reuse detection —
   for **both** guards, because a customer's phone has the same problem.
2. The device inventory and remote revocation, on the Security screen.
3. `AuthenticateStaffApiToken` and `StaffApiScope`, with the read surface.
4. The staff write surface: the six acts.
5. The web-only list, enforced by a test over the router rather than by
   somebody remembering.
6. `php artisan platform:openapi`, which is generated and checked in CI.

---

## 8. Not in this phase

The applications themselves (ADR 0044: a separate repository), the push
transport (§6 above), MDM and enterprise controls (§26 says "later" and means
it), and offline queueing — which is a client concern resting on ADR 0034's
`Idempotency-Key`, already shipped.

Nothing here talks to a provider, so the standing caveat about unproven
adapters is untouched.
