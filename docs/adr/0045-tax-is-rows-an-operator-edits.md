# 0045 — Tax is rows an operator edits, and core still knows no country's law

Status: accepted
Date: 2026-09-24
Amends: [ADR 0022](0022-risk-and-tax-are-contracts.md)

## Context

ADR 0022 made tax a contract with a dull default, and it was right about the
important half: **core must not implement a country's tax law.** Nobody in this
repository can maintain the VAT rules of twenty-seven member states, the
compounding of GST and PST across Canadian provinces, or US sales-tax nexus, and
a platform that pretended to would be wrong in a way its operator only discovers
from their accountant.

What it got wrong was where the *numbers* live. The flat-rate calculator takes
its rate, name and country from `.env`:

```dotenv
TAX_DRIVER=flat
TAX_RATE=20
TAX_COUNTRY=GB
```

Three consequences, all of which this platform's own owner ran into:

1. **One rate for the whole installation.** A provider selling to customers in
   Turkey, Germany and the United Kingdom has three rates and cannot express
   even the first two.
2. **An operator cannot change it.** A rate change — and rates change, with a
   date — is a deployment. The person who knows the correct rate is the person
   with no shell access.
3. **A module was the only answer to an ordinary question.** "Charge 19% in
   Germany and 20% in the UK" needed somebody to write a package, which is not
   an extension point, it is a wall.

## Decision

**Core holds a table of tax rules an operator edits, and still implements no
country's law.**

`tax_rules` rows say *what to charge where*, and nothing about why:

| Column | What it is |
| --- | --- |
| `name` | what the invoice calls it: `VAT`, `KDV`, `GST`, `PST` |
| `country_code`, `region_code`, `postcode_pattern` | where it applies; each narrower field is optional |
| `rate_bp` | the rate in **basis points**, an integer — 1800 is 18% |
| `level`, `compound` | a second tax on the same supply, and whether it is charged on the first one's amount as well as the base |
| `applies_to` | everything, or only products, domains or addons |
| `customer_kind` | everyone, or only individuals, or only businesses |
| `exempts_validated_business` | a business elsewhere with a tax id accounts for it themselves |
| `starts_on`, `ends_on` | a rate change is a new row |

`tax_settings` holds the handful of installation-wide answers that are not a
rate: whether displayed prices include tax, whether tax is rounded per line or
per invoice, what to call a tax id on a form, and the note to print when an
exemption applies.

`ConfigurableTaxCalculator` implements the **existing** `TaxCalculator` contract
over those rows. ADR 0022's seam is untouched: a module can still replace the
whole calculator with Avalara or TaxJar, and nothing in billing changes.

## Consequences

**The rate is an integer, because a rate is on a monetary path.** Basis points,
not a float and not a decimal string that arrives from a form. The rule holds
1800; `Money::percentage('18.00')` does the arithmetic, which is the one place in
this platform that multiplies money. A float rate would be a float one step away
from a cent.

**Core still knows nothing.** There is no list of countries with their rates, no
EU member-state table, no nexus logic. An operator states the rules for the
places they sell to, in their own words, and the platform applies them in the
order the rows say. If it ships knowing anything about Germany, this ADR has been
broken.

**Matching is most-specific-wins, and it is written down.** A rule with a region
beats one with only a country; a country beats the rule with no country at all;
`priority` breaks a remaining tie. That has to be a rule an operator can predict,
because they will write overlapping rows — a national rate plus one province — and
the platform's answer must be the one they expected.

**Two levels, because that is what compounding needs and no more.** Quebec
charges PST on the GST-inclusive amount and Canada does not; both are two rules
where one is marked compound. A third level has no example anybody asked for, so
it does not exist — and if one arrives, adding it is a migration rather than a
rewrite.

**An exemption is a flag and a sentence, never a lookup.** When a rule exempts
validated businesses, the calculator charges nothing and the invoice says why.
Whether a tax id is genuinely registered is **not** core's answer: validating an
EU VAT number means calling VIES, and ADR 0022's rule stands — core makes no such
call, so an operator's "validated" is what the platform trusts. A module can
bind a real validator behind a contract when somebody wants one.

**A rate change never touches an issued invoice.** An invoice is frozen on issue
(ADR 0023) with `tax_minor`, `tax_rate` per line and a `tax_breakdown` naming
each component. Editing a rule changes what the *next* document says and nothing
that has already been sent. A test asserts it, because the alternative is an
operator correcting a rate in March and altering January's VAT return.

**Rules belong to the seller, not the buyer.** `ResolveSeller` already answers
"who sells to this organization" for document numbering and support departments;
tax asks it the same way. A reseller's customer is taxed by the reseller's rules
when a reseller has any, and by the provider's when they do not.

**The screen is the owner's.** Tax sits in Setup's owner-only section rather than
behind a permission, because getting it wrong misstates a legal document for every
customer at once, and because an Administrator holds every staff permission by
design. If a finance role ever needs it, that is a permission and a decision,
not a default.

## Alternatives rejected

- **Leaving it in `.env`.** One rate per installation, changed by a deployment,
  by the person least likely to know the number.
- **A module per country.** An extension point that everybody has to use for the
  most ordinary requirement there is, is a wall with a door painted on it.
- **Shipping a rate table for the world.** It would be wrong within a quarter,
  and wrong in a way that looks authoritative. Rates are the operator's to state
  and their accountant's to check.
- **A rule engine with expressions.** Enough rope to express any jurisdiction and
  enough to express nonsense, in a screen no operator could read back. Four
  matching columns and two levels cover every example anybody actually gave.
