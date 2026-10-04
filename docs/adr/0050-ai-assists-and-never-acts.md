# 0050 — AI assists, never acts, and a provider is a module

Status: accepted
Date: 2026-10-04

## Context

The owner asked for AI on the support side. The obvious shape — a model that
reads a ticket and answers the customer — is also the one this product cannot
ship, and the reason is not caution about models.

Every adapter in this platform so far reads a machine. A monitoring adapter
that is wrong reports a wrong number. A hypervisor adapter that is wrong fails
to start a virtual machine. **An AI provider is the first one where being wrong
is a sentence in the seller's name, sent to their customer** — about a
suspension, a refund, an outage or a price.

This product has refused to make that kind of statement on a seller's behalf
eight times already, and always for the same reason. Core ships no tax rates
(ADR 0045), because a rate is a country's law and a seller's registration. Core
computes no SLA credit percentage, because an SLA is a contract it has never
read. Core ships no alert rules and no dunning constants, because an
installation that woke somebody at three in the morning over a threshold
nobody chose is an installation whose alerts get turned off in a fortnight. A
model answering a customer directly is the same decision with a larger blast
radius.

There is a second fact, quieter and at least as important. **An AI provider is
a third party, and a ticket is a customer's words.** Sending a thread to a
vendor sends something a customer wrote to a company the customer has no
contract with. Core cannot make that choice for a seller, and a platform that
made it silently would be making it for every seller at once.

## Decision

**AI assists a person and never acts.** Every feature produces a draft that
lands where the operator was going to type anyway, and a human presses send.
There is no setting that changes this.

**A provider is a module, and the contract is in `app/Domain`.** `AiProvider`
has one method: a prompt built from named fields in, a completion and its cost
out. No streaming, no tool calling, no conversation state — a draft is one
question and one answer, and a seam carrying a conversation is a seam with a
session in it. Core names no vendor and ships no default, the same way it ships
no gateway and no registrar.

The address and the model are module configuration; the key is in the vault
and read at the moment of the call, so a rotation takes effect without
restarting a queue. That is `monitoring-prometheus`'s shape and there is no
reason to invent a second one.

**Off until an operator turns it on**, per installation, behind a screen that
says in plain words what leaves and to whom.

**The prompt is assembled from named fields**, never from a model's
`toArray()`. `SecretRedactor` is the net, not the plan (non-negotiable 7). A
ticket's subject and replies, the customer's display name, the product name —
and nothing else. Never a secret, a card, a token, a password or a postal
address, and a test asserts what the builder emits.

**Every call writes a usage row**: who asked, which feature, which model, how
many tokens, what it cost. **Not what it said.** A draft nobody sent is not a
record worth keeping, and a draft that was sent is already the reply — storing
both would be keeping a customer's correspondence twice, once in a table nobody
thinks of as correspondence.

**A provider that is down is not an outage.** The button fails, says so, and
the operator writes the reply themselves. This is `Entitlements`' rule:
a seam whose failure is an outage is a seam built wrong.

## Consequences

**The features are smaller than "an AI support agent" and that is the
product.** Drafting a reply, summarising a thread for whoever picks it up,
suggesting a department on an unrouted ticket, drafting an incident update.
Each one removes typing and leaves every decision where it was.

**Nothing becomes automatic later by accident.** There is no code path from a
completion to a customer, so adding one would be a visible change to this
decision rather than a configuration somebody flips.

**An AI draft is distinguishable from a person's words, always.** It is marked
in the box with the model that produced it, and what finally goes out is what
the operator left there.

**The adapters are unproven, like every other adapter in this repository.** The
release checklist's standing caveat covers them: a request shape tested against
faked HTTP proves the code and not the integration.

**If a seller wants a model to answer customers directly, this is not the
product for it, and that is deliberate.** The honest escape is a module that
registers its own automation — not a core feature, and not a default.

## Alternatives rejected

**A model that replies to customers under a confidence threshold.** The
threshold would be core deciding when a wrong answer in a seller's name is
acceptable, which is exactly the decision tax, SLA credits and dunning all
refused. And the first bad reply is not recoverable by lowering it afterwards.

**An AI provider in core rather than a module.** The day a vendor changes a
field, a core release is the wrong unit of shipping — the reasoning that moved
Stripe, cPanel and Namecheap out.

**Storing every completion.** It reads as prudent and is the opposite: it is a
second copy of customers' correspondence in a table nobody treats as
correspondence, retained under no policy, exported by no erasure request.

**On by default with a redaction pass.** Redaction is a net, and a net is not
consent. The question "may a customer's words go to this vendor" has an owner,
and it is not this repository.
