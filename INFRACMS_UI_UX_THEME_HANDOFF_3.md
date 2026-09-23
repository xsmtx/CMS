# InfraCMS --- UI/UX & Theme System Handoff #3

**Purpose:** Visual system and three first-party themes for InfraCMS.
Read Handoff #1 and #2 first.

## 0. Design contract

-   Build one semantic design system, not three unrelated frontends.
-   Themes change more than colors: density, geometry, navigation,
    cards, tables, charts and composition may differ.
-   Business logic is theme-independent.
-   Components consume semantic tokens; never scatter hardcoded theme
    colors.
-   Support Light/Dark/System where declared.
-   Operational colors remain semantically consistent.
-   WCAG accessibility, keyboard navigation, visible focus,
    screen-reader labels, reduced motion and non-color status cues are
    mandatory.
-   White-label overrides brand tokens, never critical operational
    semantics.
-   Avoid gaming/crypto aesthetics, generic admin-template appearance,
    excessive gradients/glassmorphism and decorative animation in
    operational screens.

## 1. Theme architecture

First-party themes:

1.  **InfraCMS Core** --- default; enterprise/cloud, balanced and
    universal.
2.  **InfraCMS NOC** --- dark-first, compact, high-density operations.
3.  **InfraCMS Horizon** --- spacious, premium, customer/storefront
    oriented.

Structure:

``` text
themes/
├── infracms-core/
├── infracms-noc/
└── infracms-horizon/
```

Each provides `manifest.json`, `tokens.css`, optional component/layout
overrides, assets, preview and `settings.schema.json`.

Override order:
`Brand Override → Child Theme → Selected Theme → Core Component Fallback`.

Manifest declares version, platform compatibility, supported surfaces
(`admin/client/reseller/storefront`) and appearances.

## 2. Semantic tokens

Required families:

``` text
background / background-subtle
surface-primary / secondary / elevated / hover / selected
border-default / subtle / strong
text-primary / secondary / muted / inverse
brand-primary / primary-hover / accent
status-success / warning / danger / info / maintenance / unknown
focus-ring
shadow-*
radius-*
space-*
```

Components use `var(--surface-primary)` etc.

Module accents: Billing Blue, Infrastructure Cyan, Network Indigo,
Security Red, Automation/AI Violet, DNS Sky, Storage/Backup Teal. Use as
accents, not large fills.

# 3. InfraCMS Core --- Default

Personality: **Enterprise / Cloud / Calm / Technical / Balanced**.

Dark:

``` text
Background #080D17   Sidebar #0C1424
Surface #111C2E      Elevated #17243A
Hover #1C2B44        Border #253550
Primary #3B82F6      Primary Hover #60A5FA
Accent #22D3EE
Text #F1F5F9         Secondary #94A3B8   Muted #64748B
Success #10B981      Warning #F59E0B     Danger #EF4444
Info #38BDF8         Automation #8B5CF6
```

Light:

``` text
Background #F8FAFC   Subtle #F1F5F9
Surface #FFFFFF      Border #E2E8F0
Primary #2563EB      Hover #1D4ED8       Accent #0891B2
Text #0F172A         Secondary #475569   Muted #64748B
```

Geometry: cards 10px, buttons/inputs 8px, badges 6px, modals 12px. Avoid
excessive pills.

Desktop uses 248px collapsible sidebar (72px collapsed) plus topbar.
Topbar: breadcrumbs, global search, quick action, health, notifications,
appearance, user. Sidebar groups Business, Operations, Security,
Support, Automation, System.

Dashboard prioritizes grouped information rather than a card per metric:
`Active Services / MRR / Tickets / Incidents`, Infrastructure Health,
revenue trend, recent activity and Attention Required.

# 4. InfraCMS NOC

Personality: **Dense / Precise / Operational / Low-distraction /
Real-time**. Dark-first, never a neon hacker theme.

Palette:

``` text
Background #05080D   Sidebar #080C13
Surface #0C121B      Elevated #111925
Hover #162131        Border #1E2A3A
Primary #38BDF8      Accent #2DD4BF
Text #E6EDF6         Secondary #9AA8BA   Muted #64748B
Healthy #22C55E      Warning #F59E0B     Critical #F43F5E
Maintenance #3B82F6  Unknown #64748B     Automation #A78BFA
```

Geometry: 6px radius, compact rows and controls.

Use an icon rail + contextual navigation where useful. Dashboard
prioritizes Global Health, active P1/P2/P3 incidents, network, compute,
storage, backups, security and a live event stream; financial cards are
secondary.

NOC tables: sticky headers, resizable/choosable columns, saved views,
compact filters, keyboard navigation, bulk actions and live-update
indicator.

Provide `/wallboard`: no sidebar, large status typography, fullscreen,
region/DC filters, optional rotating views, no destructive actions.

# 5. InfraCMS Horizon

Personality: **Spacious / Premium / Friendly / Modern SaaS /
White-label**. Preferred reference theme for Client Area and Storefront.

Light:

``` text
Background #F6F8FC   Surface #FFFFFF      Secondary #F0F4FA
Border #DCE4EF       Primary #4F46E5     Hover #4338CA
Accent #06B6D4       Text #111827         Secondary #4B5563
Muted #7C8798        Success #059669      Warning #D97706
Danger #DC2626       Info #0284C7
```

Dark:

``` text
Background #0B1020   Surface #121A2B      Secondary #172136
Border #26344D       Primary #818CF8      Accent #22D3EE
Text #F8FAFC         Secondary #B2BED0    Muted #7C8AA0
```

Geometry: cards 16px, buttons/inputs 10px, modals 18px; more whitespace.
Very subtle gradients only in brand/hero areas.

Client dashboard emphasizes service cards, human-readable health,
upcoming invoices/domain renewals and support. Prefer "Your server is
online" over provider jargon.

Storefront pages: landing, product categories/pricing, domain search,
configurator, cart, checkout, auth, status, KB and legal. Product cards
are restrained; no fake countdowns or manipulative dark patterns.

# 6. Typography, spacing & density

Use a modern accessible sans-serif; technical identifiers selectively
use monospace (IP/CIDR/MAC/ASN/UUID/config). Use tabular numerals for
live metrics where supported.

Spacing scale: `4, 8, 12, 16, 20, 24, 32, 40, 48, 64`.

Density presets: Comfortable / Default / Compact. Core=Default,
NOC=Compact, Horizon=Comfortable. User density preference should be
independent where practical.

# 7. Component rules

Buttons: Primary, Secondary, Ghost, Danger, Link, Icon. One obvious
primary action per local context; destructive is never default.

Forms: persistent labels, help text, inline validation, error summary
for long forms, keyboard-first, autocomplete, copy/reveal controls.
Never use placeholder as the sole label.

Cards: Metric, Resource, Summary, Alert, Action, Chart, Timeline,
Configuration. Avoid nested-card clutter.

Status always uses icon/shape + text + color, e.g. `● Healthy`,
`▲ Warning`, `◆ Maintenance`, `○ Unknown`. Never color alone.

Tables/data grids support appropriate sorting, filtering, search,
pagination, selection, bulk actions, saved views, column visibility,
sticky headers and responsive alternatives. IDs/IPs are easy to copy.
Destructive bulk actions require confirmation.

Charts: restrained grid/axes, accessible tooltips, no 3D/pie explosion
effects, status colors only for status. Prefer line/area for time
series, bars for comparison, stacked bars for composition, sparklines
for compact trends. Do not use donut charts for everything.

# 8. Core interaction patterns

Global Command Palette: `Ctrl/Cmd+K`, search
customer/domain/IP/service/server/VM/invoice/ticket/incident/change/device
and show permitted quick actions.

Right-side Context Drawer for quick inspection without losing list
context. Full page for complex management.

Background Operations drawer shows running/retrying/failed/completed
operations with correlation ID and safe error details.

Danger Zone is visually separated at the bottom of resource settings.

Confirmation levels: 1. normal action --- direct; 2. consequential ---
confirm; 3. high-risk --- confirm + reason; 4. destructive/infra ---
step-up auth/approval as policy requires.

# 9. Key screen templates

Resource List: title/actions → filters/search → summary strip →
table/grid. Resource Detail: identity/status/actions → tabs → overview →
relationships/activity. Incident: severity/status/acknowledge → impact →
timeline → affected resources/customers → comms → runbook → changes →
resolution/postmortem. Network Device: identity/health →
interfaces/traffic → policies/routes/VLAN → events → config
revisions/changes. Customer 360: profile/health →
services/domains/billing/tickets → unified timeline. Service:
status/plan/actions → metrics → management module tabs →
billing/activity.

# 10. Responsive web

Breakpoints follow content needs, not device marketing names. On small
screens sidebar becomes drawer/bottom navigation depending surface;
dense tables become card/list or horizontal-scroll with frozen identity;
charts simplify; primary actions remain reachable. Never hide critical
status merely to fit.

# 11. Mobile app visual language

Native iOS/Android apps reuse brand/status tokens but follow platform
navigation conventions.

Customer mobile: bottom navigation
`Home / Services / Billing / Support / More`. Staff/NOC mobile:
`Overview / Incidents / Search / Tasks / More`.

Mobile cards are touch-first; minimum comfortable targets; swipe actions
only when discoverable and never for destructive operations without
confirmation. Push deep links land on the exact authorized resource.

Datacenter technician mode prioritizes high contrast, large controls,
barcode/QR scan, rack/server identity, task checklist and
poor-connectivity behavior.

# 12. White-label Theme Studio

Admin → Appearance → Theme Studio:

``` text
Theme
Appearance
Logo / Compact Logo / Favicon
Primary Brand
Accent
Typography
Radius preset
Density
Login artwork/background
Storefront hero settings
Email branding
Invoice branding
Custom CSS (advanced warning)
```

Provide live previews for Login, Dashboard, Client Service,
Invoice/Email and Storefront.

Brand override must run automatic contrast checks and reject/warn on
inaccessible combinations. Operational success/warning/danger semantics
cannot be freely rebranded.

# 13. Icons, motion & imagery

Use one consistent outline icon family. Do not mix icon styles.
Security/critical icons are semantic, not decorative.

Motion durations roughly 120--220ms for common UI. Respect
`prefers-reduced-motion`. No animated live charts solely for spectacle.

Storefront may use abstract infrastructure/network illustrations;
Admin/NOC should use data, topology and real resource context instead of
stock imagery.

# 14. Accessibility

Target WCAG 2.2 AA. Requirements include contrast, full keyboard access,
visible focus, skip links, semantic headings/landmarks, labeled icons,
accessible dialogs, screen-reader table semantics, chart summaries,
non-color state cues and 200% zoom usability.

# 15. Theme performance

Theme switching must not reload business data. Avoid huge CSS bundles
per theme. Lazy-load heavy visualization components. Prevent layout
shift from fonts/icons. Store theme preference server-side for
authenticated users and locally for pre-login surfaces.

# 16. Theme SDK

Third-party themes may override tokens/layout/component presentation
through documented extension points, but cannot replace
authorization/business actions. Define compatibility/versioning and
preview screenshots. Theme installation validates manifest and
prohibited unsafe constructs.

# 17. Suggested implementation phases

**T0 Foundation:** semantic tokens, base primitives, accessibility,
Storybook/component showcase or equivalent. **T1 Core:** Admin shell,
Client shell, primary components, light/dark/system. **T2 Data UI:**
tables, filters, charts, drawers, activity/timeline, command palette.
**T3 NOC:** compact shell, operations dashboard, dense grids, wallboard.
**T4 Horizon:** client/storefront shells, product/service cards,
checkout/auth. **T5 Theme Studio:** white-label token editor, previews,
contrast validation. **T6 Mobile alignment:** export/shared token
definitions, mobile component guidelines. **T7 Theme SDK:** manifests,
child themes, compatibility and developer docs.

# 18. Definition of Done

A theme is complete only when: - all supported surfaces render
correctly; - light/dark modes declared by manifest work; - accessibility
checks pass; - keyboard navigation works; -
loading/empty/error/permission-denied/offline states are designed; -
responsive behavior is tested; - operational statuses are unambiguous; -
charts/tables remain usable with realistic data volumes; - white-label
overrides pass contrast checks; - no business logic is duplicated in
theme code; - visual regression/component tests exist for critical
primitives; - theme documentation and screenshots are updated.

# 19. Claude Code first instruction

Before implementation: 1. Read all three handoffs. 2. Create
`docs/design/design-system.md`. 3. Create token taxonomy and theme
manifests. 4. Build a component inventory and screen matrix by
surface/theme. 5. Create ADR for UI component strategy and
typography/icon choices. 6. Implement only T0, then InfraCMS Core T1. 7.
Do not build NOC/Horizon until Core primitives and accessibility
baseline are stable.
