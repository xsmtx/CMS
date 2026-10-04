# AI assistance and MCP

Status: complete
Date: 2026-10-04
Decides: ADR 0050 (AI assists, never acts), ADR 0051 (MCP is a third surface)
Asked for: the owner, outside the lettered roadmap

---

## 1. Two requests, two directions, two decisions

"Add MCP, and AI could be used on the support side" is two things that sound
like one, and building them as one would be the mistake. They point in
opposite directions:

- **AI assistance** is this platform calling *out* to a model. The platform is
  the client, a customer's words are the payload, and the risk is what leaves
  the installation.
- **MCP** is this platform answering *in* to a model somebody else is driving.
  The platform is the server, its own data is the payload, and the risk is what
  an inference can reach.

One is a **provider** question — and this product has answered provider
questions the same way eleven times: a contract in `app/Domain`, an adapter in
a module, a credential in the vault, nothing in core that names a vendor.

The other is a **surface** question, and this product has answered that twice
already: ADR 0033 for the API and ADR 0049 for the staff API. A surface is not
a system. It authenticates, puts somebody on a guard, and calls the use case a
screen would have called.

---

## 2. What makes this different from every other provider

Every other adapter in this product reads a machine. A monitoring adapter that
is wrong reports a wrong number; a hypervisor adapter that is wrong fails to
start a VM. **An AI provider is the first one where being wrong is a sentence
in the seller's name, sent to their customer.**

That single fact decides nearly everything below. It is why nothing here is
automatic, why it is off until an operator turns it on, and why the output
lands in a text box rather than in an outbox.

The second fact is quieter and matters as much: **an AI provider is a third
party, and a ticket is a customer's words.** Sending a thread to a vendor is
sending something a customer wrote to a company the customer has no contract
with. Core cannot make that decision for a seller, and a platform that made it
silently would be making it for every seller at once.

---

## 3. AI assistance

### 3.1 The seam

`AiProvider` in `app/Domain/Ai/Contracts`, one method: given a prompt built
from named fields, return a completion and what it cost. No streaming, no
tools, no conversation state — a draft is one question and one answer, and a
seam that carried a conversation would be a seam with a session in it.

Adapters are modules (`modules/infracms/ai-anthropic`, `ai-openai`,
`ai-openai-compatible` for somebody running their own). Core names no vendor
and ships no default, exactly as it ships no tax rates.

The address and the model are module configuration; the API key is in the
vault (`SecretStore`), read at the moment of the call so a rotation takes
effect without restarting a queue. That is `monitoring-prometheus`'s shape,
and there is no reason to invent a second one.

### 3.2 What it is used for, and what it is not

| Used for | Why it is safe |
| --- | --- |
| Drafting a reply to a ticket | Lands in the reply box an operator was going to type in. It is a predefined reply that happens to have been written for this ticket. |
| Summarising a long thread for whoever picks it up | Read by staff only; never leaves the installation. |
| Suggesting a department and priority on an unrouted ticket | A suggestion beside the field, never the stored value. |
| Drafting an incident update or a postmortem | A human publishes; §15 already requires that. |

**Not used for**: deciding anything. Not suspending a service, not approving a
change, not closing a ticket, not setting a price, not judging an abuse report,
not choosing a placement. Every one of those has a record and a person's name
on it, and this product has spent nine phases making sure of that.

### 3.3 The rules the implementation has to carry

- **A draft is a draft.** It goes into the reply box, marked, with the model
  that produced it named. The operator edits and sends — or does not.
- **Nothing is sent to a customer that a person did not press send on.** There
  is no setting that turns this off, and there will not be one.
- **The prompt is assembled from named fields**, never from a model's
  `toArray()`. `SecretRedactor` is the net (rule 7), not the plan. A ticket's
  subject and replies, the customer's display name, the service's product
  name — and nothing else.
- **Never a secret, a card, a token, a password or an address.** A test asserts
  what the builder emits.
- **Off until an operator turns it on**, per installation, with a screen that
  says in plain words what leaves and to whom.
- **Every call writes a row**: who asked, which feature, which model, how many
  tokens, what it cost. Not what it said — a draft nobody sent is not a record
  worth keeping, and a draft that was sent is already the reply.
- **A provider that is down is not an outage.** The button fails, says so, and
  the operator types the reply themselves. The `Entitlements` rule: a seam
  whose failure is an outage is a seam built wrong.

---

## 4. MCP

### 4.1 It is a surface

`POST /mcp`, JSON-RPC 2.0, the `initialize` / `tools/list` / `tools/call`
subset. Written here rather than pulled in: the subset is small, and a
dependency that speaks a protocol on this platform's behalf is a dependency
that decides what the platform exposes.

Authentication is a **staff API token** — the one ADR 0049 just defined, with
`StaffApiScope`, an expiry, a device and a revocation. There is no second
token kind. Every tool declares the scope it needs, and `RequireStaffApiScope`'s
two questions are asked unchanged: does the token carry it, and does the holder
hold the permissions behind it.

### 4.2 Read only, and that is the decision

ADR 0049 said some actions are web-only because a client can be rewritten. MCP
is the case where that reasoning is strongest, and it goes further:

**Every MCP tool is a `:read` scope. There are no write tools.**

An API endpoint is called by a program somebody wrote: the decision to call it
was made once, by a person, at the keyboard. An MCP tool is called by a model
that inferred it should. Those are not the same kind of event, and the
difference is exactly the one this product has been drawing since the abuse
desk: a person pressing a button chose, and everything else did not.

So an operator can ask their assistant "which services are unprotected", "what
is open on web-3", "which customers were affected by INC-000014" — and cannot
ask it to reply to a ticket, resolve an incident, approve a change or drain a
backend. Those stay where somebody presses something.

The tool catalogue is a registry with a test behind it, the shape
`StaffApiSurfaceTest` already has: every tool names a real `StaffApiScope`, every
scope is a read one, and no tool's name contains a word from the forbidden list.

### 4.3 What it exposes

The staff API's reads, as tools with descriptions a model can act on: alerts,
incidents, tickets, services, customers, the backup coverage, the resource
lookup, the reconciliation findings, the leakage findings. Each one calls the
same application service the screen calls.

**The boundary is unchanged.** A reseller's token reaches a reseller's rows,
because `AuthenticateStaffApiToken` calls the same boundary middleware
everything else does. Nothing about MCP is allowed to be the one surface that
resolves identity differently — that divergence is how an API becomes the way
in, and it would be worse here.

---

## 4a. Where it stands (2026-10-04)

All six steps are in.

| Step | State |
| --- | --- |
| 1. ADR 0050 and ADR 0051 | **in** |
| 2. The AI seam, the vault key, the usage row, one module | **in** — `modules/infracms/ai-anthropic` |
| 3. Drafting a ticket reply | **in**, with the settings screen that turns it on |
| 4. Summarising a thread, triage, incident drafts | **in** — all four features are wired, and a test refuses a fifth that is offered and unread |
| 5. The MCP server | **in** — ten read tools, `McpSurfaceTest` keeps them read |
| 6. `platform:openapi` unaffected | **in** — and a test confirms MCP stays out of it |

---

## 5. Sequence

1. ADR 0050 and ADR 0051.
2. The AI seam: contract, `AiSettings`, the vault key, the usage row, and one
   module adapter. No screen yet.
3. Drafting a ticket reply, on the screen an operator already uses.
4. Summarising a thread, and suggesting a department on an unrouted ticket.
5. The MCP server: transport, the tool registry, the read tools, the guard
   test.
6. `php artisan platform:openapi` is unaffected — MCP is not REST and does not
   belong in that document. Its catalogue is `tools/list`, which is the
   protocol's own answer to the same question.

---

## 6. Not in this

**Agentic anything.** No tool-calling loop inside the platform, no model that
can call the platform's own writes, no "AI operator". The product's whole
argument is that a record says who did something.

**Training on customer data.** Nothing here sends anything anywhere to be
learned from. A provider's terms are the operator's to read; core's job is to
make what leaves small, named and visible.

**A chat window.** The assistant lives where the work is — the reply box, the
incident update, the ticket list — rather than in a box beside it. A chat
window would be a second place to do everything, and this product does not have
second places.

**An AI module in the marketplace catalogue**, until one has answered a real
provider. The standing caveat applies to these adapters exactly as it applies
to Stripe and cPanel.
