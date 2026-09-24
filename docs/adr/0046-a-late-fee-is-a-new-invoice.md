# 0046 — A late fee is a new invoice

Status: accepted
Date: 2026-09-24
Supersedes nothing. Answers a question `DunningAction` deliberately left open in
Phase 9.

## Context

`DunningAction` shipped with three members — notify, suspend, terminate — and a
comment saying why there was no fourth:

> Three actions, and no late fee. A late fee is money, and money on a frozen
> document (ADR 0023) is a credit note's worth of complexity that needs its own
> decision — whether it is a new invoice or a line on the next one — rather than
> being invented in a phase about scheduling.

The question is now live, because an operator asked for the financial side of the
platform to be configurable per country and a late payment charge is part of the
terms a seller states. Three answers were available.

**A line on the invoice that was late.** What WHMCS does. It cannot be done here:
issuing an invoice freezes it (ADR 0023), every amount on it was fixed the moment
the customer received it, and a document that grows a line after the customer has
filed it is a document whose copies disagree. That is not a technical obstacle to
route around — it is the property that makes the invoice worth anything.

**A line on the next invoice.** Legal, and useless. It arrives at the next
renewal, which may be a month away and may never come at all for a customer whose
services were terminated in the same dunning sequence. A charge that lands weeks
after the behaviour it exists to discourage is a charge that discourages nothing,
and the customer reads a renewal invoice with an unexplained extra line.

**Its own invoice.** Immediately issuable, separately payable, separately
cancellable if the operator decides to waive it, and it carries its own document
number — so an auditor reading the book sees the charge as a charge rather than as
an amount grown onto something else.

## Decision

**A late fee is a new invoice.** `ChargeLateFee` raises a one-line invoice for a
percentage of what is still outstanding on the overdue invoice, issues it
immediately, and records an audit row naming the invoice it was about, the
outstanding amount, the rate and the fee.

Five things follow from it, and each was a decision of its own:

**It is a percentage of what is outstanding, not of the total.** An invoice half
paid is half a debt. Charging interest on money that has already arrived is the
kind of error a customer notices once and remembers for years.

**It is a dunning step, not a setting with its own clock.** A step already
carries `offset_days`, so "charge a fee fourteen days after the due date" needs no
second concept of a grace period, and `invoice_dunning_steps` already remembers
that a step ran against an invoice — which is exactly what stops a nightly sweep
charging the same fee thirty times. A sequence with no fee step is a seller who
charges none, stated the same way a seller who never suspends states that.

**The fee invoice is never itself chased.** `invoices.is_late_fee` exists for one
reason: without it the fee is overdue the day after it is raised, the sweep runs
the whole sequence against it, and the result is a fee on the fee compounding
nightly and a suspend step taking a customer's server down over three euros of
interest. The debt that matters is the original invoice, and that one is still in
the sweep.

**Nothing to charge is not a failure.** No rate, nothing outstanding, or a fee
that rounds to zero on a small balance all return null, and the step is still
recorded as run. Treating "there was nothing to charge" as an error would leave
the step unrecorded and have the sweep ask the same question every night for as
long as the debt lives.

**A fee is not withheld service.** The customer preference the sweep consults for
suspend and terminate is not consulted for a fee. "Never suspend us, call us
instead" is an arrangement about service; reading it here would let it mean "never
charge us interest", which nobody agreed to. Reading the *notice* preference
instead would be worse: opting out of email would opt somebody out of the debt.

**The fee carries no tax.** Whether interest is a taxable supply has a different
answer in nearly every jurisdiction, and guessing at one is precisely what ADR
0045 exists to prevent. An operator who must charge tax on a fee will need a
`TaxAppliesTo` member for it, and that is a change to make when somebody needs
it rather than a rule invented here.

## Consequences

- A customer who pays late receives two documents. That is the honest shape of
  what happened and it is what the audit trail can explain.
- Waiving a fee is cancelling an invoice, which the platform already does and
  already records. There is no separate "remove the fee" path to build.
- Reporting sees fee revenue as invoice revenue from a document with no service
  behind it. `is_late_fee` is the column a future report groups on.
- The fee rate lives in `billing_settings` next to `due_days`: both are terms a
  seller states, both differ by country, and both were `.env` constants before.
