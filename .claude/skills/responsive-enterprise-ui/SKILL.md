---
name: responsive-enterprise-ui
description: Responsive behaviour for InfraCMS as desktop-first enterprise software — the target resolutions (1920×1080, 1440×900, 1366×768, 1280×800), breakpoints, shell/rail behaviour, and how dense tables, filter bars, detail layouts, dialogs and drawers adapt on laptops, tablets and phones without turning every table into cards. Load when building any layout, table or page, when a screen overflows or wraps badly, and during visual review.
---

# Responsive enterprise UI

Desktop is the primary surface. Laptops are the constraint: a 1280×800 or
1366×768 screen with a 72px rail must still show a useful table. Tablets and
phones must work reasonably — for checking, approving and small edits — not
become a different product.

## 1. Targets and breakpoints

| Target | CSS viewport | Must hold |
| --- | --- | --- |
| 1920×1080 | ~1900 | Content caps at `max-w-[110rem]`; no line of prose > 80ch; two-column detail layout. |
| 1440×900 | ~1420 | Everything on one row: filter bar, header actions, all default table columns. |
| 1366×768 | ~1350 | ≥ 12 table rows visible at default density; header + filters ≤ ~150px. |
| 1280×800 | ~1260 | Priority columns only (`hideBelow="xl"` drops the rest); filter bar may wrap to two rows, never three. |
| Tablet 768–1023 | | Rail becomes a drawer (below `lg`); tables scroll horizontally with a sticky identity column; aside stacks under main. |
| Phone < 640 | | Single column; header actions collapse to primary + overflow; dialogs/drawers full width. |

Tailwind breakpoints: `sm` 640 · `md` 768 · `lg` 1024 · `xl` 1280. Do not add
`2xl:` rules without updating the QA method in `visual-quality-review`
(it relies on there being none).

## 2. Shell

- ≥ `lg`: fixed rail (72px collapsed by default, 248px open, remembered per
  browser) + sticky 56px topbar.
- < `lg`: rail is an off-canvas drawer with a scrim, opened from the topbar.
- The topbar never wraps: breadcrumbs truncate first, then secondary topbar
  controls hide behind menus.
- Footer is in the document flow, not fixed — it must never steal list rows.

## 3. Tables — not cards

Tables stay tables. Use, in this order:

1. **Priority columns** — `columns[].hideBelow: 'md' | 'lg' | 'xl'` on
   secondary facts (created date, region, owner, ID). Never on the identity
   or status column.
2. **Operator choice** — `optional` / `offByDefault` columns plus the
   columns menu; the choice is remembered per browser (a laptop and a
   34" monitor want different answers).
3. **Horizontal scroll inside the frame** — `AppTable` wraps the table in
   `overflow-x-auto`; the page itself never scrolls sideways.
4. **Sticky identity column** — `columns[].sticky: true` on the first column
   so the row stays identifiable while scrolling.
5. **Truncate, don't wrap** long names in dense tables (`max-w-[…] truncate`
   with the full value in `title` or reachable on the record page). IDs are
   shortened for display and copied in full (`AppCopy :label`).
6. **Drawer for detail** — on narrow screens, open a row in `AppDrawer`
   instead of adding columns.

Card/list layouts are acceptable only on phones (< `sm`) **and** only for
lists whose rows have ≤ 3 facts (e.g. a customer's own services in the
client area). Never for admin operational tables.

## 4. Other patterns

- **Page header**: title block and actions wrap; the primary action stays
  visible at every width; extra actions collapse into an overflow `AppMenu`
  below `md`.
- **Filter bar**: wraps; below `sm` only the search box stays inline and the
  rest move behind "Filters" (the `#more` panel). Never stack labelled
  fields four rows deep above a list.
- **Detail layout**: `lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]`; below
  `lg` the aside follows the main column. Tabs scroll horizontally (the tab
  strip already does) rather than wrapping.
- **Metric strip**: 2 columns below `lg`, one row above.
- **Dialogs**: `max-w-lg`, full width minus 16px gutters on phones, scroll
  inside. **Drawers**: fixed width ≥ `sm`, full width below.
- **Menus/popovers**: `useAnchoredPanel` keeps them on screen and escapes
  overflow; don't hand-position.
- **Touch**: `(hover: none)` shows row actions permanently (built into
  `.row-actions`); targets ≥ 24px; no hover-only affordances.
- **Charts**: fewer ticks/labels on narrow widths; keep the `sr-only` table.

## 5. Never

- Hide status or the primary identifier to make something fit.
- Let the page scroll horizontally.
- Shrink text below `text-label` (11px) to fit a column.
- Duplicate a page for mobile.

## 6. Verify

Follow `visual-quality-review` step 5 (CSS zoom for the four desktop widths).
For < `lg`, resize the real window or use DevTools device mode; if neither is
possible in the session, report that tablet/phone were not verified.
