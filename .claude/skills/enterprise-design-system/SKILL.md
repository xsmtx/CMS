---
name: enterprise-design-system
description: The visual language of InfraCMS — tokens, type scale, spacing, radius, elevation, icons, and how every primitive (buttons, inputs, tables, status, tabs, dialogs, drawers, menus, alerts, empty states, skeletons, charts) must look. Load before writing or changing ANY .vue template, CSS, Tailwind class, theme token or visual component under resources/ or themes/. Also load when reviewing UI, adding a colour, size or icon, or when something "looks off".
---

# Enterprise design system

InfraCMS is operational software for people managing hundreds of servers,
thousands of domains and a billing ledger. The interface should read as
**serious, restrained, dense and consistent** — closer to Stripe, Datadog,
Cloudflare and GitLab than to a SaaS template.

Order of authority when sources disagree:

1. `INFRACMS_UI_UX_THEME_HANDOFF_3.md` (product spec of record)
2. `docs/design/design-system.md` (decisions and the token table)
3. this skill (how to apply them)

Companion skills: `enterprise-cms-ux` (behaviour and page structure),
`frontend-architecture` (which component to use), `accessibility`,
`responsive-enterprise-ui`, `visual-quality-review` (run before you finish).

## Principles

clarity > decoration · consistency > novelty · information > empty space ·
function > effects · hierarchy > containers · reuse > one-offs ·
operator efficiency > marketing aesthetics.

## 1. Tokens are the only source of values

The CSS custom properties in `resources/css/app.css` are public API (ADR 0040).
Tailwind utilities resolve them. **Never write a hex, rgb or oklch value in a
component**, and never use Tailwind's palette (`bg-blue-500`, `text-gray-400`,
`bg-white`).

| Use | Utility |
| --- | --- |
| Page background | `bg-background` (set on `body` — don't repeat) |
| In-flow surface (table, strip, panel) | `bg-surface-primary` |
| Recessed / secondary fill | `bg-surface-secondary` |
| Floating surface (menu, dialog, drawer) | `bg-surface-elevated` or `bg-surface-primary` + `shadow-(--shadow-panel)` |
| Hover fill | `hover:bg-surface-hover` |
| Selected fill | `bg-surface-selected` |
| Sidebar / topbar | `bg-surface-chrome` |
| Hairline / stronger / faint | `border-line` / `border-line-strong` / `border-line-subtle` |
| Text: primary / secondary / tertiary | `text-content` / `text-content-muted` / `text-content-subtle` |
| Brand (primary action, selection, links) | `bg-brand`, `text-brand`, `border-brand` |
| Status | `text-success` `text-warning` `text-danger` `text-info` `text-maintenance` `text-unknown` |
| Module accent (thin accents only, never fills) | `text-billing` `text-infrastructure` `text-network` `text-security` `text-automation` `text-dns` `text-storage` |

Tints come from opacity modifiers on semantic tokens (`bg-danger/10`,
`border-brand/35`), not from new colours.

**Colour budget per screen:** neutrals everywhere; brand for the one primary
action, the selected tab/nav item, links and focus; status colours only where
something *has* a status. A rainbow screen is a bug.

## 2. Typography

System font stack (no webfonts in core — ADR 0040 §4). Five sizes, two weights.

| Utility | Size | Use |
| --- | --- | --- |
| `text-page` | 19px | The page `h1`. One per screen. Also headline figures in `MetricStrip`. |
| `text-title` | 15px | Section headings (`h2`/`h3`), dialog titles. |
| `text-body` | 13px | Everything by default: table cells, inputs, buttons, labels, prose. |
| `text-chrome` | 12px | Secondary lines, hints, meta lines, small buttons, breadcrumbs. |
| `text-label` | 11px +tracking | Column headers and micro-labels, `uppercase`. |

- Weights: `font-medium` (500) for labels, names, buttons; `font-semibold`
  (600) for headings and figures. **No `font-bold`**, no light weights.
- **Never** `text-xs/sm/base/lg/xl/2xl…` or `text-[Npx]`. Hierarchy comes from
  weight, colour (`content` → `muted` → `subtle`) and position, not size.
- `font-mono` for identifiers only: IP, CIDR, MAC, ASN, UUID/ULID, hostnames in
  tables, ports, invoice numbers, config. Use `AppCopy … mono` when the value is
  something people paste elsewhere.
- `tabular-nums` for every figure that sits in a column or updates live (a
  `.numeric` cell does it for you).
- Prose width: `max-w-[60ch]`–`max-w-[80ch]` for descriptions; never let a
  paragraph run the full 1700px.

## 3. Spacing

Scale: 4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 · 48 · 64 (`--space-*`).
In Tailwind steps: `1 2 3 4 5 6 8 10 12 16`.

- **Layout spacing** (between sections, page padding, grid gaps) must be on the
  scale: sections `gap-8`, blocks inside a section `gap-4`–`gap-5`, header to
  content `mb-5`, inline items `gap-2`/`gap-3`.
- Half steps (`1.5`, `2.5` = 6/10px) are allowed **only inside a primitive**
  for optical alignment (control padding, badge padding). Never on a page.
- Tighter than a marketing site: a section needs a heading and 32px above it,
  not a 96px hero gap.

## 4. Geometry and elevation

| Radius | Utility | For |
| --- | --- | --- |
| 6px | `rounded-sm` | badges, menu rows, small inner elements |
| 8px | `rounded-md` | buttons, inputs, selects, alerts |
| 10px | `rounded-lg` | tables, strips, panels, cards |
| 12px | `rounded-xl` | dialogs |
| full | `rounded-full` | avatars, spinners, status dots, true toggles — nothing else |

Write `rounded-md`, not `rounded-[var(--radius-md)]`.

Elevation: in-flow surfaces are **flat** — a hairline, no shadow. Only
floating layers (menus, popovers, dialogs, drawers, the command palette) get
`shadow-(--shadow-panel)`. In dark mode shadows are nearly invisible by
design; the hairline carries the structure.

## 5. Containers — hierarchy, not boxes

Page → Section → Content. A rectangle must communicate something.

- A **section** is `DetailSection`: heading + optional description/actions +
  hairline. No border, no fill. This is the default grouping.
- A **framed surface** (`rounded-lg border bg-surface-primary`) is for things
  that are objects: a table, a `MetricStrip`, a chart that needs a frame, the
  danger zone, a side panel on a busy page, a "More filters" panel.
- `AppCard` is for a genuinely separate surface. Not for "this is a group".
- **Maximum one framed surface deep.** No card inside a card. A framed thing
  inside a section is fine; a framed thing inside a framed thing needs a
  written reason in a comment.
- Two-column detail layout: main `minmax(0,1fr)` + aside `minmax(0,22–24rem)`,
  `gap-x-10 gap-y-8`, no boxes around either column.

## 6. Component rules (visual)

**Buttons** (`AppButton`): heights come from `--control-h` (32px default) /
`--control-h-sm` (28px). Variants:
`primary` (one per local context) · `secondary` (default) · `ghost` (toolbar /
tertiary) · `danger-subtle` (the *entry* to a destructive action: danger-zone
rows, menus) · `danger` (the *final* press, inside a confirmation only).
Icon + label via the `icon` prop; icon-only buttons need a label (see
accessibility). Never a gradient, never a pill, never an all-red header.

**Inputs** (`AppInput`, `AppSelect`, `AppTextarea`, `MoneyInput`): label above
(`text-body font-medium`), control at `--control-h`, hint/error below at
`text-chrome`. In a filter row use `SearchInput` / `FilterSelect` instead.

**Status** (`AppStatus` + `statusTone()` from `resources/js/status.ts`):
shape + word + colour, always. `● healthy ◐ info ▲ warning ■ critical ◆ maintenance □ neutral ○ unknown`.
Do not map status words in a page; add the word to `status.ts`.

**Badges** (`AppBadge`): labels that are *not* status — tags, "Primary",
"Visible to customer", a type. At most one or two per row. If every fact on a
row is a badge, none of them is.

**Tables** (`AppTable`): flat, hairline rows, sticky header, `text-label`
uppercase headers, cell padding from `--row-y` (write cells **without** `px-*
py-*`), `.numeric` for figures, row actions in `.row-actions` (revealed on
hover/focus, always on touch). Details in `enterprise-cms-ux`.

**Tabs** (`AppTabs`): underline style, brand edge on the selected tab, counts
in `text-content-subtle`. Not pills, not boxed segments.

**Menus / dropdowns** (`AppMenu`, anchored with `useAnchoredPanel`): elevated
surface, `rounded-lg`, `p-1.5`, rows `rounded-sm px-2 py-1.5 text-body`,
destructive rows `text-danger` at the bottom.

**Dialogs** (`AppConfirm`): `rounded-xl`, `max-w-lg`, scrim `bg-background/70`.
**Drawers** (`AppDrawer`): right side, full height, hairline left edge.

**Tooltips**: there is no tooltip component on purpose. Essential information
never lives in a tooltip. `title` is acceptable only as a supplement (a
truncated value's full text, a bar's exact figure).

**Alerts** (`AppAlert`): neutral surface, tone on the border and icon; the
sentence stays `text-content`. One per page region, not stacked.

**Empty / loading / error**: `EmptyState` (`boxed` in place of a table,
`plain` inside a section), `AppTableSkeleton` for tables, `LoadingState`
elsewhere, `ErrorState` for failures and `kind="permission"`.

**Metrics**: `MetricStrip` — one hairline-divided strip, figures at
`text-page`. Never a row of KPI cards, never a 36px number.

**Charts** (`AppBarChart`; load the `dataviz` skill for anything new):
restrained axes, brand for the series, status colours only for status,
`sr-only` data table, no 3D, no donut-for-everything, no animation for show.
Use `hide-title` when the enclosing section already names the chart.

**Icons** (`AppIcon`, concept names from `resources/js/icons.ts`): Phosphor
`regular` only. Sizes: **12** (chevrons in chrome), **14** (in `sm` buttons,
inputs), **16** (default: buttons, rows, nav), **20** (rare, standalone). Never
emoji. Add a concept to `icons.ts`; never import a `Ph*` component in a page.

## 7. Dark mode

Designed, not inverted — the dark values live in `app.css` in two places that
must stay identical (the `prefers-color-scheme` block and
`:root[data-theme='dark']`). When you add a token, add it to **light and both
dark blocks**. `color-scheme` follows the explicit theme so native controls
match. Check every change in both themes (see `visual-quality-review`).

## 8. Motion

120–220ms (`--duration-press/fast/base`), `ease-(--ease-out)`. Animate colour,
opacity and transform only. Nothing animates on a keyboard action; panels
scale from 0.97, never from 0. `prefers-reduced-motion` is already handled
globally — do not re-enable motion locally.

## 9. Anti-patterns — reject on sight

Card inside card · giant rounded cards · `rounded-2xl/3xl` · gradients or
gradient text · glassmorphism / `backdrop-blur` on content · decorative blobs
or illustrations in admin · emoji as icons · `text-2xl+` headings or KPI
numbers · `shadow-lg/xl` on in-flow content · coloured section backgrounds ·
every fact as a badge · pills for status · status as colour alone · a
different green per module · arbitrary `text-[…]`, `p-[…]`, `#hex` · mixed
icon families or sizes · `py-6` table rows · huge centred empty states ·
full-width red buttons · "Welcome back!" hero blocks.

Quick self-check before finishing (from `infracms/`):

```bash
grep -rnE "text-(xs|sm|base|lg|[2-9]?xl)\b|text-\[[0-9]|#[0-9a-fA-F]{6}|shadow-(lg|xl|2xl)|rounded-(2xl|3xl)|bg-gradient|backdrop-blur|font-bold" resources/js --include=*.vue
grep -rn "AppBadge :tone=\"tone(" resources/js --include=*.vue   # status as badge
```

Anything these print in code you touched must be fixed or justified in a comment.
