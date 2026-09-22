# 0022 — Risk and tax are contracts, with dull defaults

Status: accepted
Date: 2026-09-25

## Context

Two things every hosting platform needs, and neither belongs in core.

**Tax** rules are per jurisdiction, change on political timescales, and are
wrong in ways that are expensive. A platform that hardcodes one country's
VAT rules is a platform that cannot be sold into the next country without
editing billing.

**Fraud screening** is a vendor market. Naming one in the ordering path
means every installation carries that vendor's opinion, and replacing it is
surgery on the code that takes people's money.

## Decision

Both are contracts in `App\Domain`, chosen by configuration:

```php
interface TaxCalculator { public function calculate(TaxableSupply $supply): TaxResult; }
interface RiskEvaluator { public function evaluate(RiskSubject $subject): RiskAssessment; }
```

Core ships defaults that do as little as possible:

- `NoTaxCalculator` charges nothing. An installation that has not been told
  its tax rules must not guess at them, and an operator choosing this is
  choosing to handle tax outside the platform — which is a real answer.
- `FlatRateTaxCalculator` applies one configured rate with one configured
  name. Enough for a single-country installation, and it knows nothing about
  any jurisdiction: the rate, the name and the one exemption rule are all
  configuration.
- `RuleBasedRiskEvaluator` reads signals the platform already holds — order
  value, account age, velocity, billing/request country mismatch — and
  **holds rather than refuses**. Nothing denies outright unless an operator
  sets a deny threshold, because an installation that has not tuned its
  rules should not be silently turning customers away.

`RiskSubject` is a flat value object rather than the order model. An
evaluator from a module is handed a stated set of facts instead of the whole
database through a relation, and the facts a decision was made on are then
exactly the facts that can be recorded beside it.

Risk reasons are codes, not sentences: the message an operator reads is a
translation, so a reason recorded a year ago still reads in their language.
**Reasons never reach the customer.** A rejection that names the rule it
tripped is a tuning guide for whoever tripped it.

## Consequences

- Adding a jurisdiction or a fraud vendor is a module, not an edit to
  ordering.
- A tax result carries named components rather than one rate, so a place
  with two taxes on one line is expressible and an invoice can name each.
- A zero result carries a reason, because "no tax" and "reverse charge
  because the customer gave a valid registration number" look identical on a
  total and are completely different on an invoice.
- The defaults are boring on purpose. An installation that never configures
  either behaves predictably: no tax, and every order held only when the
  rules an operator actually set say so.
