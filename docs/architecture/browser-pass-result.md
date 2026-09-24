# The First Browser Pass — Result

Status: complete
Date: 2026-09-24

`CLAUDE.md` has said since Phase 17 that **no screen had ever been driven in a
browser**. This is that pass: the admin area and the storefront, opened, clicked
and read, against the development installation with its real data.

Seven bugs, none of which any of the 1608 tests could see. Six of the seven are
the same shape — *something the tests never look at, because a test asserts
behaviour and these are all about what a human reads.*

---

## 1. What was found, and fixed

**A customer's tax id travelled in a URL.** The Tax screen's "Try it" panel is an
`Inertia::optional` prop, which makes it a GET — so everything it asked went into
the address bar, the browser history and the server's access log, including the
tax id typed into it. The calculator never validates an id and could not, since
that means calling a country's own service; it only ever checks that one exists.
So the field is now a checkbox — *whether* one was given, never which — and the
query string carries `has_tax_id=0` instead. This is the finding the pass paid
for: no test would ever have looked at the address bar.

**Two save buttons were labelled with the heading above them.** The Tax screen's
settings button said "How tax behaves" and the Billing screen's said "Billing
terms". `find` had to guess which one saved the form. The tax one also stretched
to the full width of the panel, because an `AppButton` is a direct child of a
`flex flex-col` unless it is wrapped.

**Three screens printed their own translation keys at an operator.** The
Automation screen showed cards titled `automation.tasks.webhooks.label` and
`automation.tasks.licence.label`; System health showed `health.checks.licence`;
Connect showed `branding.remove_vendor_mark` beside an "Allowed" badge. The first
three had no `lang` entry in either language. The fourth had one all along —
`Feature::labelKey()` knows to underscore a dotted slug — and the controller sent
the raw `$feature->value` instead of calling it. That is exactly the trap
`CLAUDE.md` documents for permission slugs, arriving through a different door.

**Every customer's node in the Resource Graph was called "Customer".** Not a
label bug: `CreateCustomer` names the organization `companyName ?? legalName ??
'Customer'`, and an individual signing up with no company has neither. Four
identical rows in an impact view tell an operator nothing. A graph node carries a
*cached label* (ADR 0043), so the projection now caches
`Customer::displayName()` — which falls through company, legal name, then the
primary contact — eager-loaded with `displayNameWith()`, never `with('customer')`
alone.

## 2. The guard that stops the class recurring

`tests/Feature/VocabularyTest.php` walks every `AutomationTask`, every registered
health check and every licensing `Feature`, in **both** shipped locales, and
fails when `__()` hands back the key it was given.

`__()` returning the key is the designed symptom — a visible
`automation.tasks.webhooks.label` is a bug report from the page itself, and blank
space is a bug nobody files. That only works if somebody is looking, and for
months nobody was. Now a test is.

Two details in it are worth keeping. The health checks are asked of **the
registry** rather than listed by hand, because a check added to the container and
nowhere else is exactly the one that would go unnamed — `licence` was. And the
health part is a test body rather than a dataset, because a Pest dataset is built
**before the application boots**, so `app(...)` in one resolves against a
container whose providers have not registered.

## 3. A mistake made while fixing, and caught by looking again

The first fix for the missing wording wrote `ü` and `’` **literally**
into the language files — a Python escaping slip — so the Automation screen went
from showing a translation key to showing `Müşterinin`. Every gate
stayed green, because a string is a string.

Re-opening the page is what found it. The same slip had reached five source
comments and was swept out with it.

## 4. Two false alarms, both mine

`/admin/resources/explorer` and `/plans` both answered 404, and both were URLs
**I** guessed rather than links the product offers: the Explorer is at
`/admin/resources` and the storefront's Plans link points at `/store`. Checking
the router before reporting is the difference between a finding and noise.

## 5. What was driven, not merely rendered

Setup, Tax (a rule added, the preview run), Billing terms (terms saved, numbering
read back), Infrastructure, Explorer, Adapters, Telemetry, Automation (the
Resource graph task run — 14 examined, 7 changed), Clients, Invoices, Orders,
Services, Domains, Support, Reports, Currencies, Modules, Licence, Health,
Connect, Import, Operations, Settings, the storefront, a product page and the
cart.

## 6. Gates

Pint, Rector, PHPStan level 8, Pest (1623 passed), ESLint, Prettier, vue-tsc,
Vitest (85 passed), Vite build, `platform:openapi --check`. All green, and every
fix re-checked in the browser afterwards.

## 7. Still not done

The provider adapters remain the other standing gap: Stripe, PayPal, cPanel and
Namecheap have still never spoken to their real providers, and neither has any of
handoff #2's adapter families. A browser proves the screens; only a real
provider proves an adapter.
