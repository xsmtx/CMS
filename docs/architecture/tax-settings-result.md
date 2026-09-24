# Tax Settings Made Real — Result

Status: complete
Date: 2026-09-24
Follows: `tax-configuration-result.md` and `billing-terms-result.md`.

The tax screen shipped with four answers that are not a rate — whether catalog
prices already include tax, what a tax id is called, whether a business must
give one, and where the cent goes — and **none of them was read by anything**.
Four switches an operator could turn on with no effect, which is worse than four
switches that are absent: the most expensive of them silently adds VAT on top of
prices that already contain it, on every order, until a customer notices.

Closing the last one turned out to need tax calculated per line, which brought a
fifth dead thing with it — a rule scoped to domains or addons could never match
anything at all.

This closes them, and three of them were sitting on bugs.

---

## 1. What shipped

- **`prices_include_tax` works.** The tax is taken *out* of an inclusive price
  rather than added on top, and nothing downstream adds it again.
- **`tax_id_label` works.** Every form that asks for a tax id — the storefront
  checkout, the client's billing details, and both admin customer forms — calls
  it what the seller calls it.
- **The storefront checkout gained the field at all.** It carried a company name
  and no tax id, so a seller who turned the requirement on would have refused
  every business checkout with an error against a field that was not on the
  page — and reverse charge could never be claimed at checkout, because there was
  nowhere to put the number it needs.
- **`require_tax_id_for_business` works.** A business customer is asked for one,
  on all four of those forms, from one rule.
- **`Customer::isBusiness()`** — a company name, not a tax id.
- **`TaxIdentity`** (the two label answers, shared with every page),
  **`AsksForATaxId`** (the validation rule, stated once) and
  **`CurrentTaxSettings`** (the one place that resolves whose settings apply).
- **Tax is calculated per line**, which makes `rounding` mean something and makes
  a rule scoped to products, domains or addons able to match at all.

## 2. The decisions worth keeping

**`included` lives on the answer, not on the question.** `TaxResult` gained the
flag rather than `TaxableSupply`, because only the calculator knows: whether a
catalog price is gross is the seller's setting, and the calculator is the one
thing that reads it. A module's calculator — Avalara, TaxJar, anything written
before this existed — returns false and behaves exactly as it did. No SDK
contract changed for a caller.

**The net is worked out by integer division, then the ordinary arithmetic runs on
it.** `gross / (1 + rate)` in floating point is how a price ends up a cent out in
one direction on every order. A compounding second level multiplies rather than
adds — Quebec's gross is `net × (1 + gst) × (1 + qst)` — so its contribution is
`r2 × (1 + r1)`, and the integer division there loses a fraction of a part per
million.

**So the components are reconciled against the gross, not the other way round.**
The price the customer was shown is the fixed point. `Money::allocate()` splits
the tax that is actually inside it across the components, which loses no cent:
the remainder goes to the largest share rather than evaporating. A test asserts
the components always sum to the total.

**`PriceCart` subtracts rather than adds, and the subtotal carries the net.** That
one line keeps `subtotal + setup − discount + tax = total` true either way, which
is why `PlaceOrder`, `CreateInvoiceFromOrder` and every presenter needed no change
at all: nothing downstream has to know which kind of catalog this is.

**A business is a company name, not a tax id** — and this was a real bug, not a
new decision. `isBusiness` was `tax_id !== ''` in both places that built a
supply, which is circular three ways over: it made an individual who typed a tax
id a business, a company that had not given one an individual, `TaxCustomerKind::Business`
mean "typed a tax id", and a rule saying "a business must state a tax id"
**impossible to ever fire** — a customer without one would not be a business.
Reverse charge is unaffected, because it asks for both: a business *and* an id to
charge it to.

**The default label is not "VAT number".** It is Vergi Numarası in Turkey, an ABN
in Australia, a GSTIN in India, a CNPJ in Brazil. A default that is wrong for most
of the world reads as configured when it is only unset, so the fallback is a
neutral "Tax ID" and a seller states their own.

**A rule scoped to anything but "all" could never match, and that was silent.**
The only supply ever handed to the calculator was built from the cart's *total*
and said `TaxAppliesTo::All`, so `covers()` was false for every rule an operator
had scoped to domains or addons. A whole column on the rules screen that saved
correctly, displayed correctly and did nothing. Several countries genuinely tax a
domain registration and a hosting account at different rates, which is why the
column exists.

**`TaxRounding` had nothing to decide, because there was only ever one
calculation to round.** Both are fixed by the same change: `PriceCart` works the
tax out per line rather than once on the total. Rounding per line is one
calculation per line; rounding once on the invoice is one per **tax treatment**,
so a cart whose lines are all products is a single calculation exactly as before
— and a cart mixing a domain with hosting is two, because two different rates
cannot share one rounding.

A line's own taxable amount is `lineTotal` — what it renews for, plus its setup
fee, less its share of the discount — so the parts add up to the taxable total by
construction rather than by a second calculation that could disagree. No existing
expectation moved.

**`TaxCategory` is a `match` with no default.** It is the one place ordering's
word for what a line *is* meets tax's word for what a rule is charged *on*. A
fifth kind of line fails to compile there rather than silently landing in
whichever category came first — which on a tax screen means whichever rate came
first.

**The requirement is one rule in one trait.** Four forms ask for the field and the
seller's answer has to be the same on all of them; copied into four request
classes it would be one rule in four places, and the one somebody forgot to
update would be the form a customer actually used. It fires on `required_with` the
company field rather than on `required`, because an individual is not a business
and is asked for nothing.

## 3. What the tests cover

`tests/Feature/InclusivePricingTest.php` — 14 cases, and one more in
`StorefrontCheckoutTest`: the tax taken out of an
inclusive price rather than added; exclusive behaving identically whether stated
or defaulted; seven awkward grosses that must not lose a cent; an inclusive price
split across compounding GST and QST with the components summing to the total; an
exemption still charging nothing on an inclusive price; a company with no tax id
being a business and an individual with one not being; the label defaulting and
then stated; the requirement read back; a **cart** whose total is exactly the
shelf price with the document invariant still holding; a cart that adds on top
when exclusive; and the billing-details form refusing a business with no tax id —
only once the seller asked, never for an individual, and accepting one that is
given. The checkout case drives the storefront: the field is rendered with the
seller's own word on it, a guest business without one is refused and no order is
written, and an individual is asked for nothing. Three more cover the per-line
work: an addon charged a different rate from the product it hangs off with both
named separately on the result, a line left untaxed when no rule covers its kind
rather than picking up the neighbouring rate, and the same two lines rounding to
13.16 per line and 13.17 once on the invoice.

## 4. A weak test caught in the writing

The first version of the form case asserted only `assertSessionHasNoErrors()`.
That passes against a **403**, which is what it was getting: the test's contact
had no portal role, so the screen it was meant to be driving was never reached.
`assertRedirect()` alongside it is what makes the assertion mean anything. The
same shape would pass against a 404, which is how a screen whose route moved goes
unnoticed — the lesson `AdminActionRoutesTest` exists for, arriving from the other
direction.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest (1608 passed), ESLint, Prettier, vue-tsc,
Vitest (85 passed), Vite build, `platform:openapi --check`. All green.

## 6. Not done, deliberately

- **Line amounts stay gross on an inclusive catalog.** The document's subtotal
  carries the net and the lines carry the price the customer saw, which is what
  an inclusive invoice looks like in the markets that require inclusive pricing.
  Writing a net amount onto each line is now possible — the tax per line is known
  — but it changes what an order line *records*, which is ADR 0021's territory and
  deserves its own decision rather than being a side effect of this one.
- **`TaxAppliesTo::Manual` is still unreachable.** It exists for a line an
  operator types onto an invoice by hand, and nothing in the cart path can
  produce one. It becomes live when invoice lines are composed the same way,
  which is the same piece of work as the bullet above.
- **Nothing here validates a tax id against a country's format.** Checking an EU
  number means calling VIES, which is a remote call to one union's service — an
  adapter, and therefore a module. The platform stores the id and the exemption
  flag and does not claim to have verified either.
