---
name: enterprise-cms-ux
description: UX rules for InfraCMS as an operator tool — page structure, list/table pages, resource detail pages, filtering, search, bulk actions, contextual and row actions, relationships between resources, dangerous operations and confirmation levels, and the states every screen must handle. Load before designing or restructuring any admin or client screen, adding a list/detail/settings page, adding actions or filters, or deciding how a workflow should behave.
---

# Enterprise CMS UX

The people using this spend their day in it: support agents, billing staff,
sysadmins, NOC engineers. Optimise for **how fast a trained operator gets the
answer or finishes the action**, not for first impressions.

Visual rules are in `enterprise-design-system`; which component to reach for
is in `frontend-architecture`.

## 1. Every screen answers four questions, top to bottom

1. **Where am I?** — breadcrumbs in the topbar, one `h1`.
2. **What am I looking at?** — a meta line under the title: counts, state,
   identifiers (`128 servers · 124 healthy · 3 warning · 1 critical`).
3. **What matters?** — attention first: failures, overdue, stuck operations.
4. **What can I do?** — one primary action top right, the rest nearby.

## 2. Page structure

```
AppShell (AdminLayout: rail + topbar + breadcrumbs)
└ PageHeader: title · status · meta line · [secondary] [Primary]
  └ FilterBar: search · filter selects · More filters · …  │ result toggles · columns
    └ Main content (table / sections / tabs)
      └ Pagination · supporting info
        └ DangerZone (detail/settings pages only, always last)
```

Most screens get the header for free from `AdminLayout`'s `heading` prop and
its `#meta`, `#status`, `#actions` slots. A detail page passes its own
`PageHeader` through `#header`.

- No "Welcome back", no descriptions that restate the title. A description is
  allowed when it tells the operator something they would otherwise get wrong
  ("Once issued, an invoice never changes. Corrections are credit notes.").
- The page does not scroll to reach its primary action.

## 3. List pages

Tabular information goes in a **table**. Never turn rows into cards on
desktop. Prefer

`Server | Status | IP | CPU | RAM | Disk | Uptime | Alerts | ⋯`

over six cards saying the same thing.

- **Columns, left to right:** identity (name, linked) → status → the 3–6
  facts people scan for → dates/owner → ID. Identity is never hideable;
  secondary columns are `optional`, rarely-needed ones `offByDefault`.
- **Filters are one row** (`FilterBar`): a `SearchInput` for the free-text
  lookup, `FilterSelect` for enumerations (they apply immediately), a
  "More filters" toggle for the long tail (with a count of active ones), and
  a Clear that appears only when something is set. Filter state lives in the
  URL query so a filtered list can be bookmarked and shared.
- **A filtered list looks filtered** — set filters get the brand edge; the
  meta line/empty state says filters are active and offers Clear.
- **Sorting** only for keys the server sorts by (`columns[].sortable` +
  `v-model:sort`). Never sort one page of a paginated result client-side.
- **Summary before the table** only when it drives action: a `MetricStrip`
  whose figures filter the list (failed · pending · suspended). Not decoration.
- **Pagination** always visible when there is more than one page
  (`AppPagination` with the paginator's `links`). Say the total.
- **Row density**: default ~32px rows. Never `py-4+` cells.

### Row actions and bulk actions

- The row's name is the link to the record. Do not add a "View" button.
- Row actions: at most one visible verb (e.g. Edit) plus an overflow
  `AppMenu` (⋯), inside `.row-actions` so they appear on hover/focus and are
  always visible on touch. Destructive items go last in the menu, in
  `text-danger`, and open a confirmation.
- Bulk actions: `selectable` table + `AppTableRow` + `AppSelectionBar` above
  the table. "Select all" means *this page* and says so. The server checks
  policy per row, skips what it cannot apply, and reports
  applied/skipped/failed counts (see `ApplyBulkInvoiceAction`).
- Quick inspection without losing list context → `AppDrawer` (read-only,
  always links to the full record). Decisions happen on the record page.

## 4. Resource detail pages

```
Server / web-01
web-01  ● Healthy                               [Console] [Restart] [Edit] [⋯]
10.0.4.21 · Hetzner · fsn1 · Ubuntu 24.04 · up 98d
Overview | Monitoring | Services | Network | Storage | Security | Backups | Events | Config | Activity
```

- **Identity band** (`PageHeader`): name, `AppStatus`, a meta line of the
  identifying facts (copyable IDs/IPs with `AppCopy mono`), everyday actions.
  Maximum ~3 visible buttons + overflow. The most frequent action may be
  primary.
- **Dangerous actions never sit in the identity band.** Terminate, delete,
  erase, revoke → `DangerZone` at the foot of the page or the last items of
  the overflow menu, and always through `AppConfirm`.
- **Tabs** (`AppTabs` with `query="tab"` so facets deep-link) when a resource
  has several substantial facets. Do not manufacture tabs for two short
  sections — use `DetailSection`s on one page.
- **Overview tab**: facts (`DescriptionList`), relationships, the latest few
  events. Two columns at ≥ lg: facts left, people/related/recent right.
- **Relationships are links**: a service shows its customer, server, product
  and invoices as links; a customer shows counts of services/domains/tickets
  that open the filtered lists. An operator should never copy an ID to
  search for the related record.
- **Activity/timeline** for anything with history: who, what, when, reason.

## 5. Forms and settings

- Group with `DetailSection` / `fieldset` + `legend`, not a card per field
  group. Two columns only for short related fields (city/postcode).
- Labels persistent, hints under fields, errors inline and summarised at the
  top of long forms. Save at the bottom-left of the form *and* keep it
  reachable (sticky footer bar for very long settings pages).
- Destructive settings live in the Danger Zone, separated, last.
- Do not autosave consequential settings; do autosave preferences
  (column visibility, rail state) locally.

## 6. Dangerous operations — the confirmation ladder

| Level | Examples | Pattern |
| --- | --- | --- |
| 1 normal | save a field, filter, open | no dialog |
| 2 consequential | suspend, issue invoice, remove contact, restart | `AppConfirm level="consequential"` — what will happen, in a sentence |
| 3 high-risk | impersonate, refund, block IP, change DNS | `level="high-risk"` — plus a reason written to the audit log |
| 4 destructive | terminate, delete server/backup, erase data, revoke cert | `level="destructive"` — reason + type the resource name |

- The dialog says **what happens and what is kept**, not "Are you sure?".
- The confirm button repeats the verb ("Erase personal data"), never "OK".
- Entry points use `danger-subtle`; only the final confirm is solid `danger`.
- Never make a destructive action the default/primary button, never place it
  next to the action it could be mistaken for, never fill a screen with red.

## 7. Search and navigation

- `⌘K / Ctrl+K` command palette (`CommandPalette`) is the fastest route to any
  record; list pages still have their own search.
- The rail groups by operator domain (Business, Operations, Support, System);
  active location is visible in both the rail and the breadcrumb.
- Keep users in context: open related records in place, return to the same
  filtered, scrolled list with Back (Inertia `preserveState`/`preserveScroll`).

## 8. States — design all of them

Default · hover · active · selected · focus · disabled · loading · empty ·
error · success · partial data · permission denied · offline/stale.

- **Empty ≠ error ≠ no permission.** `EmptyState` says what will appear and how
  to make it appear; `ErrorState` says what failed and carries the correlation
  ID; `ErrorState kind="permission"` is neutral. A permission-hidden block is
  omitted (server sends `null`), not shown empty.
- **Loading** keeps the shape (`AppTableSkeleton`) so nothing jumps.
- **Partial data**: show what arrived, mark what did not (`—` plus a note),
  never block the page on the slowest widget.
- **Success**: flash message via the layout (`flash.status`), and the changed
  thing visibly changed. No confetti.
- **Long-running operations** appear in the Operations drawer with their
  correlation ID (ADR 0032); the action returns immediately.

## 9. Microcopy

Plain, specific, operator-to-operator. Status words from the status
vocabulary. Numbers with units. Dates in the operator's locale; relative
times ("3 min ago") only beside an absolute one available on hover/title.
All user-facing strings go through translations (`lang/en`, `lang/tr`) —
CLAUDE.md non-negotiable #10.
