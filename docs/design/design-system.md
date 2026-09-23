# InfraCMS design system

The contract between the product and anything that themes it.

**Source of record:** `INFRACMS_UI_UX_THEME_HANDOFF_3.md`. Where this
document and the handoff disagree, the handoff wins and this document is
wrong. Where this document says *more* than the handoff, it is recording a
decision the handoff left open — each of those says so.

Related: [ADR 0040 — The UI is primitives, not a component library](../adr/0040-the-ui-is-primitives.md),
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
| `--radius-control/card/badge/modal` | via `--radius-sm/md/lg/xl` | `rounded-[var(--radius-md)]` |
| `--space-1` … `--space-16` | — | the 4/8/12/16/20/24/32/40/48/64 scale |

† **`--surface-chrome` is an addition** beyond the handoff's required
families. The Core palette gives the sidebar its own colour in both
appearances — white in light, a shade above the page in dark — and
`background-subtle` cannot carry it, because in light those are two
different colours.

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

`AppStatus` is the component. **Maintenance and unknown are statuses, not
shades of warning** — those two are the things an operator most needs to tell
apart from "it is broken", and every panel that coloured them amber taught
its users to ignore amber.

---

## 3. Type

Five sizes, two weights. Body is 13px because that is what a screen somebody
reads for eight hours wants: larger is a document, smaller is a spreadsheet.

| Token | Size | For |
| --- | --- | --- |
| `text-label` | 11px, +0.04em | Column heads, micro-labels, uppercase. |
| `text-chrome` | 12px | Secondary lines, help text, footers. |
| `text-body` | 13px | The working size. `<body>` is set to it. |
| `text-title` | 15px, −0.01em | Card and section headings. |
| `text-page` | 19px, −0.015em | One per screen. |

Tracking tightens as size grows — what optical sizing does by hand. A sixth
size is how a product ends up with nine.

Monospace (`font-mono`) is for technical identifiers only: IP, CIDR, MAC,
ASN, UUID, invoice number, config. Not for prose and not for ordinary
numbers — those get `tabular-nums`, which is the `.numeric` cell on a table.

---

## 4. Geometry, space, density

Radii (§3): controls 8, cards 10, badges 6, modals 12. Nothing is a pill
except a true toggle. A pill-shaped badge in a table cell reads as
decoration, which is what a status must not be.

Spacing is the handoff's scale — 4, 8, 12, 16, 20, 24, 32, 40, 48, 64 — as
`--space-1` … `--space-16`.

Density is `data-density` on the root, and it moves three measurements: row
padding, control padding, card padding.

| Preset | Theme | Row |
| --- | --- | --- |
| `compact` | NOC | 6px |
| *(default)* | Core | 10px |
| `comfortable` | Horizon | 14px |

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
| `AppButton` | all | primary / secondary / ghost / danger, two sizes. One primary per context; destructive is never the default. |
| `AppInput`, `AppSelect`, `AppTextarea`, `AppCheckbox` | all | Persistent labels. A placeholder is never the only label (§7). |
| `MoneyInput` | admin | Integer minor units; the decimal exists only as the field's text. |
| `AppRichText` | admin | Markdown with a toolbar. Stores what was typed; the server renders it. |
| `AppCard` | all | Header separated by a hairline, not by whitespace. |
| `AppTable` | admin, client | Sticky header, hairline rows, `.numeric` cells right-aligned and tabular. Columns, selection and the toolbar strip (§7). |
| `AppTableRow` | admin | A row that can be selected. Exists because the checkbox cell cannot be injected into slot markup. |
| `AppTableSkeleton` | admin | The table's own shape while it loads, so the page does not jump when rows land. |
| `AppSelectionBar` | admin | The count, Clear, and the screen's own bulk actions. Above the table, never floating over it. |
| `AppConfirm` | admin | §8's ladder: consequential, high-risk (reason), destructive (reason + typed name). |
| `AppCopy` | admin, client | An id, IP or correlation id, one press away. |
| `AppBadge` | all | A label. **Not** the way operational status is shown. |
| `AppStatus` | all | Shape + text + colour. The way operational status is shown. |
| `AppStat` | admin | A figure you can press to filter by it. |
| `AppBarChart` | admin | SVG, no library, with an `sr-only` table of the same numbers. |
| `AppMenu` | admin, client | Teleported, origin-aware, escapes table overflow. Positioning is `useAnchoredPanel`'s. |
| `AppIcon` | all | Phosphor `regular`, one family, concepts not drawings. |
| `AppAlert` | all | Page-level message. |
| `EmptyState` | all | Left-aligned where the data will be, so arriving rows do not move the page. |
| `AppPagination` | admin, client | |
| `CommandPalette` | admin | ⌘K / Ctrl+K / `/`. Destinations locally, records from the search endpoint. |
| `ThemeSwitch` | all | light / dark / system. |

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

### Still to build (handoff §8, §9)

- **Context drawer** — right-side inspection without losing the list.
- **Background operations drawer** — running / retrying / failed, with the
  correlation ID.
- **Danger zone** — separated at the bottom of a resource's settings.
- **Saved views** — see above: server-side, not `localStorage`.
- **Step-up authentication** for §8's fourth confirmation level. There is
  two-factor at sign-in and no re-challenge, and `AppConfirm` says so rather
  than pretending: level 4 is reason plus typing the record's name.
- **Wallboard** (`/wallboard`, NOC, §4).
- **Theme Studio** (§12).

---

## 7. Screen matrix

| Template (§9) | Exists | Where |
| --- | --- | --- |
| Resource list | yes | Customers, Services, Domains, Orders, Invoices, Transactions, Tickets |
| Resource detail | yes | Customer, Service, Domain, Order, Invoice, Ticket |
| Dashboard | yes | `Admin/Dashboard` — Attention Required, the four-figure strip, infrastructure, revenue trend, recent activity (§3) |
| Customer 360 | partial | `Admin/Customers/Show` — profile, services, billing, tickets; **no unified timeline** |
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

**Not on the topbar yet**, and named here rather than faked: the health
indicator and the notification count (§3). Each needs a query cheap enough
to run on every request, and neither exists — health checks run on demand
and an unread count has no index behind it. A dot that is always grey is
worse than no dot.

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

Not yet done: a contrast check on brand overrides (§12), and 200% zoom
testing.
