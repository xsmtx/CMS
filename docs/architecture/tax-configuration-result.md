# Configurable Tax — Result

Status: complete
Date: 2026-09-24
ADR: [0045](../adr/0045-tax-is-rows-an-operator-edits.md)
Supersedes: the flat-rate driver from Phase 3, which stays as a driver and is no
longer the default.

Asked for in one sentence: *"this application will not be used in one country
only, so each country's different financial working must be configurable — tax
must be editable"*, and *"only the super-admin can see it, in the Setup area"*.

---

## 1. What shipped

- **`tax_rules`** — one row per thing anybody charges. Name, place
  (country / region / postcode), rate, level, compounding, what it applies to,
  which customers it applies to, exemption, priority, a date window, active.
- **`tax_settings`** — the handful of answers that are not a rate: whether catalog
  prices already include tax, where the cent goes, what a tax id is *called* here,
  whether a business customer is asked for one, and the default exemption note.
  One row per seller.
- **`TaxRules`** — the matcher. Most specific place wins, one rule per level.
- **`ConfigurableTaxCalculator`** — an implementation of the `TaxCalculator`
  contract Phase 3 declared. Core's own tax code did not change; the driver did.
- **`/admin/tax`** — three panels, owner only: the rules, how tax behaves, and
  **Try it**.
- **`lang/en/tax.php`, `lang/tr/tax.php`** — and between them not one
  jurisdiction named.

## 2. The decisions worth keeping

**Parts per million, not basis points.** Quebec's QST is 9.975%. In basis points
that is 997.5, which is not an integer, and the first thing anybody would reach
for is a float. `rate_ppm` holds 99 750 and the conversion from the percentage an
operator typed happens **once**, in `TaxRuleRequest`. `(int) round()` rather than
a cast, because `(int) (9.975 * 10000)` is 99 749 on a binary float — a rate
nobody set, charged to every customer in Quebec.

**The rate arrives as a validated string.** `numeric` would accept `1e2` and
`0x14`. A rate is a decimal a tax authority published, so the rule is a regex, and
a test posts all five of the shapes that are not one.

**Two levels, and compounding is a flag.** Canada is the case that decides this:
GST is federal, PST and QST are provincial, and Quebec's QST is charged on the
amount *plus* GST while British Columbia's PST is charged on the amount alone. One
level and no flag cannot express both; three levels would be a number somebody
guessed. Level 2 with `compound` expresses every combination anybody actually
charges, and the test file proves BC and QC side by side.

**Most specific wins, and only one rule per level.** A rule with a postcode beats
one with a region, which beats one with a country, which beats one with no place
at all. Without the "one per level" rule, a US state rate and a national rate
would both fire and the customer would be charged twice.

**A rate change is a new row, or a date window on the old one.** Nothing
recalculates an issued invoice — an issued invoice is frozen (ADR 0023) — and a
test asserts it: change the rate to 1%, delete the rule entirely, and the invoice
that already went to a customer still says what it said.

**Exemption is a flag plus a note, and the note goes on the document.** "A
business elsewhere with a tax id pays nothing" is the shape of reverse charge,
VAT MOSS, and several GST regimes; what the invoice must *say* differs by country,
so the sentence is a column and not a constant.

**The owner's screen and nobody else's.** Not a permission: an Administrator holds
every staff permission by design, so `tax.manage` would let every reseller's own
Administrator set their own VAT rate. The route group is `owner`, and it is on
`/admin/apps` with the other five owner-only tiles rather than in the nav rail.

**Try it calls the real calculator.** The preview is an `Inertia::optional` prop on
the same route the list uses, so an ordinary page load computes nothing, and it
runs `app(TaxCalculator::class)` rather than reimplementing the arithmetic in a
controller. A preview that agreed with a second implementation and disagreed with
the invoice would be worse than no preview at all.

**The rules belong to the seller.** `ResolveSeller`, the same way document numbers
and support departments resolve it — a customer is an organization here, so "the
current organization's tax rules" would be the customer's, which is nobody's.

## 3. What the tests cover

`tests/Feature/ConfigurableTaxTest.php` — 15 cases, each one a country somebody
sells in: Turkey, Germany and the UK side by side; a US state rate beating a
national one; Canada's GST with compound QST and additive BC PST; a reverse charge
with its note; applies-to and customer-kind narrowing; a rate that changed on a
date; an inactive rule; postcode prefixes; one rule per level; half-up rounding on
19.75% of 333; zero and negative amounts; and rules read from the seller rather
than from the acting organization.

`tests/Feature/TaxScreenTest.php` — 7 cases driving the screen over HTTP: the
owner-only refusal for an Administrator on both the read and the write, rule
create / edit / delete, 9.975 surviving the round trip, five malformed rates
refused without writing a row, settings saved and re-saved into one row, the
preview through a real partial reload, and an issued invoice left alone.

## 4. A class of bug this found

`FrontEndTranslations` gained a block comment containing the path
`lang/*/tax.php`. The `*/` inside it closed the comment, the rest of the sentence
became PHP, and the file stopped parsing — which 500'd **every rendered page in
the product**, not just the tax screen. No unit test caught it, because nothing
that does not render a document touches that class; six of the seven new screen
tests passed while it was broken.

The lesson is the one Phase 9 already wrote down in a different form: *if a screen
has no feature test that renders it, it has not been tested.* The corollary this
adds is that a test which renders **any** page is a guard on the document
chrome — so the first assertion in a new screen test should be `assertOk()` on a
real render, before anything about the screen's own data.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest (1573 passed), ESLint, Prettier, vue-tsc,
Vitest (85 passed), Vite build, `platform:openapi --check`. All green.

## 6. Not done, deliberately

- **No country ships with rates.** A new installation charges nothing until
  somebody states a rule, and the empty state says so. Shipping a table of rates
  would be shipping a table that is wrong within a year, in a file nobody
  maintains.
- **No tax id validation.** Checking a VAT number against VIES is a remote call to
  a specific union's service, which makes it an adapter and therefore a module.
  The platform stores the id and the exemption flag; it does not claim to have
  verified either.
- **The rounding setting is stored, and the calculator does not read it.** That is
  on purpose: `Money::percentage()` already rounds half up when a component is
  worked out, and a calculator handed one supply at a time that also rounded the
  total would round twice. Where the cent goes is a decision for whatever composes
  the lines into a document, which is the only code that can see all of them. The
  setting's test pins the stored value, not a behaviour it does not yet drive.
