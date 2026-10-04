# WHMCS parity — what is missing, and what is missing on purpose

Status: planned
Date: 2026-10-04
Follows: Phase J (`phase-j-result.md`), which finished handoff #2's roadmap

---

## 1. Why this document exists

The handoff's two roadmaps are complete, and the product they describe is
larger than WHMCS in every direction that faces infrastructure — the resource
graph, IPAM, DCIM, the guarded change workflow, reconciliation, profitability.
What it has never been audited against is the panel it is meant to replace,
feature by feature, in the places an operator actually works.

So this is a sweep rather than a phase: walk WHMCS's own admin menu, find what
has no answer here, and say for each whether it is a gap to close or a decision
already taken.

**It is a genuine audit, not a wish list.** Most of what WHMCS does is already
here and better specified — proforma invoices, credit notes, the ledger, the
dunning sequence, tax rules, custom fields, tags, the knowledge base,
announcements, promotions, risk, the cart, resellers, the API. Those are not
repeated below.

---

## 2. What is missing, and what each one actually is

### 2.1 Upgrades and downgrades — **the largest gap**

`PackageChange` and `ProvisioningModule::changePackage()` have existed since
Phase 6 and **nothing calls them on a service**. An adapter can be told to move
an account between packages; no use case asks it to, and neither the admin nor
the customer has a way to say so.

In WHMCS this is one of the most-used screens in the product: a customer
outgrows a plan, picks a bigger one, is shown a prorated figure for the
remainder of the current term, pays it, and the account is moved.

What it needs:

- **A prorated amount, worked out from days remaining.** Money is integer
  minor units, so the credit for the unused part of the old term and the charge
  for the new one are both `Money::allocate()` problems rather than floats.
- **An order, not a special case.** An upgrade is a thing somebody buys, so it
  becomes an order with its own line — which means it already has an invoice, a
  payment, risk, tax and the fulfilment path. A second billing path for
  upgrades would be a second place to get prorata wrong.
- **The service moves only when the money does**, which is `OrderPaid`
  subscribing exactly as provisioning already does.
- **A downgrade credits rather than refunds.** Money that has already been
  taken is not sent back by a panel; a credit note and account credit are what
  this product already has for it (ADR 0023, ADR 0024).

### 2.2 Billable items

A one-off charge an operator adds to a customer, which appears on their next
invoice: an hour of migration work, a hardware part, an excess-bandwidth
charge somebody negotiated.

This product has no way to put anything on a future invoice. Usage metering
writes snapshots that the next invoice quotes (Phase F), and that is the shape
to follow — a `billable_items` row that a renewal run picks up and a frozen
invoice then quotes, never a line edited onto an issued document.

### 2.3 Quotes

A priced proposal a customer accepts, which becomes an order. WHMCS's quote
lifecycle is draft → delivered → accepted → lost, with a validity date.

This matters more here than in WHMCS, because this product sells dedicated
servers and colocation — things nobody buys from a shopping cart.

The honest shape: a quote is a **document like an invoice** — it freezes its
lines and its bill-to party when it is sent, because a quote whose prices moved
after it was sent is not a quote. Accepting it raises an order.

### 2.4 Mass pay

A customer with nine unpaid invoices paying all nine in one transaction.
Without it they pay nine times, and a gateway charges nine fees.

The ledger already settles an invoice from one path (`RecordPayment`), so this
is a selection screen and one payment intent covering several invoices — with
the allocation written per invoice, because a payment that cannot say which
invoice it settled is a reconciliation problem.

### 2.5 Invoice and quote documents

There is **no PDF and no print view** anywhere in this product. A customer
cannot save their invoice, an accountant cannot file it, and in several
jurisdictions a VAT invoice has to exist as a document rather than as a web
page.

This is the one gap with a real dependency: it needs a renderer. The decision
is whether that is a Blade print stylesheet the browser prints, or a PDF
produced on the server. A print view is cheaper and is what a customer
actually uses; an attached PDF is what an accounting system expects.

### 2.6 Affiliates

WHMCS's affiliate system: a referral link, a cookie, a commission on the first
or on every payment, a payout threshold, and a withdrawal.

**This one is a decision rather than an obvious gap.** The reseller family
already answers "somebody else sells our hosting and we pay them" with a
ledger, a balance and a margin — and an affiliate is a thinner version of the
same idea. Whether that is worth a second mechanism is the question to answer
before building, not after.

### 2.7 Client downloads

A file a customer can fetch: an installer, a licence file, a manual. WHMCS puts
them behind product ownership or a client login.

Small, and it brings file storage into a product that has deliberately had
none. Worth doing only if the answer to "where do the bytes live" is a decision
somebody has made.

### 2.8 Mass mail

Sending a message to a filtered set of customers — "everybody on the server we
are migrating on Saturday". `Notifier` and the template system already exist;
what is missing is the audience and the rate at which it is sent.

The reason to do it is the reason the status page exists: the alternative is an
operator pasting four hundred addresses into their own mail client, where
nothing is recorded and the opt-out list is not consulted.

### 2.9 Banned addresses

WHMCS bans an IP after repeated failed logins or a fraud rule. This product
throttles sign-in and holds risky orders, which covers most of it — what it
lacks is an operator's own list: "this address, for these reasons, until this
date".

The shape is `access_grants` inverted, and the same rule applies: whether a ban
is live is a question about its own timestamps, never a state column.

### 2.10 Ticket escalations

WHMCS escalates a ticket that has sat too long: move department, raise
priority, email somebody. This product has SLA clocks on tickets (ADR 0030) and
an alerting family that already knows how to raise and clear — so the pieces
are there and nothing joins them.

### 2.11 Product bundles

Several products bought as one with a combined price. The order line already
copies the catalog, so a bundle is a catalog construct rather than a new order
shape.

Lower value than the rest: a bundle is approximated today by a product with
addons, which is how most hosts actually sell.

---

## 3. What WHMCS has that this product deliberately does not

Each of these is already decided and written down. They are listed so the audit
is complete and so nobody re-opens them by accident.

| WHMCS feature | Why not |
| --- | --- |
| Currency exchange rates | There is no rate anywhere in this product. A total across currencies is a figure that means nothing and it is the figure somebody would quote (`MoneyByCurrency`). |
| Database backup button | A PHP process cannot take a consistent snapshot, and one that produced an inconsistent snapshot would be worse than none. `docs/operations/` holds the runbooks instead. |
| `is_admin` / full-access flags | Permissions, always. `super-admin` is the only bypass. |
| Editing an issued invoice | Frozen at issue; corrections are credit notes (ADR 0023). |
| Live chat embed | A CSP decision before it is a module one. `script-src 'self'` has no exceptions on purpose. |
| Auto-apply of infrastructure changes | The guarded workflow is the point; a setting for it would be the shortcut around it. |
| Project management | A different product. |

---

## 4. Sequence

Commercial value first, because that is where the gaps are:

1. **Upgrades and downgrades**, with prorata. The largest gap and the most-used
   WHMCS screen that has no answer here.
2. **Billable items**, which the next invoice quotes.
3. **Mass pay**, which is a selection and one payment.
4. **Invoice documents** — the decision first, then the renderer.
5. **Quotes**, which reuse the frozen-document rules invoices already have.
6. **Ticket escalations**, joining two families that already exist.
7. **Banned addresses**, `access_grants` inverted.
8. **Mass mail**, an audience over `Notifier`.
9. **Affiliates** — decide first, build second.
10. **Downloads** and **bundles**, if they still look worth it by then.

Each is an increment in the usual shape: build, translate into both languages,
run the gates, drive it in a browser, record the lesson, tick
`docs/design/propagation.md`, commit.

---

## 5. Not in this sweep

Anything that would re-open §3. And anything that requires this product to hold
files until somebody has decided where files live — which at present is §2.7
and half of §2.5.
