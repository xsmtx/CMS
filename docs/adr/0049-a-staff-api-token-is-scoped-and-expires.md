# 0049 — A staff API token is scoped, expires, and cannot do the dangerous things

Status: accepted
Date: 2026-09-29

## Context

ADR 0033 built the API as a surface rather than a system: `AuthenticateApiToken`
puts a token's contact on the `client` guard and then calls the same boundary
middleware a browser request uses, so `CurrentActor`, `CurrentCustomer` and
every policy behave identically whether the caller is a person in a browser or a
machine with a bearer string. A scope is a fourth question after boundary,
ownership and permission, and it only ever narrows.

There is no staff equivalent, and handoff #1 left that out deliberately. ADR
0044 then made it the blocker for the staff mobile app and said, in as many
words, that inventing one in passing while writing a phone screen would be the
wrong way to decide it.

The reason it is not one line of middleware is a fact this product has run into
twice already. **An Administrator holds every staff permission by design.** It
is why `resellers.administer` is a gate rather than a permission — a reseller's
own Administrator would otherwise be able to set their own margins — and why the
tax screen is owner-only rather than behind `tax.manage`. A staff API token
issued the way a client token is issued would therefore be a string that is root
on the installation, that never expires, and that lives in a phone.

§26 of handoff #2 asks for the staff app all the same, and lists exactly what it
should be able to do. Most of that list is reading. The writes are
acknowledging, assigning, replying, approving and requesting — none of which is
destructive.

## Decision

A staff API token exists, under five constraints, each of which is a rule this
product has already adopted somewhere else.

**1. Always scoped, never `*`.** Every staff scope declares the permissions it
requires; a token whose holder lacks them reaches nothing, and `:write` never
implies `:read`. This is ADR 0033's rule unchanged. There is no scope that means
"everything", because the whole problem being solved is that the holder already
has everything.

**2. Always expires.** There is no unlimited option, unlike a client token. A
client token may live forever because a server-to-server integration is a
machine in a rack that somebody owns and patches; a staff token exists for a
device in a pocket. An installation that wants an unattended staff integration
wants a service account with a role — which this product already has — and not a
bearer string that is an operator.

**3. High-risk actions are absent from the API, not hidden in the app.** §26
names firewall configuration, device reboot, service termination, restore, mass
actions and physical power control, and says some may be web-only by policy.
Those routes do not exist on the staff API at all. A client can be rewritten, so
leaving a button out of an application enforces nothing; the refusal has to be
the absence of the endpoint. A test walks the staff routes and fails if one
appears.

**4. Issued by its own holder, behind the password challenge, never on somebody
else's behalf.** A permission can say who may issue a token and cannot say
*whose* token it is — the rule `AccessGrants` needed for grants and
`DecideNetworkChange` needed for approvals, arriving a third time.

**5. Revoking the account revokes the tokens.** `AuthenticateApiToken` already
refuses a contact whose `portal_access` is gone; the staff middleware refuses a
staff user who has been deactivated, in the same place and for the same reason.
That property is what makes issuing a token safe at all.

Two mechanisms come with it and apply to **both** guards, because a customer's
phone has the same problem as an operator's:

**Short-lived access plus refresh rotation, with reuse detection.** A refresh
token is consumed when exchanged and replaced. If one that has already been
exchanged is presented again, either the thief or the owner is holding a stale
copy and there is no way to tell which — so the whole family is revoked and both
have to sign in again. Rotation that detected reuse and ignored it would leave
the thief with a working session and the owner none the wiser, which is worse
than not rotating at all, because somebody would believe it was protecting them.

**A device inventory with remote revocation.** Revoking a device ends its
refresh family and its outstanding access tokens in one transaction — a
revocation that left a valid access token alive for another fourteen minutes is
a revocation somebody trusts and should not. The revoked device row is kept:
"this phone was revoked on the 14th" is the record somebody wants afterwards,
and `SessionRegistry` learned the same lesson when a row that vanished made an
operator's own device invisible in their own list.

## Consequences

**Existing tokens are untouched.** Every `personal_access_tokens` row in an
installation today has a null `expires_at` and belongs to a contact; the
migration adds columns and writes nothing to old rows. An integration that has
run for a year does not stop because this phase shipped — the rule the
`move_core_adapters_into_modules` migration was written under.

**The staff API is smaller than the admin area, permanently.** That is the
design rather than a first cut. Every endpoint calls an application use case
that already exists, and the ones that would need a new use case written are the
ones that do not belong on a phone.

**Step-up authentication is reused, not rebuilt.** Phase 17's
`RequireRecentAuthentication` is what §26 asks for, and biometric unlock stays
what handoff #2 says it is: a local convenience, never the server's proof of who
is asking.

**The device table is where push will address.** A subscription belongs on a
device row, so when a push provider module is written the transport has
somewhere to send. The channel itself stays undone, with its reason recorded in
Phase D — a `NotificationChannel` member nothing implements is a member nothing
can set.

## Alternatives rejected

**Put a staff user on the `staff` guard with no scope changes, mirroring the
client path exactly.** It is one line and it is the bearer-string-is-root
problem: symmetry with the client guard is only safe because a contact's
permissions are already narrow.

**A separate "mobile role" with a reduced permission set.** It sounds tidier and
it moves the decision to whoever configures the installation — so the first
operator who gives their mobile role `infrastructure.power` because it was
convenient has silently undone constraint 3 for everybody. What may be done over
the API is a property of the API.

**Opaque session tokens minted per sign-in, with no separate refresh.** Simpler,
and it forces a choice between a short session that signs an operator out during
an incident and a long one that is the thing rotation exists to avoid.
