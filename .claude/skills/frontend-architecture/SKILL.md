---
name: frontend-architecture
description: How InfraCMS frontend code is structured — Inertia pages vs. primitives, where business logic may and may not live, the primitive inventory (AppShell, PageHeader, FilterBar, DataTable, StatusBadge, DetailSection, Tabs, Drawer, ConfirmDialog, CommandPalette…) mapped to real files, and the rules for adding or changing a component. Load before creating a Vue component or page, before adding a prop/variant to a primitive, when refactoring UI, or whenever you are about to write markup that looks like something that already exists.
---

# Frontend architecture

Stack: Laravel 13 + Inertia + Vue 3 (`<script setup lang="ts">`) + Tailwind v4.
No component library — the primitives in `resources/js/Components` are ours
(ADR 0040). Admin and client areas are Inertia; the storefront is Blade
themes (ADR 0009) and is out of scope here.

## 1. Layers

| Layer | Lives in | May contain | Must not contain |
| --- | --- | --- | --- |
| Business rules, authorization, money maths, status transitions | `app/` (Domain/Application) | everything | — |
| Page data | Controller → `Inertia::render` props | shaping rows for display, labels, `can` flags | business decisions made in JS |
| Page | `resources/js/Pages/**` | layout of primitives, local UI state (open dialog, selected tab), form wiring (`useForm`, `router`) | new visual patterns, colours, status mapping, copied markup of a primitive |
| Primitive | `resources/js/Components/*` | one visual/interaction pattern, tokens only | API calls, page-specific copy, business rules |
| Shell | `resources/js/Layouts/*` | rail, topbar, breadcrumbs, flash, header slots | page content |
| Shared logic | `resources/js/composables`, `status.ts`, `icons.ts` | pure helpers | DOM styling |

- `usePermissions().can()` decides what is **shown**, never what is
  **allowed**; the server re-checks with the gate of the same name.
- A redesign changes templates and primitives. It does **not** change props
  contracts, routes, form payloads or controller behaviour unless the task
  says so. If a screen genuinely needs more data, add it to the controller
  explicitly and note it.
- Money is integer minor units; format at the edge (`Intl.NumberFormat`) — never
  do arithmetic on formatted strings.

## 2. Primitive inventory

Before writing markup, find the primitive. If one is close, extend it (a prop
or slot) — do not fork it into the page.

| Concept | Use | Notes |
| --- | --- | --- |
| AppShell / Sidebar / TopNavigation / Breadcrumbs | `Layouts/AdminLayout.vue`, `Layouts/ClientLayout.vue` | rail (72/248px), topbar, breadcrumbs from the nav map |
| PageHeader | `Components/PageHeader.vue` | via `AdminLayout` `heading` + `#meta` `#status` `#actions`, or `#header` for detail pages |
| ActionBar | `PageHeader` `#actions` + `AppSelectionBar` for bulk | |
| FilterBar | `Components/FilterBar.vue` | `#end` for toggles, `#more` for the advanced panel |
| SearchInput | `Components/SearchInput.vue` | the only field without a visible label (has `aria-label`) |
| Filter select | `Components/FilterSelect.vue` | inline "Status: Any"; applies on change |
| DataTable | `Components/AppTable.vue` + `AppTableRow.vue` + `tableContext.ts` | `columns` (key, numeric, optional, offByDefault, sortable, hideBelow, sticky), `v-model:selected`, `v-model:sort`, `#toolbar`, `#bulk` |
| Table loading | `AppTableSkeleton.vue` | |
| StatusBadge | `Components/AppStatus.vue` + `statusTone()` in `resources/js/status.ts` | never map status in a page |
| Label badge | `AppBadge.vue` | tags/types only, not status |
| MetricCard | `Components/MetricStrip.vue` | one strip, not cards; `AppStat` = a pressable filter figure |
| DetailSection | `Components/DetailSection.vue` | heading + hairline; the default grouping |
| DescriptionList | `Components/DescriptionList.vue` | `rows` / `grid`; slots named by item key |
| Tabs | `Components/AppTabs.vue` | ARIA tabs, `query="tab"` for deep links |
| FormSection | `DetailSection` or `fieldset` + `legend` | |
| Inputs | `AppInput`, `AppSelect`, `AppTextarea`, `AppCheckbox`, `MoneyInput`, `CustomFieldInput`, `AppRichText` | |
| Button | `AppButton.vue` | `variant`, `size`, `icon`, `href` (renders Inertia `Link`), `loading` |
| EmptyState / LoadingState / ErrorState | `EmptyState.vue` (`boxed`/`plain`), `LoadingState.vue`, `ErrorState.vue` (`kind="permission"`) | |
| Alert | `AppAlert.vue` | |
| Modal / ConfirmDialog | `AppConfirm.vue` (levels consequential/high-risk/destructive) | focus-trapped; there is no generic form modal — prefer a page or drawer |
| Drawer | `AppDrawer.vue`; `OperationsDrawer.vue` | inspection, not management |
| Dropdown / menu | `AppMenu.vue` on `useAnchoredPanel` | anything floating must use `useAnchoredPanel` (escapes overflow) |
| CommandPalette | `CommandPalette.vue` | ⌘K |
| Pagination | `AppPagination.vue` | takes Laravel paginator `links` |
| Copyable ID | `AppCopy.vue` | `mono`, `label` for truncated display |
| Danger zone | `DangerZone.vue` + `DangerZoneRow.vue` | last on the page |
| Chart | `AppBarChart.vue` | `hide-title` inside a titled section |
| Icon | `AppIcon.vue` + `icons.ts` | concept names only |
| Focus trap | `composables/useFocusTrap.ts` | every modal surface |

**Copy-ready skeletons** for list, detail, overview and settings pages, plus
the checklist for converting an existing page, are in
[`page-recipes.md`](page-recipes.md) next to this file. Which pages still
need converting is tracked in `docs/design/propagation.md`.

## 3. Decision procedure

1. What is the operational purpose of this screen? (Write it in the page's
   top comment — the repo's pages all start with one.)
2. Which page template is it (list, detail, dashboard, settings, form)? Follow
   `enterprise-cms-ux`.
3. For each region, pick the primitive from the table. If none fits:
   - Can an existing primitive take a **prop or slot**? Extend it.
   - Is the pattern going to appear on a **second screen**? Make a primitive.
   - Otherwise, compose it in the page from primitives + tokens, and say why
     in a comment.
4. Never copy a primitive's classes into a page to "tweak" it.

## 4. Writing or changing a primitive

- One file in `resources/js/Components`, PascalCase, `App*` for generic
  controls. Top-of-file comment: what it is for, and the mistake it prevents.
- Props typed with `defineProps<…>()` + `withDefaults`; models with
  `defineModel`. Emit typed events. Expose nothing unless a caller needs it.
- Tokens only (see `enterprise-design-system`). Heights from `--control-h*`,
  table padding from `--row-y`, radii via `rounded-sm/md/lg/xl`.
- Accessibility is part of the component, not the caller's job (names,
  roles, keyboard, focus) — see `accessibility`.
- A unit test in `*.test.ts` beside it (Vitest + `@vue/test-utils`,
  `happy-dom`) for the behaviour that can regress: keyboard, ARIA wiring,
  emitted values. `resources/js/Components/designSystem.test.ts` shows the style.
- Update the inventory in `docs/design/design-system.md` §6 and this table.
- Backwards compatible by default: many screens use the primitive. If you
  change a default, check every usage (`grep -rn "<AppX" resources/js`).

## 5. Page conventions

- `<Head title="…" />` + `<AdminLayout heading="…">` (or `ClientLayout`).
- Props interface at the top mirrors the controller payload exactly.
- Local helpers (formatting) are fine; anything used on two pages moves to a
  composable or `status.ts`.
- Filters: `reactive` form mirrored to the URL with
  `router.get(url, query, { preserveState: true, replace: true })`.
- Mutations: `useForm` / `router.post|delete` with `preserveScroll`; handle
  `processing` (button `loading`) and `errors` (field `error` prop).
- Strings: user-facing text must be translatable (CLAUDE.md #10) — use the
  server-provided `labels` or `useTranslations()`; hard-coded English is debt.

## 6. Gates (all must pass)

```bash
npm run typecheck && npm run lint && npm run test:unit && npm run build
```

Then `visual-quality-review` in the browser. Compilation is not completion.
