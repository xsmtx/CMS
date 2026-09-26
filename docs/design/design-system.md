# InfraCMS design system

The contract between the product and anything that themes it.

**Sources of record, in order.** `DESIGN.md` at the repository root is the
**visual language** (`Apple-design-analysis`): it holds the palette, the type
scale, the radii, the spacing ladder and the component specifications, and
every value in `resources/css/app.css` is one of its or a derivation from one
of its, with the derivation written beside it.
`INFRACMS_UI_UX_THEME_HANDOFF_3.md` remains the record for **structure** —
which tokens exist, what a theme may override, what a screen owes an
operator. Where the two disagree about a *value*, DESIGN.md wins; where they
disagree about a *name*, the handoff wins, because a token name is an API
somebody else's theme is written against.

Where this document says *more* than either, it is recording a decision they
left open — each of those says so.

**DESIGN.md has two registers and both are used.** Its marketing members
(`hero-display` 56, `display-lg` 40, `lead` 28) build the storefront, where a
tile occupies roughly one viewport. Its utility members (`caption` 14,
`fine-print` 12, `micro-legal` 10, `button-utility`, `button-dark-utility`)
build the console, where a screen carries forty controls. Both are that
document's; the mapping between surface and register is this one's, and it is
in §3 and §4.

**How to apply it:** the Claude Code skills in `.claude/skills/` —
`enterprise-design-system`, `enterprise-cms-ux`, `frontend-architecture`,
`visual-quality-review`, `accessibility`, `responsive-enterprise-ui` — turn
this document into working rules and a review workflow. Read them before any
frontend change; `docs/design/audit-2026-09-24.md` records why they exist.

Related: [ADR 0040 — The UI is primitives, not a component library](../adr/0040-the-ui-is-primitives.md),
[ADR 0048 — Sections, not cards; one status vocabulary](../adr/0048-sections-not-cards-and-one-status-vocabulary.md),
[ADR 0036 — A brand is a row](../adr/0036-a-brand-is-a-row.md),
[ADR 0037 — A theme is a package and may not execute](../adr/0037-a-theme-is-a-package-and-may-not-execute.md).

---

## 1. The two spellings

**The custom properties are the API.** A theme package overrides
`--surface-primary`; a brand overrides `--brand-primary`; a third-party theme
written against the handoff finds the names the handoff printed. Renaming one
is a breaking change for code this repository does not contain — the same
rule the module SDK lives under.

Tailwind's colour names sit on top, in `@theme`, and are shorter where
Tailwind's own prefix already names the family. Markup reads
`text-content-muted`, not `text-text-secondary`. One vocabulary, two
spellings, and this is the table:

| Canonical token (the API) | Tailwind colour | Used in markup as |
| --- | --- | --- |
| `--background` | `background` | `bg-background` |
| `--background-subtle` | `background-subtle` | `bg-background-subtle` |
| `--surface-primary` | `surface-primary` | `bg-surface-primary` |
| `--surface-secondary` | `surface-secondary` | `bg-surface-secondary` |
| `--surface-elevated` | `surface-elevated` | `bg-surface-elevated` |
| `--surface-hover` | `surface-hover` | `hover:bg-surface-hover` |
| `--surface-selected` | `surface-selected` | `bg-surface-selected` |
| `--surface-chrome` † | `surface-chrome` | `bg-surface-chrome` |
| `--border-default` | `line` | `border-line`, `divide-line` |
| `--border-subtle` | `line-subtle` | `border-line-subtle` |
| `--border-strong` | `line-strong` | `border-line-strong` |
| `--text-primary` | `content` | `text-content` |
| `--text-secondary` | `content-muted` | `text-content-muted` |
| `--text-muted` | `content-subtle` | `text-content-subtle` |
| `--text-inverse` | `content-inverse` | `text-content-inverse` |
| `--brand-primary` | `brand` | `bg-brand`, `text-brand` |
| `--brand-primary-hover` | `brand-hover` | `hover:bg-brand-hover` |
| `--brand-accent` | `accent` | `bg-accent` |
| `--status-success` | `success` | `text-success` |
| `--status-warning` | `warning` | `text-warning` |
| `--status-danger` | `danger` | `text-danger` |
| `--status-info` | `info` | `text-info` |
| `--status-maintenance` | `maintenance` | `text-maintenance` |
| `--status-unknown` | `unknown` | `text-unknown` |
| `--accent-billing` … `--accent-storage` | `billing` … `storage` | `text-automation` |
| `--focus-ring` | — | `outline` in `:focus-visible` |
| `--radius-badge/control/card/modal/pill` | `--radius-sm/md/lg/xl/full` | `rounded-sm` (5) · `rounded-md` (8) · `rounded-lg` (11) · `rounded-xl` (18) · `rounded-full` |
| `--panel-surface`, `--panel-blur` | — | `.floating` — every menu, dialog, drawer and the palette |
| `--shadow-product` | — | `.product-shadow` — storefront product imagery, and nothing else |
| `--control-h`, `--control-h-sm` | — | `h-(--control-h)` — every button, input and select |
| `--row-y` | — | table cell padding (automatic on `.data-table` cells) |
| `--space-1` … `--space-20` | — | DESIGN.md's 4/8/12/17/24/32/48/80 ladder, plus 20/40/64 which it does not name |

† **`--surface-chrome` is an addition** beyond the handoff's required
families, and it is `surface-black` in both appearances: DESIGN.md's
`global-nav` is black with on-dark text, and it is the first thing that makes
a page read as this language.

**Everything inside the chrome re-reads the tokens**, through `.on-chrome`.
That class redefines the same names for its own subtree, so `text-content`
and `border-line` keep working and no markup inside the rail knows it is on
black — one class instead of forty conditional utilities.

It redefines **both spellings**, and that is the only half that works: `@theme`
declares `--color-content: var(--text-primary)` on `:root`, and a custom
property whose value contains `var()` is substituted where it is *declared*,
not where it is used. Overriding `--text-primary` on a descendant therefore
changes nothing a Tailwind utility reads. The breadcrumb sat at 1.24:1 on the
black bar until axe counted it on seventy-seven renders.

### Rules

- A component **never** holds a colour. If a hex appears outside
  `app.css` or a theme's `tokens.css`, it is a bug.
- A brand overrides `--brand-primary` and `--text-inverse`. The platform
  **derives** the hover and the selected tint from it — overriding one
  without the others is half a rebrand.
- `--focus-ring` is its own token so it survives a rebrand. A brand that
  set an accent nobody can see against the page would otherwise take the
  focus ring with it.
- Operational semantics are **not** rebrandable. Success is green, danger is
  red, and a white-label installation does not get to swap them (§12).

---

## 2. Status is never colour alone

Handoff §7, and the rule this system is most emphatic about. Roughly one man
in twelve cannot tell this product's green from its amber; a hosting panel is
read on projectors, through remote sessions that crush colour, and by people
who are tired at three in the morning.

Every status carries **shape + text + colour**:

| Tone | Mark | Token | Means |
| --- | --- | --- | --- |
| healthy | `●` | `--status-success` | Working. |
| warning | `▲` | `--status-warning` | Working, and about to stop. |
| critical | `■` | `--status-danger` | Not working. |
| maintenance | `◆` | `--status-maintenance` | We took it down on purpose. |
| unknown | `○` | `--status-unknown` | The check did not answer. |

Two tones were added with the enterprise pass: `info` (◐ — moving: running,
provisioning, retrying) and `neutral` (□ — out of play: closed, cancelled,
archived, refunded).

**One vocabulary.** A status *word* is mapped to a tone in exactly one place,
`resources/js/status.ts` (`statusTone()`, plus `httpTone()` for response
codes). Pages never carry their own `tone()` function: 25 of them did, and
they disagreed — a closed customer was red and a closed ticket grey, an
expired domain red on its list and amber on its page. A word nobody mapped
renders as `unknown`, visibly. Add words by meaning, not by the colour you
want.

`AppStatus` is the component; `AppBadge` is for labels that are not a status
(tags, "Primary", a type). **Maintenance and unknown are statuses, not
shades of warning** — those two are the things an operator most needs to tell
apart from "it is broken", and every panel that coloured them amber taught
its users to ignore amber.

---

## 3. Type

The system face, and seven sizes: five for the console and two more the
storefront uses. Every one is a DESIGN.md member.

| Token | Size | DESIGN.md member | For |
| --- | --- | --- | --- |
| `text-label` | 10px, +0.06em | `micro-legal` | Column heads and micro-labels, uppercase. |
| `text-chrome` | 12px, −0.01em | `fine-print` | Secondary lines, help text, badges, footers. |
| `text-body` | 14px, −0.016em | `caption` | The working size. `<body>` is set to it. |
| `text-title` | 17px, −0.022em | `body-strong` | Card and section headings. |
| `text-page` | 28px, −0.022em | `lead` | One per screen. |
| `text-display` | 40px | `display-lg` | Storefront tile headings. |
| `text-hero` | 56px | `hero-display` | The storefront hero, once. |

Tracking is negative and that is not a preference: every SF Pro Text member
in DESIGN.md carries it, and it is what stops 14px reading loose.

**A badge is `fine-print`, not `micro-legal`.** The 10px member is what that
document spends on the legal line at the foot of a page. A badge is a word
somebody reads to know what a row is, and at 10px on a 14% tint of its own
colour it measures 4.19:1, which fails AA — which is how it was found.

**The console does not use the marketing register.** A 28px page heading is
`lead`; a 56px one would be a gallery where an operator wants a list.

**Tailwind's own sizes are not used.** `text-xs`/`text-sm` appeared 578 times
before the enterprise pass, and `text-sm` is 14px — larger than body — so
labels outranked the text they labelled. They were replaced with
`text-chrome`/`text-body`; `text-base`/`xl`/`2xl` remain only on the client
area and auth screens, pending their own pass. Weights: 500 and 600; no bold.

Monospace (`font-mono`) is for technical identifiers only: IP, CIDR, MAC,
ASN, UUID, invoice number, config. Not for prose and not for ordinary
numbers — those get `tabular-nums`, which is the `.numeric` cell on a table.

---

## 4. Geometry, space, density

Radii are DESIGN.md's ladder: badges 5 (`xs`), controls and inputs 8
(`sm`), cards 11 (`md`), dialogs 18 (`lg`), and the pill.

**The system is mixed on purpose and the rule is written down**, because
DESIGN.md's own is: `button-primary` and `button-secondary-pill` are pills,
`button-dark-utility` is 8px. A pill is the thing the page is asking you to
do; the 8px square is a control in a row of controls. So primary and danger
buttons are pills, every other button keeps the control radius that the input
beside it has, and a badge is still not a pill — a pill-shaped badge in a
table cell reads as decoration, which is what a status must not be.

**Elevation is not a shadow.** DESIGN.md allows exactly one drop-shadow in
the whole system and spends it on product photography
(`--shadow-product`, storefront only). In the interface, elevation is the
surface changing colour, and a floating layer — menu, dialog, drawer,
palette — is *frosted*: `.floating` puts the parchment at 80% behind a
`blur(20px)`, with a solid fallback under `prefers-reduced-transparency`,
because that preference is somebody saying translucency makes text hard to
read and a menu over a table is where that bites.

Spacing is the handoff's scale — 4, 8, 12, 16, 20, 24, 32, 40, 48, 64 — as
`--space-1` … `--space-16`.

**Containers: sections, not cards.** A page is Page → Section → Content. The
default grouping is `DetailSection` — a heading and a hairline, no box. A
framed surface (`rounded-lg border bg-surface-primary`) is reserved for things
that are objects: a table, a `MetricStrip`, the danger zone, a filter panel.
One framed surface deep, at most. In-flow surfaces carry no shadow; only
floating layers (menus, dialogs, drawers) do. (ADR 0048.)

**Controls share one height.** `--control-h` (32px) / `--control-h-sm` (28px)
set the height of every button, input, select, `SearchInput` and
`FilterSelect`, so a row of mixed controls lines up because it reads one
number. Before this, inputs were ~42px and buttons ~38px.

Density is `data-density` on the root, and it moves four measurements: row
padding, control height, control padding, card padding.

| Preset | Theme | Row padding | Control height |
| --- | --- | --- | --- |
| `compact` | NOC | 5px | 28px |
| *(default)* | Core | 8px | 32px |
| `comfortable` | Horizon | 12px | 36px |

Table cells take their padding from `--row-y` through a zero-specificity
`:where()` rule, so a cell written today carries no `px-*`/`py-*` classes and
follows the density; older cells that still set `px-4 py-2.5` keep them.

---

## 5. Motion

120–220ms for anything common (§13). `--ease-out` for enter and exit,
`--ease-in-out` for movement on screen. Never `ease-in` on a UI element: it
delays the moment the user is watching most closely.

- **Nothing animates on a keyboard action.** The command palette opens with
  no transition, because it is opened hundreds of times a week and 200ms of
  entrance is 200ms of nothing.
- Panels grow from their trigger (`transform-origin`), starting at
  `scale(0.97)` and never from zero — nothing in the world appears from
  nothing.
- `prefers-reduced-motion` removes movement, keeps colour and opacity.
- No animated chart for spectacle.

---

## 6. Component inventory

What exists, and what surfaces it serves. `admin`, `client`, `store` =
storefront.

| Primitive | Surfaces | Notes |
| --- | --- | --- |
| `AppButton` | all | primary / secondary / ghost / danger-subtle / danger, two sizes, optional `icon`. One primary per context; `danger-subtle` is the way *into* a destructive action, solid `danger` only the final press in `AppConfirm`. |
| `AppInput`, `AppSelect`, `AppTextarea`, `AppCheckbox` | all | Persistent labels. A placeholder is never the only label (§7). |
| `MoneyInput` | admin | Integer minor units; the decimal exists only as the field's text. |
| `AppRichText` | admin | Markdown with a toolbar. Stores what was typed; the server renders it. |
| `AppCard` | all | Header separated by a hairline, not by whitespace. |
| `AppTable` | admin, client | Sticky header, hairline rows, `.numeric` cells right-aligned and tabular. Columns, selection and the toolbar strip (§7); `sortable` headers with `aria-sort` (server-side sort only); `hideBelow` priority columns and a `sticky` identity column; the columns menu sits in the header row when there is no toolbar. |
| `AppTableRow` | admin | A row that can be selected. Exists because the checkbox cell cannot be injected into slot markup. |
| `AppTableSkeleton` | admin | The table's own shape while it loads, so the page does not jump when rows land. |
| `AppSelectionBar` | admin | The count, Clear, and the screen's own bulk actions. Above the table, never floating over it. |
| `AppConfirm` | admin | §8's ladder: consequential, high-risk (reason), destructive (reason + typed name). |
| `AppDrawer` | admin | Right-side context drawer. Inspection only, and always offers the way to the record. |
| `OperationsDrawer` | admin | The background queue in the chrome, with the correlation ID per row. |
| `DangerZone` / `DangerZoneRow` | admin | Separated, last on the page, one sentence per irreversible thing. |
| `AppCopy` | admin, client | An id, IP or correlation id, one press away. |
| `AppBadge` | all | A label. **Not** the way operational status is shown. |
| `AppStatus` | all | Shape + text + colour. The way operational status is shown. |
| `AppStat` | admin | A figure you can press to filter by it. |
| `AppBarChart` | admin | SVG, no library, with an `sr-only` table of the same numbers. |
| `AppMenu` | admin, client | Teleported, origin-aware, escapes table overflow. Positioning is `useAnchoredPanel`'s. |
| `AppIcon` | all | Phosphor `regular`, one family, concepts not drawings. |
| `AppAlert` | all | Page-level message. |
| `EmptyState` | all | Left-aligned where the data will be, so arriving rows do not move the page. `boxed` in place of a table, `plain` inside a section. |
| `AppPagination` | admin, client | |
| `CommandPalette` | admin | ⌘K / Ctrl+K / `/`. Destinations locally, records from the search endpoint. |
| `ThemeSwitch` | all | light / dark / system. |
| `PageHeader` | admin, client | Title, `#status`, `#meta` line, `#actions`. Rendered by `AdminLayout`; detail pages pass their own via `#header`. |
| `DetailSection` | all | Heading + hairline. The default grouping — reach for it before `AppCard`. |
| `DescriptionList` | all | Label/value facts, `rows` or `grid`, slots by item key, em dash for missing. |
| `AppTabs` | admin, client | ARIA tabs with roving focus; `query` deep-links a facet. |
| `FilterBar` | admin, client | One row of filters, `#end` toggles, `#more` panel. |
| `SearchInput` | admin, client | The one field with no visible label (icon + `aria-label`). |
| `FilterSelect` | admin, client | `Status: Any ▾` — label inside the control; applies on change. |
| `MetricStrip` | admin, client | Headline figures in one strip, at `text-page`. Replaces KPI-card rows. |
| `LoadingState` / `ErrorState` | all | Non-table loading; failure vs. permission-denied, with correlation ID. |
| `useFocusTrap` | all | Keeps Tab inside `AppConfirm` and `AppDrawer`. |

### Table craft (§7)

Three of the four are built, and the fourth is deliberately not a component.

**Column visibility** is `localStorage`, keyed by the table's `name`. Which
columns fit is a fact about the window somebody is looking at, so an operator
on a laptop and on a 34-inch monitor wants a different answer on each and
neither is "the setting". A column an operator may hide is marked `optional`,
and the column that *names* the row never is — a table whose first column can
be hidden is rows of numbers belonging to nothing. The cells are hidden
through `data-col` and one generated rule scoped to the table's own id,
because a `<td>` is slot markup no class or scoped style of ours reaches.

**Selection** is `v-model:selected` on the table plus `AppTableRow` per row.
Select-all means *this page* and says so next to the count; rows picked on an
earlier page are kept, because somebody paging through a list is still
choosing. The selected row is marked by `data-selected`, which is one rule
next to the hover rule it has to stay distinguishable from — the tint alone
is not enough, so there is an inset edge as well.

**Bulk actions** belong to the screen, not to the table: only the screen knows
what they do and which of them needs asking. Destructive ones go through
`AppConfirm`. The server side is the interesting half, and `ApplyBulkInvoiceAction`
is the worked example: the ids came from a browser, so the **policy is asked
about every row**, a row the action cannot apply to is *skipped* rather than
refused, one row failing never stops the rest, and what happened comes back as
three numbers rather than the word "done".

**Saved views** are not built. A named set of filters is a preference about the
data rather than about the window, so it belongs on the server — a table, a
policy and an owner per row — and that is a slice of its own rather than a
component. Column visibility deliberately does *not* pretend to be it.

### The context drawer (§8)

`AppDrawer`, teleported and `fixed`, full width below `sm`. It exists because
checking one row out of two hundred should not cost the scroll position, the
filter and the selection — which is exactly what a navigation and a Back press
costs.

**Inspection, not management.** The drawer always offers the way to the whole
record, and anything that takes a decision lives there. A drawer that grew
into a second detail page would be two screens to keep in step.

The record arrives as an **`Inertia::optional` prop on the list route**, not
from an endpoint of its own: the server builds nothing on an ordinary page
load, and when the browser asks for the one prop by name it builds one record
rather than re-running the list. The invoices list is the worked example. An id
the operator cannot see comes back as `null`, never a 403 — the id is in a
query string anybody can type, and a 403 there is confirmation that the record
exists.

Focus moves into the panel on open and back to whatever opened it on close,
and the watcher is `immediate` so a drawer that mounts already open — a deep
link — still listens for Escape. Without that it silently did neither.

### The operations drawer (§8)

An operation is visible before it finishes (ADR 0032). The Operations Center
answers that for somebody who went looking; `OperationsDrawer` answers it for
somebody who did not — a provisioning run that failed twenty minutes ago is a
thing an operator should trip over.

So the trigger is in the topbar and it carries a count. Two indexed counts on
every admin render is a real cost and the only one worth paying: a badge that
is always grey is worse than no badge. **A dot for "something is running", a
number only for "something needs a person"** — nobody acts on "3 running".

The counts are `null`, never zero, for anybody who may not see operations.
Portal pages are shared the same props, and a count of the platform's failed
provisioning runs is not a customer's to know; zero would be a claim about the
queue, and null is "not yours".

The rows are an `Inertia::optional` prop, fetched when the drawer opens and
re-fetched every six seconds **only while it is open and something is still
running**. A page polling in the background costs an operator's battery all
afternoon and tells them nothing. Every row carries its correlation ID, which
§8 names: it is the one string that ties a failure here to the lines in the
log, so it is `AppCopy`.

This is the one thing the earlier "not built" note said needed a query cheap
enough to run on every request. It turned out `operations_state_index` is
exactly that. The **notification count still is not**, and stays unbuilt.

### The danger zone (§8)

`DangerZone` plus a `DangerZoneRow` per irreversible thing, **last on the
page, always**. Anything below it is a reason to scroll past it, and a thing
people scroll past is a thing they stop reading.

The separation is the point. A Terminate button in the same row as Suspend and
Sync is a button muscle memory reaches on a Friday afternoon; distance and a
different-looking surface are what make the hand stop. The border is
`border-danger/35`, not solid — a section outlined in full red reads as an
error that has already happened rather than a warning about one that could.

Each row leads with **a sentence about what is destroyed**, not a verb, and
the button is to its right so the sentence is read first. The confirmation is
`AppConfirm` at the level the screen chooses. The service page is the worked
example: terminating is level 4, so it wants a reason *and* the service's own
name typed out, and the reason reaches the job and the audit record.

### Still to build (handoff §8, §9)

- **Saved views** — server-side, not `localStorage`. See §7 above.
- **Step-up authentication** for §8's fourth confirmation level. There is
  two-factor at sign-in and no re-challenge, and `AppConfirm` says so rather
  than pretending: level 4 is reason plus typing the record's name.
- **The topbar notification count.** Unlike the operations count there is no
  index behind an unread count, and a dot that is always grey is worse than
  no dot.
- **Wallboard** (`/wallboard`, NOC, §4).
- **Theme Studio** (§12).

---

## 7. Screen matrix

| Template (§9) | Exists | Where |
| --- | --- | --- |
| Resource list | yes | Customers, Services, Domains, Orders, Invoices, Transactions, Tickets. **Reference: `Admin/Customers/Index`** (FilterBar, priority columns, pagination). |
| Resource detail | yes | Customer, Service, Domain, Order, Invoice, Ticket |
| Dashboard | yes | `Admin/Dashboard` — Attention Required, `MetricStrip`, revenue + infrastructure, activity as a table (§3). **Reference implementation** of the enterprise pass. |
| Customer 360 | partial | `Admin/Customers/Show` — identity header, tabs (Overview / Contacts / Notes), danger zone. **Reference detail page.** Services, billing, tickets and a unified timeline need controller data first. |
| Incident | no | Phase 13+ / Handoff #2 |
| Network device | no | Handoff #2 |
| Service | yes | `Admin/Services/Show` |

---

## 8. Themes

### The Core shell

A **248px collapsible rail plus a topbar** (§3), and the rail ships
collapsed to 72px. Collapsed is the right default for a panel whose screens
are mostly tables: an operator recognises seven glyphs within a day and gets
the width back for the data, and the one who wants labels presses once. The
preference is `localStorage`, not the account — somebody who opens this on a
laptop and a 34-inch monitor wants a different answer on each.

The rail keeps **WHMCS's group names** under **§3's category headings**:
Business, Operations, Support, System, Extensions. A heading appears only
when a group it owns is visible, so a rail never advertises Security before
a security screen exists.

The **collapse control is in the rail's header**, next to the mark, at both
widths. It sat at the foot of the bar first, under a list long enough to
scroll, which made it findable only by somebody who already knew it was
there — and a collapse control nobody can find is a rail that is not
collapsible.

Expanded, a group opens **in place**. Collapsed, it opens as a flyout,
because 72px has nowhere to put a nested list — and that flyout is
**teleported to the body and positioned `fixed`**, not `absolute` inside the
bar. The rail's nav scrolls, a scrolling container clips horizontally as well
as vertically, and the flyout was therefore cut off at 72px: the menu opened
*inside* the bar. It is the same bug the table row menus had, so it has the
same fix and now the same implementation — `useAnchoredPanel`, which is the
one place in this product that answers "a panel that must escape whatever is
clipping it". A new dropdown uses it rather than writing `absolute` and
finding out later.

The topbar carries where you are (breadcrumbs) and what belongs to the
session: ⌘K, appearance, tools, help, account. Nothing on it is page
content, which is what keeps it from becoming a second header.

On the topbar: ⌘K, the **background operations count**, appearance, tools,
help, account.

The footer is in the document flow, not fixed: a fixed footer cost every
screen 36px of rows to repeat a copyright line whose links are also on the
Help menu.

**Not on the topbar**, and named here rather than faked: the health indicator
and the notification count (§3). Each needs a query cheap enough to run on
every request. Operations turned out to have one — `operations_state_index` —
which is why that count exists. Health checks run on demand and an unread
count has no index behind it, so those two do not. A dot that is always grey
is worse than no dot.

| Theme | Personality | Density | Status |
| --- | --- | --- | --- |
| `infracms-core` | Enterprise, calm, balanced | Default | **This is what ships.** T0 done, T1 in progress. |
| `infracms-noc` | Dense, operational, dark-first | Compact | Manifest only. Not built — §19.7 forbids it until Core is stable. |
| `infracms-horizon` | Spacious, premium, white-label | Comfortable | Manifest only. Same. |

Override order (§1):
`Brand override → Child theme → Selected theme → Core component fallback`.

---

## 9. Accessibility

WCAG 2.2 AA (§14). What is enforced today:

- One focus treatment, never removed — `:focus-visible` with `--focus-ring`.
- A skip link on every layout.
- Status carries shape as well as colour.
- Icons that are the only content of a control carry a name; icons beside a
  label do not, or a screen reader says it twice.
- Charts ship an `sr-only` table of the same numbers.
- `prefers-reduced-motion` removes movement.
- Contrast: `--text-inverse` is dark on the dark theme's primary, because
  #3B82F6 against white is 3.7:1 and fails AA for body text.

- Dialogs and drawers trap Tab (`useFocusTrap`) as well as declaring
  `aria-modal`.
- `color-scheme` follows the chosen theme, so native checkboxes, selects and
  date pickers match it (they followed the OS before).
- Textarea hints and errors are tied to the field with `aria-describedby`.

Not yet done: a contrast check on brand overrides (§12), 200% zoom testing,
and a screen-reader pass on the new primitives.
