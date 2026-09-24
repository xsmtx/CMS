# Integration Modules — Plan

Status: planned
Date: 2026-09-24
Follows: `modules-and-marketplace-plan.md`
ADRs: [0038](../adr/0038-a-module-may-execute.md),
[0039](../adr/0039-the-sdk-is-platform-contracts.md),
[0047](../adr/0047-a-package-is-verified-before-it-touches-disk.md)

Twenty-four integrations were asked for, all official, all delivered through the
marketplace. This is what each one needs before it can exist.

The answer is not uniform, and that is the useful part: **thirteen of them plug
into a seam that exists today and can be written now. Eleven cannot, because the
seam they need is not there** — and writing them anyway would mean either a
module that registers nothing, or core growing a special case per provider, which
is the thing the SDK exists to prevent.

---

## 1. Ready today — the seam exists

| Integration | Slug | Contract | Registered by |
| --- | --- | --- | --- |
| cPanel | `provisioning-cpanel` | `ProvisioningModule` | `key()` |
| DirectAdmin | `provisioning-directadmin` | `ProvisioningModule` | `key()` |
| Plesk | `provisioning-plesk` | `ProvisioningModule` | `key()` |
| Proxmox VE | `provisioning-proxmox` | `ProvisioningModule` | `key()` |
| SolusVM | `provisioning-solusvm` | `ProvisioningModule` | `key()` |
| VMware ESXi | `provisioning-esxi` | `ProvisioningModule` | `key()` |
| DigitalOcean | `provisioning-digitalocean` | `ProvisioningModule` | `key()` |
| Hetzner Cloud | `provisioning-hetzner` | `ProvisioningModule` | `key()` |
| TeamSpeak | `provisioning-teamspeak` | `ProvisioningModule` | `key()` |
| Stripe | `gateway-stripe` | `PaymentGateway` | `key()` |
| PayPal | `gateway-paypal` | `PaymentGateway` | `key()` |
| İyzico | `gateway-iyzico` | `PaymentGateway` | `key()` |
| PayTR | `gateway-paytr` | `PaymentGateway` | `key()` |
| Namecheap | `registrar-namecheap` | `DomainRegistrar` | `key()` |
| Realtime Register | `registrar-realtime` | `DomainRegistrar` | `key()` |

Three of those — Stripe, cPanel, Namecheap — already exist **inside core** and
move out rather than being written. That move is described in
`modules-and-marketplace-plan.md` §2 and carries an upgrade path: an installation
crossing the release must find its gateway still working.

These registries key on a free-form `key()`, so any number of providers coexist.
That is what makes this column easy.

## 2. Blocked — and precisely on what

### 2.1 The notification channel vocabulary is closed

`NotificationChannel` is an enum with three members — mail, database, webhook —
and `ChannelRegistry` keys on `channel()->value`. Two consequences, both fatal to
four of the requested integrations:

- **SMS has no member.** Netgsm cannot say what it is. And
  `NotificationRecipient` carries `email` and nothing else, so even with a member
  there is no address to send to: a phone number is not an email, and
  `isAddressable()` would have nothing to check.
- **One implementation per channel.** Discord, Slack and Mattermost are all
  webhook-shaped. Registering them as `Webhook` means each overwrites the last,
  and it would also overwrite core's own `WebhookChannel`, which is the operator's
  own systems listening.

What it needs, and this is an SDK change:

1. `NotificationChannel` gains `Sms` and `Chat`, or becomes an open vocabulary
   the way `ResourceKind` is (ADR 0043) — core must know which channels it
   *guarantees*, so the closed enum is probably right and the members should be
   added deliberately.
2. `ChannelRegistry` keys on a provider key **within** a channel, so "the SMS
   channel, delivered by Netgsm" is expressible and two chat providers can both
   be installed.
3. `NotificationRecipient` gains an address per channel rather than one `email`,
   and `ResolveRecipients` learns to fill it.
4. Per-channel opt-out already exists and needs nothing.

Affected: **Netgsm SMS**, **Discord**, **Slack**, **Mattermost**.

### 2.2 Seams that do not exist at all

| Integration | Needs | Where it belongs |
| --- | --- | --- |
| DNS Manager | A `DnsProvider` contract — zones and records as value objects, plus a core screen | `app/Domain/Dns`, planned as the `dns` module in handoff #2 phase E |
| IP Manager | An IPAM contract and prefix/assignment rows | planned as `ipam`, phase C — it is explicitly a core contract plus a module |
| GoGetSSL | A `CertificateAuthority` contract: order, validate, reissue, expiry | planned as `certificates`, phase E |
| Social Media Login | An identity-provider extension point. **Nothing like it exists**: the SDK offers no way to add an authentication method, and it must not be a module that registers middleware (ADR 0039) | new, and it deserves its own ADR |
| tawk.to | A client-side script slot. `Widget` is *data core renders*; a live-chat embed is a third-party script tag on the client area, which is a Content Security Policy decision before it is a module one | new, and CSP is why it is not trivial |
| Email Template | Nothing — **core already has this.** `NotificationTemplate`, per locale, per event, editable on Setup → Notification templates | already shipped |

**Email Template is listed as done rather than planned.** Building a module for it
would be building a second answer to a question core already answers, and two
places that can define what a customer is sent is one too many.

**Social Media Login and tawk.to are the two that should be thought about before
they are built.** A login provider changes who can get in; a chat widget puts
somebody else's JavaScript on a page where a customer types their billing
address, and the CSP is currently `script-src 'self'` with no exceptions — on
purpose. Both are possible; neither is a module to knock out in an afternoon, and
saying so now is cheaper than discovering it halfway.

## 3. What every one of these modules is, and is not

**It is an adapter behind a contract.** It takes a value object and returns one,
and it never touches the database (ADR 0026). A module cannot register
middleware, replace a binding, or reach a facade — the SDK never offers the
chance (ADR 0039).

**It declares its configuration and never renders it.** Credentials live in the
module's own config, which the presenter masks and which never leaves
`ModuleContext` — better than the environment file the three core adapters use
today, which is read by everything.

**It is provisionally wrong until it has talked to its provider.** Every adapter
in this product has been written against documented APIs and faked HTTP, which
proves the code and not the integration. Each module's README says so in its own
words, and `docs/operations/release-checklist.md` already says the first real
deployment must treat each adapter as unproven. Twenty-four more of those is
twenty-four more things to prove, not twenty-four things that work.

**It is signed by the vendor.** The marketplace lists only official packages
(ADR 0047); `php artisan platform:package <slug> --key=…` is what produces one.

## 4. Sequencing

1. **The nine provisioning modules, four gateways and two registrars** — the
   fifteen in §1, including the three extracted from core.
2. **The notification SDK change** in §2.1, then Netgsm, Discord, Slack and
   Mattermost — four modules that become trivial once the vocabulary can express
   them.
3. **DNS, IPAM and certificates**, in their handoff #2 phases, where they were
   already planned.
4. **Social login and live chat**, each with the decision it needs first.
