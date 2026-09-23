# 0033 — The API is a surface, not a system

Status: accepted
Date: 2026-09-23

## Context

Nine phases have put every rule this platform has into an application use
case: `OpenTicket`, `ReplyToTicket`, `RunServiceOperation`,
`RunDomainOperation`, `RecordPayment`. The screens call them. The scheduler
calls them. Nothing owns a rule twice.

A public API is the first thing that can break that. The tempting shape is
an `Api` namespace with its own controllers, its own queries and — within
two phases — its own slightly different rules. It starts as "the API needs
a lighter version of this" and ends with a client whose ticket priority
means something different from the portal's, and with two places to fix a
bug.

There is a second, sharper version of the same risk. An API needs an
identity, and the obvious move is to give it one: a token subject, an API
guard, a separate authorization path. That path will eventually diverge
from the browser's, and the divergence will be in the direction of
permissiveness, because the browser path is the one people test by using
it.

## Decision

**The API calls the same application use cases the screens call.** A
controller under `Api/V1` validates, authorizes, calls a use case and
renders a resource. It contains no rule a portal controller does not, and
where the portal has a rule the API does not, that is a bug in one of them.

**A token resolves to an ordinary client actor.**
`AuthenticateApiToken` finds the token, checks it, and puts its contact on
the `client` guard. From that point `CurrentActor`, `CurrentCustomer`, the
organization boundary and every policy behave exactly as they do for
somebody signed into the portal — and the boundary is established by
calling the same middleware a browser request uses, rather than by
re-implementing it.

**Authorization stays three questions, and a scope is a fourth.**
Organization boundary, resource ownership, permission — then the token's
scope. The order matters and so does the direction: **a scope can only
narrow.** A token carrying `services:write` held by a contact without
`portal.services.view` reaches nothing. A scope is what the token's holder
consented to share with one integration; a permission is what the platform
allows that person to do. Confusing the two turns an API token into a
privilege-escalation path, and it happens by omission rather than by
design.

Each scope therefore declares the permissions behind it, on the enum, so
that adding a scope forces the author to answer "and what must the person
be allowed to do" in the same edit.

**`:write` does not imply `:read`.** A token that may open a ticket without
reading the others is a real integration — a contact form on somebody's own
site — and the reverse is more common still.

**A token from before scopes existed carries nothing.** Tokens issued in
Phase 5 hold `*`, which meant "everything" in a world with no API. Reading
it as full access would hand every pre-existing token the whole surface on
the day this shipped. It consented to nothing, so it gets nothing.

**Invoices are read-only and there are no admin scopes.** Paying an invoice
moves money and a token is a password nobody types; staff tokens need their
own scope set, their own limits and their own audit story. Both are
decisions rather than omissions, and both get their own argument when they
change.

## Consequences

Adding an endpoint is: a route with a scope, a controller method that calls
an existing use case, and a resource. If no use case exists, the endpoint
is not the place to write one.

Every refusal a customer can hit in the browser, an integrator hits
identically — including 404-rather-than-403 for somebody else's record, so
that an id cannot be used to discover what exists.

Two tests carry the decision: one asserts a token without the scope is
refused, and one asserts a token *with* the scope but whose holder lacks
the permission is refused too. The second is the one that matters, and it
is the one an implementation without this ADR would not have.

The cost is that the API cannot be "simpler" than the product. That is the
intent.
