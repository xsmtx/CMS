---
name: accessibility
description: Accessibility requirements for InfraCMS (WCAG 2.2 AA, Handoff 3 §14) and how this codebase meets them — contrast, keyboard navigation, focus, semantic HTML and ARIA, form labels and errors, dialogs and focus traps, tables, menus, tabs, tooltips, charts, status without colour, reduced motion. Load whenever you build or change an interactive component, form, dialog, table, menu, chart or colour token, and as part of every visual review. Accessibility is a requirement, not polish.
---

# Accessibility

Target **WCAG 2.2 AA** on every surface. Operators use this with keyboards
all day, on projectors, over remote desktop that crushes colour, at 3am.

## 1. Contrast

- Body text ≥ 4.5:1; large text (≥ 19px semibold) and UI component edges,
  focus rings and meaningful icons ≥ 3:1 against what they sit on.
- The tokens are chosen to pass: `text-content` and `text-content-muted` are
  safe for any text; **`text-content-subtle` is for secondary metadata at
  ≥ 12px only** — never for the only copy of important information, never on
  `bg-surface-secondary` for small text without checking.
- Status colours as **text** must pass 4.5:1 on their surface; as a shape
  mark 3:1. `--text-inverse` is dark on the dark theme's brand because white
  on `#3b82f6` is 3.7:1.
- New/changed token → check both themes. Quick check in the browser console:
  ```js
  const L=c=>{const [r,g,b]=c.match(/\d+/g).map(v=>{v/=255;return v<=.03928?v/12.92:((v+.055)/1.055)**2.4});return .2126*r+.7152*g+.0722*b}
  const ratio=(el)=>{const s=getComputedStyle(el);let bg=el;while(bg&&getComputedStyle(bg).backgroundColor.includes('0)'))bg=bg.parentElement;const a=L(s.color),b=L(getComputedStyle(bg||document.body).backgroundColor);return ((Math.max(a,b)+.05)/(Math.min(a,b)+.05)).toFixed(2)}
  ratio($0)
  ```
- Brand overrides (white-label) must keep 4.5:1 for `--text-inverse` on
  `--brand-primary`; operational status colours are not rebrandable.

## 2. Keyboard

- Everything reachable and operable with Tab / Shift+Tab / Enter / Space /
  Escape / arrows, in visual order. No positive `tabindex`.
- A skip link ("Skip to content" → `#main`) exists in every layout; keep it.
- Composite widgets use roving focus: `AppTabs` (arrows, Home/End, only the
  selected tab in the Tab order). Menus open with Enter/Space, close with
  Escape and return focus to the trigger.
- Row actions that appear on hover also appear on `:focus-within` (built into
  `.row-actions`). Anything revealed on hover must be reachable by keyboard.
- Global shortcuts (⌘K, `/`) never fire while typing in a field.

## 3. Focus

- One focus style, never removed: `:focus-visible` outline in `--focus-ring`
  (2px, offset 2px), defined globally in `app.css`. Do not add
  `outline-none` without an equivalent visible replacement (see
  `FilterSelect`, which moves the ring to its wrapper with `has-[…]`).
- Opening a dialog/drawer moves focus into it; closing returns focus to the
  trigger; **Tab is trapped** inside while open (`useFocusTrap`). Escape closes.
- After a destructive action removes the focused element, move focus to a
  sensible neighbour or the page heading.

## 4. Semantics and ARIA

- Native elements first: `<button>` for actions, `<a>`/Inertia `Link` for
  navigation (`AppButton` renders the right one from `href`), `<table>` for
  tables, `<dl>` for label/value, `<fieldset>`/`<legend>` for groups,
  `<nav aria-label>` for navigation, `<main id="main">`.
- One `h1` per page; headings do not skip levels (`DetailSection` has a
  `level` prop for nesting).
- ARIA only where HTML has no equivalent, and completely: tabs
  (`tablist/tab/tabpanel`, `aria-selected`, `aria-controls`,
  `aria-labelledby`), menu triggers (`aria-haspopup="menu"`, `aria-expanded`),
  dialogs (`role="dialog"|"alertdialog"`, `aria-modal`, `aria-labelledby`,
  `aria-describedby`), toggles (`aria-pressed`), disclosure buttons
  (`aria-expanded` + `aria-controls`).
- Icon-only controls have an accessible name (`AppMenu :label`, `AppIcon
  :label`, `aria-label`). Icons beside a text label are `aria-hidden`.
- Live regions: `AppAlert` danger uses `role="alert"`, everything else
  `role="status"`; a selection count uses `aria-live="polite"`. Don't make
  whole tables live.

## 5. Forms

- Every field has a persistent visible `<label for>` (`AppInput`,
  `AppSelect`, `AppTextarea`, `AppCheckbox` do it). Placeholder is never the
  label. The single exception is `SearchInput`, which has an icon, a
  placeholder and an `aria-label`.
- Hints and errors are linked with `aria-describedby`; invalid fields get
  `aria-invalid="true"`; errors are text, not only a red border.
- Long forms show an error summary at the top that links to the fields.
- Required is marked visually and with `required`.
- `autocomplete` attributes on identity/address fields.

## 6. Tables

- `<th scope="col">` headers (AppTable does this), row headers with
  `scope="row"` when a table is read across.
- Sortable headers are a `<button>` inside the `<th>` and the `<th>` carries
  `aria-sort` (built in).
- Selection checkboxes are named per row ("Select invoice INV-1043"), select
  all says "on this page".
- Don't use tables for layout; don't put interactive controls in a header
  except sorting and the columns menu.

## 7. Status, colour and charts

- Status is shape + word + colour (`AppStatus`). Never colour alone — not in
  a badge, a dot, a row tint or a chart.
- Charts ship an `sr-only` table of the same numbers (AppBarChart does) and a
  text summary of what matters ("Revenue up 12% on last month").
- Hover-only tooltips are never the only way to get a value.

## 8. Motion and zoom

- `prefers-reduced-motion` removes movement globally (`app.css`); do not
  override it. No auto-playing or looping animation in operational screens.
- Layouts must survive **200% zoom** and 320px-wide reflow for content pages
  (tables may scroll horizontally inside their frame).
- Touch targets ≥ 24×24px (WCAG 2.2 2.5.8); controls are 28–32px tall.

## 9. How to verify

- Keyboard-only pass through the screen: every control reached, focus always
  visible, dialogs trap and return focus, Escape works.
- Screen reader spot check of names: `read_page` (claude-in-chrome) with
  `filter: "interactive"` should list no unnamed buttons or links.
- Unit tests: extend `resources/js/Components/accessibility.test.ts` or the
  component's own test for any new ARIA wiring.
- Report what was verified and what was not (e.g. no screen reader run).
