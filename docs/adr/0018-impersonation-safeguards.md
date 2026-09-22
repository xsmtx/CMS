# 0018 — Impersonation safeguards

Status: accepted
Date: 2026-09-23

## Context

Support cannot diagnose "the invoice looks wrong on my screen" without seeing
the screen. Every hosting platform therefore lets staff act as a customer,
and every such feature has the same property: it produces actions in the
audit trail attributable to someone who did not perform them.

## Decision

Impersonation exists, and four controls make it acceptable. All four are
enforced in the service rather than by the UI.

1. **The boundary is checked, strictly below.** A reseller can never
   impersonate another reseller's customer, and staff cannot impersonate a
   contact in their own organization, because that is a colleague rather
   than a customer.
2. **A reason is required and stored.** A review with no stated intent tells
   nobody anything, and review is the only control that makes the feature
   acceptable at all.
3. **Start and end are both audited**, under one correlation identifier, so
   everything done in between can be tied back to the staff member who did
   it.
4. **Only one guard is ever authenticated.** The staff session is replaced by
   the customer session and restored on exit. Keeping both would leave every
   downstream decision asking which of two signed-in identities is the real
   one, and the wrong answer is a privilege escalation.

On top of that:

- Security-sensitive actions are refused while it is active. Changing the
  customer's password, managing their second factor or revoking their
  sessions would let a staff member lock the real owner out of their own
  account, and the trail would show the customer doing it.
- The client layout shows a banner that cannot be dismissed. The whole
  safeguard is that the person can always see the session is not theirs.
- Starting it is rate limited on top of the permission and boundary checks.
  Impersonation is rare by nature, so a burst is worth noticing rather than
  serving.
- The permission is flagged high risk, so a super-admin bypass of it is
  audited.

## Consequences

- Session identifiers are regenerated on both the swap and the restore, so an
  identifier captured before either cannot be replayed after it.
- If the staff account is deleted mid-impersonation, the session ends
  anonymous rather than stranded as the customer.
- A customer cannot tell from the audit trail alone that they were
  impersonated, because the actor recorded is the staff member. That is the
  correct attribution; surfacing it to the customer is a Phase 9 decision
  about what the account activity screen shows.
