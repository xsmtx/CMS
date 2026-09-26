<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import AppAlert from '../Components/AppAlert.vue'
import AppIcon from '../Components/AppIcon.vue'
import CommandPalette, { type Destination } from '../Components/CommandPalette.vue'
import { type IconName } from '../icons'
import AppMenu from '../Components/AppMenu.vue'
import OperationsDrawer from '../Components/OperationsDrawer.vue'
import PageHeader from '../Components/PageHeader.vue'
import LanguageSwitch from '../Components/LanguageSwitch.vue'
import ThemeSwitch from '../Components/ThemeSwitch.vue'
import { useAnchoredPanel } from '../composables/useAnchoredPanel'
import { useBranding } from '../composables/useBranding'
import { usePermissions } from '../composables/usePermissions'
import { useTranslations } from '../composables/useTranslations'

/**
 * Admin shell — a rail and a topbar (Handoff #3 §3).
 *
 * **The rail ships collapsed.** 72px of icons, 248px when somebody opens it,
 * remembered per browser. Collapsed is the right default for a panel whose
 * screens are mostly tables: an operator recognises seven glyphs within a
 * day and gets the width back for the data, and the one who wants labels
 * presses once and never thinks about it again.
 *
 * **The groups keep WHMCS's words**, because the people who will run this
 * have spent years in WHMCS and every minute spent hunting for Invoices is a
 * minute the software costs them. §3's categories — Business, Operations,
 * Support, System — are the *headings* those groups sit under, which is how
 * both can be true at once. A heading appears only when something is under
 * it: a rail advertising Security before a security screen exists would be a
 * rail that lies.
 *
 * Expanded, a group opens **in place**. Collapsed, it opens as a flyout,
 * because 72px has nowhere to put a nested list. Two behaviours because
 * there are two shapes, not because either is a fallback.
 *
 * The topbar carries where you are (breadcrumbs) and the things that belong
 * to the session rather than to the screen: search, appearance, tools, help,
 * account. Nothing on it is page content.
 *
 * Staff open this dozens of times a day, so there is no page transition and
 * no entrance animation. Navigation renders only what the signed-in staff
 * member may reach — hiding is presentation, not authorization, and every
 * destination re-checks the same gate server side.
 */
const props = withDefaults(defineProps<{ heading: string; description?: string }>(), {
  description: undefined,
})

const page = usePage()
const { can, isSuperAdmin } = usePermissions()

// The brand, and its colours written onto the document. Reseller staff see
// their own name and their own accent, on the same deployment.
const { brand } = useBranding()

// Confirmation belongs to the shell rather than to each page: an action that
// redirects has no page left to report on.
const flash = computed(() => page.props.flash)
const user = computed(() => page.props.auth.user)

// Rows the enabled modules contribute. Empty on almost every installation,
// so the group disappears rather than sitting there saying nothing.
const addons = computed(() => page.props.moduleNavigation ?? [])

const help = computed(() => page.props.help ?? {})

// A computed rather than a constant: `t` is declared further down with the
// rest of the composables, and a map built at module scope would read it
// before it exists.
const helpLabels = computed<Record<string, string>>(() => ({
  documentation: t('ui.shell.links.documentation', {}, 'Documentation'),
  support: t('ui.shell.links.support', {}, 'Technical Support'),
  community: t('ui.shell.links.community', {}, 'Community Forums'),
  license: t('ui.shell.links.license', {}, 'License Information'),
  bug: t('ui.shell.links.bug', {}, 'Report a Bug'),
  contact: t('ui.shell.links.contact', {}, 'Contact us'),
}))

/**
 * The footer's three links, in the order they are read.
 *
 * Taken from the same configured set the help menu uses, so a white-label
 * installation points its operators at its own documentation rather than
 * at ours — and a link nobody configured is left out rather than shown
 * pointing nowhere.
 */
const FOOTER_LINKS = ['bug', 'documentation', 'contact'] as const

const footerLinks = computed(() =>
  FOOTER_LINKS.filter((key) => Boolean(help.value[key])).map((key) => ({
    key,
    label: helpLabels.value[key] ?? key,
    href: help.value[key] as string,
  })),
)

const year = new Date().getFullYear()

/**
 * The placeholder face: whatever letters the account already has.
 *
 * No uploaded avatar exists yet and inventing a gravatar request would
 * send every operator's email address to a third party on every page load.
 */
const initials = computed(() => {
  const source = user.value?.name?.trim() || user.value?.email?.trim() || ''
  const words = source.split(/[\s@._-]+/).filter(Boolean)

  return (
    words
      .slice(0, 2)
      .map((word) => word[0])
      .join('') || '?'
  ).toUpperCase()
})

interface NavItem {
  label: string
  href: string
  permission?: string
  /**
   * Owner-only rows. An Administrator holds every staff permission by
   * design, so no permission could mean "owner of this installation".
   */
  superAdmin?: boolean
  /**
   * A second level, shown as a flyout to the side.
   *
   * Two levels and no more. Children inherit their parent's permission
   * rather than declaring their own: a submenu whose rows were reachable
   * when the row that opens them is not would be a gap nobody could see.
   */
  children?: NavItem[]
}

/**
 * §3's sidebar categories. The heading a group sits under in the rail.
 *
 * Security and Automation are named by the handoff and have no group of
 * their own yet — their screens live under Setup and Utilities. They appear
 * here when something is theirs, and not before.
 */
type NavSection = 'Business' | 'Operations' | 'Support' | 'System' | 'Extensions'

interface NavGroup {
  label: string
  /** A group with an href is a link rather than a dropdown. */
  href?: string
  /**
   * Keep the group out of the rail entirely, but leave it in the palette and
   * on the topbar.
   *
   * One group uses this: Setup, whose screens all live on one page now. The
   * rows are still destinations somebody can search for by name — a menu that
   * moved should not make the palette forget where things are.
   */
  hidden?: boolean
  permission?: string
  section: NavSection
  /**
   * The glyph in front of the label.
   *
   * On a group rather than on every row: an icon per link would be forty
   * shapes competing in a dropdown, and the thing an operator navigates by
   * is the group. Inside a panel the words are the affordance.
   */
  icon: IconName
  items?: NavItem[]
}

/**
 * The admin panel map, in WHMCS's order and WHMCS's words.
 *
 * Only the sections that exist are listed. A menu that advertises Reports
 * before a report exists is a menu that lies, so each phase fills in its
 * own rows rather than the whole map being stubbed up front.
 *
 * Setup and Utilities are sub-headed rather than flat, the way WHMCS's are:
 * a dropdown of fourteen undifferentiated links is a list nobody reads.
 */
/*
 * No Dashboard row. The wordmark is the way home — every panel an
 * operator has used works that way, and a menu whose first entry
 * duplicates the logo spends a slot saying nothing.
 */
const { t } = useTranslations()

/**
 * A nav label, in the operator's language.
 *
 * The English string stays here as the fallback rather than only in
 * `lang/en`: this map is also what the breadcrumb compares a page heading
 * against, and a label that rendered as `ui.nav.clients` would break the
 * trail as well as reading as a bug. Primitives take a fallback for the same
 * reason (`useTranslations`), and this map is chrome, not a page.
 */
function nav(key: string, fallback: string): string {
  return t(`ui.nav.${key}`, {}, fallback)
}

const groups = computed<NavGroup[]>(() => [
  {
    label: nav('clients', 'Clients'),
    section: 'Business',
    icon: 'clients',
    items: [
      {
        label: nav('view_search_clients', 'View/Search Clients'),
        href: '/admin/customers',
        permission: 'crm.customers.view',
      },
      {
        label: nav('manage_users', 'Manage Users'),
        href: '/admin/customer-users',
        permission: 'crm.customers.view',
      },
      {
        label: nav('add_new_client', 'Add New Client'),
        href: '/admin/clients/create',
        permission: 'crm.customers.manage',
      },
      {
        label: nav('products_services', 'Products/Services'),
        href: '/admin/services',
        permission: 'services.view',
        // The product types this platform sells, as filters on the one
        // list. A screen of its own per type would drift from the list
        // that already answers the question.
        children: [
          { label: nav('all_products_services', 'All products/services'), href: '/admin/services' },
          {
            label: nav('shared_hosting', 'Shared Hosting'),
            href: '/admin/services?product_type=shared_hosting',
          },
          {
            label: nav('reseller_hosting', 'Reseller Hosting'),
            href: '/admin/services?product_type=reseller',
          },
          { label: nav('vps', 'VPS'), href: '/admin/services?product_type=vps' },
          { label: nav('dedicated', 'Dedicated'), href: '/admin/services?product_type=dedicated' },
          { label: nav('ssl', 'SSL'), href: '/admin/services?product_type=ssl' },
          { label: nav('email', 'Email'), href: '/admin/services?product_type=email' },
        ],
      },
      {
        label: nav('service_addons', 'Service Addons'),
        href: '/admin/services/addons',
        permission: 'services.view',
      },
      {
        label: nav('domain_registrations', 'Domain Registrations'),
        href: '/admin/domains',
        permission: 'domains.view',
      },
      {
        label: nav('cancellation_requests', 'Cancellation Requests'),
        href: '/admin/cancellations',
        permission: 'services.view',
      },
      {
        label: nav('organizations', 'Organizations'),
        href: '/admin/organizations',
        permission: 'organizations.view',
      },
      /*
       * Resellers is gated on `organizations.manage` here, which is a
       * deliberate approximation: the row is presentation, and the screen
       * itself asks `resellers.administer` — a gate rather than a
       * permission, because a reseller's own Administrator holds every staff
       * permission there is. A reseller's staff see the row and get a 403,
       * which is the one case where hiding and authorizing disagree, and it
       * is the safe direction.
       */
      {
        label: nav('resellers', 'Resellers'),
        href: '/admin/resellers',
        permission: 'organizations.manage',
        children: [
          { label: nav('all_resellers', 'All resellers'), href: '/admin/resellers' },
          { label: nav('add_reseller', 'Add reseller'), href: '/admin/resellers/create' },
          { label: nav('performance', 'Performance'), href: '/admin/reports/resellers' },
        ],
      },
    ],
  },
  {
    label: nav('orders', 'Orders'),
    section: 'Business',
    icon: 'orders',
    items: [
      {
        label: nav('list_all_orders', 'List All Orders'),
        href: '/admin/orders',
        permission: 'orders.view',
        children: [
          { label: nav('all_orders', 'All orders'), href: '/admin/orders' },
          { label: nav('pending_orders', 'Pending Orders'), href: '/admin/orders?status=pending' },
          { label: nav('active_orders', 'Active Orders'), href: '/admin/orders?status=active' },
          { label: nav('fraud_orders', 'Fraud Orders'), href: '/admin/orders?status=fraud_review' },
          {
            label: nav('cancelled_orders', 'Cancelled Orders'),
            href: '/admin/orders?status=cancelled',
          },
        ],
      },
      {
        label: nav('add_new_order', 'Add New Order'),
        href: '/admin/orders/add',
        permission: 'orders.manage',
      },
      {
        label: nav('review_queue', 'Review Queue'),
        href: '/admin/orders/review',
        permission: 'orders.view',
      },
    ],
  },
  {
    label: nav('billing', 'Billing'),
    section: 'Business',
    icon: 'billing',
    items: [
      {
        label: nav('transactions_list', 'Transactions List'),
        href: '/admin/transactions',
        permission: 'billing.invoices.view',
        children: [
          { label: nav('all_transactions', 'All transactions'), href: '/admin/transactions' },
          { label: nav('amount_in', 'Amount in'), href: '/admin/transactions?direction=in' },
          { label: nav('amount_out', 'Amount out'), href: '/admin/transactions?direction=out' },
        ],
      },
      {
        label: nav('add_transaction', 'Add Transaction'),
        href: '/admin/transactions/add',
        permission: 'billing.payments.record',
      },
      {
        label: nav('invoices', 'Invoices'),
        href: '/admin/invoices',
        permission: 'billing.invoices.view',
        children: [
          { label: nav('all_invoices', 'All invoices'), href: '/admin/invoices' },
          { label: nav('paid', 'Paid'), href: '/admin/invoices?status=paid' },
          { label: nav('draft', 'Draft'), href: '/admin/invoices?status=draft' },
          { label: nav('unpaid', 'Unpaid'), href: '/admin/invoices?status=unpaid' },
          { label: nav('overdue', 'Overdue'), href: '/admin/invoices?status=overdue' },
          {
            label: nav('partially_paid', 'Partially paid'),
            href: '/admin/invoices?status=partially_paid',
          },
          { label: nav('cancelled', 'Cancelled'), href: '/admin/invoices?status=cancelled' },
          { label: nav('refunded', 'Refunded'), href: '/admin/invoices?status=refunded' },
        ],
      },
      {
        label: nav('gateway_log', 'Gateway Log'),
        href: '/admin/billing/gateway-log',
        permission: 'billing.payments.manage',
      },
      {
        label: nav('unpaid_invoice_sequence', 'Unpaid invoice sequence'),
        href: '/admin/automation/dunning',
        permission: 'automation.view',
      },
      {
        label: nav('currencies', 'Currencies'),
        href: '/admin/catalog/currencies',
        permission: 'catalog.products.view',
      },
      // Under Billing, because an operator looking for money looks here. The
      // reseller roll-up hangs off the Resellers group instead, where the
      // question is about a reseller rather than about the business.
      {
        label: nav('reports', 'Reports'),
        href: '/admin/reports',
        permission: 'billing.invoices.view',
        children: [
          { label: nav('monthly_review', 'Monthly review'), href: '/admin/reports' },
          {
            label: nav('reseller_performance', 'Reseller performance'),
            href: '/admin/reports/resellers',
          },
        ],
      },
    ],
  },
  {
    label: nav('support', 'Support'),
    section: 'Support',
    icon: 'support',
    items: [
      {
        label: nav('support_overview', 'Support Overview'),
        href: '/admin/support/overview',
        permission: 'support.tickets.view',
      },
      {
        label: nav('support_tickets', 'Support Tickets'),
        href: '/admin/support',
        permission: 'support.tickets.view',
        // Only the statuses this platform has. A row against an enum with
        // no such member returns an empty list and blames the operator.
        children: [
          {
            label: nav('all_active_tickets', 'All Active Tickets'),
            href: '/admin/support?status=open',
          },
          { label: nav('open', 'Open'), href: '/admin/support?status=open' },
          {
            label: nav('customer_reply', 'Customer-Reply'),
            href: '/admin/support?status=customer_reply',
          },
          { label: nav('answered', 'Answered'), href: '/admin/support?status=answered' },
          { label: nav('on_hold', 'On Hold'), href: '/admin/support?status=on_hold' },
          { label: nav('closed', 'Closed'), href: '/admin/support?status=closed' },
        ],
      },
      {
        label: nav('open_new_ticket', 'Open New Ticket'),
        href: '/admin/support/create',
        permission: 'support.tickets.manage',
      },
      {
        label: nav('predefined_replies', 'Predefined Replies'),
        href: '/admin/support/replies',
        permission: 'support.tickets.manage',
      },
      {
        label: nav('announcements', 'Announcements'),
        href: '/admin/content/announcements',
        permission: 'content.manage',
      },
      {
        label: nav('knowledge_base', 'Knowledge Base'),
        href: '/admin/content/articles',
        permission: 'content.manage',
      },
    ],
  },
  {
    /*
     * Handoff #2, Phase A. A group of its own rather than three rows under
     * Utilities, because an operator running racks and firewalls lives here all
     * day and a utility is something you visit.
     *
     * Only what exists is listed, like every other group: the Infrastructure
     * Center, topology and DCIM screens arrive with the phases that build them.
     */
    label: nav('infrastructure', 'Infrastructure'),
    section: 'Operations',
    icon: 'servers',
    items: [
      {
        label: nav('explorer', 'Explorer'),
        href: '/admin/resources',
        permission: 'infrastructure.resources.view',
      },
      {
        label: nav('telemetry', 'Telemetry'),
        href: '/admin/resources/telemetry',
        permission: 'infrastructure.telemetry.view',
      },
      {
        label: nav('adapters', 'Adapters'),
        href: '/admin/resources/adapters',
        permission: 'infrastructure.adapters.view',
      },
      {
        // What this installation has decided, next to what it has discovered.
        label: nav('addressing', 'Addressing'),
        href: '/admin/network/addressing',
        permission: 'network.ipam.view',
      },
    ],
  },
  {
    label: nav('utilities', 'Utilities'),
    section: 'System',
    icon: 'utilities',
    items: [
      /*
       * The passwordless way into a server's panel, and a **permission**
       * rather than the owner-only gate.
       *
       * A support agent fixing somebody's mailbox has to get into the panel.
       * The alternative to letting them is emailing them a root password or an
       * API key, which is the thing this platform exists not to do — so the
       * credential stays on the server, the panel issues a short-lived session,
       * and the audit record says who went where.
       *
       * Licence and Import are not here: those two really are about who
       * somebody is, and they live in Setup's owner-only section.
       */
      {
        label: nav('connect', 'Connect'),
        href: '/admin/apps/connect',
        permission: 'infrastructure.connect',
      },
      // WHMCS calls this the Module Queue. It is the same thing: every
      // background operation, what it was for, and what went wrong.
      {
        label: nav('module_queue', 'Module Queue'),
        href: '/admin/operations',
        permission: 'operations.view',
      },
      {
        label: nav('todo_list', 'Todo List'),
        href: '/admin/todo',
        permission: 'platform.health.view',
      },
      {
        label: nav('automation', 'Automation'),
        href: '/admin/automation',
        permission: 'automation.view',
      },
      {
        label: nav('system_health', 'System Health'),
        href: '/admin/health',
        permission: 'platform.health.view',
      },
      {
        label: nav('notification_log', 'Notification Log'),
        href: '/admin/notifications/log',
        permission: 'notifications.view',
      },
      {
        label: nav('api_activity', 'API Activity'),
        href: '/admin/api/activity',
        permission: 'platform.audit.view',
      },
    ],
  },
  {
    /*
     * Setup is a page, and it is not in the rail at all.
     *
     * A menu of eight undifferentiated links tells an operator the names of
     * eight screens; the page tells them what each is for and how much is in
     * it. Everything that was in this dropdown is a tile there, plus the
     * owner-only screens — Modules, Servers, Licence, Import.
     *
     * It hangs off the spanner in the topbar instead, with the other things
     * somebody configures once and then forgets. The rail is for the screens
     * an operator works in all day, and Setup is not one of them.
     *
     * The rows stay in this map even though nothing draws them, because the
     * command palette is built from it: somebody who knows they want Roles
     * should be able to press Cmd-K and type it rather than learning where it
     * moved. `hidden` is what keeps them out of the rail and in the palette.
     */
    label: nav('setup', 'Setup'),
    href: '/admin/apps',
    section: 'System',
    icon: 'setup',
    hidden: true,
    items: [
      {
        label: nav('products', 'Products'),
        href: '/admin/catalog/products',
        permission: 'catalog.products.view',
      },
      {
        label: nav('product_groups', 'Product Groups'),
        href: '/admin/catalog/groups',
        permission: 'catalog.groups.view',
      },
      {
        label: nav('promotions', 'Promotions'),
        href: '/admin/promotions',
        permission: 'promotions.view',
      },
      {
        label: nav('domain_extensions', 'Domain Extensions'),
        href: '/admin/catalog/tlds',
        permission: 'domains.tlds.manage',
      },
      {
        label: nav('staff_members', 'Staff Members'),
        href: '/admin/staff',
        permission: 'identity.staff.view',
      },
      { label: nav('roles', 'Roles'), href: '/admin/roles', permission: 'access.roles.view' },
      {
        label: nav('general_settings', 'General Settings'),
        href: '/admin/settings',
        permission: 'settings.view',
      },
      {
        label: nav('notification_templates', 'Notification Templates'),
        href: '/admin/notifications/templates',
        permission: 'notifications.view',
      },
      {
        label: nav('apps_integrations', 'Apps & Integrations'),
        href: '/admin/apps',
        superAdmin: true,
      },
      {
        label: nav('marketplace', 'Marketplace'),
        href: '/admin/apps/marketplace',
        superAdmin: true,
      },
      { label: nav('tax', 'Tax'), href: '/admin/tax', superAdmin: true },
      {
        label: nav('billing_terms', 'Billing Terms'),
        href: '/admin/billing/settings',
        superAdmin: true,
      },
      { label: nav('licence', 'Licence'), href: '/admin/licence', superAdmin: true },
      { label: nav('import', 'Import'), href: '/admin/import', superAdmin: true },
    ],
  },
])

/**
 * The map, plus whatever the enabled modules added.
 *
 * Appended rather than woven in: a module cannot put a row next to Billing,
 * because a row that looked like Billing would be indistinguishable from
 * the platform's own.
 */
const withAddons = computed<NavGroup[]>(() =>
  addons.value.length === 0
    ? groups.value
    : // A module's rows get the extension glyph, not one of their own. A
      // module choosing its own icon could choose Billing's.
      [
        ...groups.value,
        {
          label: nav('addons', 'Addons'),
          section: 'Extensions' as const,
          icon: 'modules' as const,
          items: addons.value,
        },
      ],
)

function isVisible(item: NavItem): boolean {
  return (!item.superAdmin || isSuperAdmin.value) && (!item.permission || can(item.permission))
}

const visibleGroups = computed(() =>
  withAddons.value
    .map((group) => ({ ...group, items: (group.items ?? []).filter(isVisible) }))
    .filter(
      (group) =>
        (group.href !== undefined && isVisible(group as NavItem)) || group.items.length > 0,
    ),
)

/**
 * Every screen the palette can take you to, flattened from the menu.
 *
 * Built from the same map rather than listed again: a palette with its own
 * list of destinations is a second menu, and the two drift the first time
 * somebody adds a screen to one of them. Permissions come free for the same
 * reason — a row that opens a 403 is worse than a row that is not there.
 */
const destinations = computed<Destination[]>(() =>
  visibleGroups.value.flatMap((group) =>
    group.items.flatMap((item) => [
      { label: item.label, href: item.href, group: group.label, icon: group.icon },
      ...(item.children ?? []).map((child) => ({
        label: child.label,
        href: child.href,
        group: item.label,
        icon: group.icon,
      })),
    ]),
  ),
)

const currentPath = computed(() => page.url.split('?')[0] ?? '/')

function hrefsOf(group: NavGroup): string[] {
  const items = (group.items ?? []).flatMap((item) => [
    item.href,
    ...(item.children ?? []).map((c) => c.href),
  ])

  if (group.href === undefined) return items

  // A group that is both a link and a set of screens — Setup — lights up on
  // any of them. Without the items here, an operator editing a role would see
  // nothing selected in the rail and have to work out where they were.
  return [group.href, ...items]
}

/**
 * The longest matching destination wins.
 *
 * `/admin/orders` is a prefix of `/admin/orders/review`, and without this
 * both light up at once — which tells the operator nothing about where
 * they are.
 */
const currentHref = computed(() => {
  const candidates = groups.value
    .flatMap(hrefsOf)
    .filter(
      (href) =>
        currentPath.value === href ||
        (href !== '/admin' && currentPath.value.startsWith(`${href}/`)),
    )

  return candidates.sort((a, b) => b.length - a.length)[0] ?? null
})

function isCurrent(href: string): boolean {
  return currentHref.value === href
}

function isCurrentGroup(group: NavGroup): boolean {
  return currentHref.value !== null && hrefsOf(group).includes(currentHref.value)
}

const openGroup = ref<string | null>(null)
const mobileOpen = ref(false)

/**
 * Groups in the order §3 puts their headings, with the heading attached.
 *
 * Built from `visibleGroups` so a group its owner cannot reach takes its
 * heading with it when it is the last one under it.
 */
const SECTIONS: NavSection[] = ['Business', 'Operations', 'Support', 'System', 'Extensions']

const navSections = computed(() =>
  SECTIONS.map((section) => ({
    section,
    // The heading an operator reads, rather than the discriminator the type
    // uses. They were the same string until this map had a second language.
    label: nav(`sections.${section.toLowerCase()}`, section),
    groups: visibleGroups.value.filter(
      (group) => group.section === section && group.hidden !== true,
    ),
  })).filter((entry) => entry.groups.length > 0),
)

/**
 * Setup, for the topbar, and only for somebody who can open something on it.
 *
 * The group carries no permission of its own — its rows do — so "can they
 * reach it" is "is there a row left after filtering". A link to a page that
 * answers 403 is worse than no link.
 */
const setupDestination = computed(
  () =>
    visibleGroups.value.find((group) => group.hidden === true && group.items.length > 0) ?? null,
)

/**
 * What hangs off the spanner: the things configured once rather than worked
 * in. Each row carries its own answer to "may they", so the menu is drawn only
 * when it has something on it — it used to be owner-only as a whole, which is
 * no longer true of either Setup or Connect.
 */
const tools = computed(() => {
  const items: { label: string; href: string }[] = []

  if (setupDestination.value !== null) items.push({ label: 'Setup', href: '/admin/apps' })

  if (can('infrastructure.connect')) {
    items.push({ label: 'Connect', href: '/admin/apps/connect' })
  }

  if (can('platform.audit.view')) {
    items.push({ label: 'System logs', href: '/admin/api/activity' })
  }

  return items
})

/**
 * Where you are, as words.
 *
 * Section › group › screen, and the screen's own name comes from the
 * heading the page passed rather than from the map — a detail page
 * ("Acme Ltd") has no entry in a menu, and the breadcrumb is the only place
 * that says which record you are looking at.
 */
function differs(a: string, b: string): boolean {
  // Lowercased rather than compared with a collator: a runtime built without
  // the full ICU data answers every collator with a plain byte comparison,
  // and the duplicate comes back with nothing saying why.
  return a.toLocaleLowerCase() !== b.toLocaleLowerCase()
}

const breadcrumbs = computed(() => {
  const trail: { label: string; href?: string }[] = []

  for (const group of visibleGroups.value) {
    const item = group.items.find(
      (row) =>
        row.href === currentHref.value ||
        (row.children ?? []).some((child) => child.href === currentHref.value),
    )

    if (item === undefined) continue

    trail.push({ label: group.label })

    // Case-insensitively: the map says "Review Queue" and the page heading says
    // "Review queue", which is one name and would otherwise be two crumbs.
    if (differs(item.label, props.heading)) trail.push({ label: item.label, href: item.href })

    break
  }

  return trail
})

/**
 * The nav's dropdown, teleported out of the bar.
 *
 * `fixed`, against the trigger's own rectangle, outside every ancestor's
 * overflow — because the bar is translucent and `backdrop-filter` creates a
 * containing block, so an `absolute` panel inside it would be clipped by the
 * very thing that makes it look like glass. `useAnchoredPanel` is the one
 * place that rule lives.
 */
const flyout = useAnchoredPanel({ side: 'bottom', width: '15.5rem' })

// Template refs and bindings want a setup-scope name, so the pieces the
// template touches are named here rather than reached through `flyout.`.
const flyoutOpen = flyout.open
const flyoutPanel = flyout.panel
const flyoutStyle = flyout.style

/** The group the dropdown is showing, or null when it is shut. */
const flyoutGroup = computed(
  () => visibleGroups.value.find((group) => group.label === openGroup.value) ?? null,
)

// Click rather than hover: a hover menu is unreachable on a touch screen and
// unforgiving with a trackpad.
function toggle(label: string, event?: Event): void {
  const next = openGroup.value === label ? null : label

  openGroup.value = next

  if (next !== null && event?.currentTarget instanceof HTMLElement) {
    flyout.trigger.value = event.currentTarget
  }

  flyout.open.value = next !== null
}

function close(): void {
  openGroup.value = null
  flyout.open.value = false
}

// The panel closes itself on an outside press or on Escape, and the group
// has to go with it. Two pieces of state that disagree is a flyout that
// will not reopen until you press something else first.
watch(flyoutOpen, (isOpen) => {
  if (!isOpen) openGroup.value = null
})

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
    mobileOpen.value = false
  }
}

let stopNavigation: (() => void) | null = null

onMounted(() => {
  document.addEventListener('keydown', onKeydown)

  // A menu still hanging open over the page it has just navigated to is the
  // commonest way a top nav feels broken.
  stopNavigation = router.on('navigate', () => {
    close()
    mobileOpen.value = false
  })
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  stopNavigation?.()
})
</script>

<template>
  <div class="text-content min-h-dvh">
    <a
      href="#main"
      class="focus:bg-surface-elevated sr-only rounded-sm focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-40 focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
      {{ t('ui.shell.skip', {}, 'Skip to content') }}
    </a>

    <!--
      Below `lg` the groups do not fit on a line, so they become one sheet
      under the bar: every group, every destination, nothing hidden behind a
      second press. It is a long list and that is correct - a phone scrolls.
    -->
    <div
      v-if="mobileOpen"
      class="bg-background/70 fixed inset-0 z-20 lg:hidden"
      @click="mobileOpen = false"
    />

    <div
      v-if="mobileOpen"
      class="on-chrome bg-surface-chrome fixed inset-x-0 top-11 z-30 max-h-[80dvh] overflow-y-auto px-4 pt-2 pb-6 lg:hidden"
    >
      <div v-for="entry in navSections" :key="entry.section" class="mb-5 last:mb-0">
        <p class="text-content-subtle text-label px-1 pb-1.5 uppercase">{{ entry.label }}</p>
        <ul class="space-y-0.5">
          <li v-for="item in entry.groups.flatMap((group) => group.items)" :key="item.label">
            <Link
              :href="item.href"
              :aria-current="isCurrent(item.href) ? 'page' : undefined"
              class="pressable text-body block rounded-sm px-2 py-2 transition-colors duration-(--duration-fast)"
              :class="
                isCurrent(item.href)
                  ? 'bg-surface-hover text-content font-medium'
                  : 'text-content-muted hover:bg-surface-hover hover:text-content'
              "
            >
              {{ item.label }}
            </Link>
          </li>
        </ul>
      </div>
    </div>

    <!--
      A group opens as a dropdown under its button. One panel rather than one
      per group: only one can be open, and a panel per button would be seven
      hidden panels on every page load.

      Teleported to the body and positioned `fixed`, which is the only
      placement no ancestor's `overflow` can clip.
    -->
    <Teleport to="body">
      <div
        v-if="flyoutOpen && flyoutGroup"
        ref="flyoutPanel"
        data-rail-flyout
        :style="flyoutStyle"
        class="panel-enter floating text-content z-50 rounded-lg p-1.5"
      >
        <p class="text-content-subtle text-label px-2 pt-1 pb-1.5 uppercase">
          {{ flyoutGroup.label }}
        </p>
        <!--
          A destination with children is a heading and its rows, not a row
          that expands. The rail made somebody press twice to reach a
          filtered list; a menu that is already open has nothing to gain by
          hiding half of itself, and the parent's own href is always the
          first child's anyway.
        -->
        <ul class="max-h-[70dvh] space-y-0.5 overflow-y-auto">
          <template v-for="item in flyoutGroup.items" :key="item.label">
            <li v-if="item.children">
              <p class="text-content-subtle text-label px-2.5 pt-2 pb-1 uppercase">
                {{ item.label }}
              </p>
              <ul class="space-y-0.5">
                <li v-for="child in item.children" :key="child.href">
                  <Link
                    :href="child.href"
                    :aria-current="isCurrent(child.href) ? 'page' : undefined"
                    class="pressable text-body block rounded-sm border-l-2 px-2.5 py-1.5 whitespace-nowrap transition-colors duration-(--duration-fast)"
                    :class="
                      isCurrent(child.href)
                        ? 'bg-surface-hover text-content border-accent font-medium'
                        : 'text-content-muted hover:bg-surface-hover hover:text-content border-transparent'
                    "
                  >
                    {{ child.label }}
                  </Link>
                </li>
              </ul>
            </li>
            <li v-else>
              <Link
                :href="item.href"
                :aria-current="isCurrent(item.href) ? 'page' : undefined"
                class="pressable text-body block rounded-sm border-l-2 px-2.5 py-1.5 whitespace-nowrap transition-colors duration-(--duration-fast)"
                :class="
                  isCurrent(item.href)
                    ? 'bg-surface-hover text-content border-accent font-medium'
                    : 'text-content-muted hover:bg-surface-hover hover:text-content border-transparent'
                "
              >
                {{ item.label }}
              </Link>
            </li>
          </template>
        </ul>
      </div>
    </Teleport>

    <div class="flex min-h-dvh flex-col">
      <!--
        `global-nav`: where you can go, and what belongs to the session.
        Black, 44px, 12px links, and translucent — the bar is the one piece of
        chrome that never scrolls away, so it has to let the page show through
        rather than sit on top of it like a lid.
      -->
      <header
        data-admin-nav
        class="on-chrome bg-surface-chrome/85 sticky top-0 z-30 h-11 backdrop-blur-xl"
        :aria-label="t('ui.shell.sections', {}, 'Sections')"
      >
        <div class="mx-auto flex h-full max-w-[1600px] items-center gap-1 px-4 sm:px-6">
          <!-- The way home, and the only one. -->
          <Link
            href="/admin"
            :aria-current="currentPath === '/admin' ? 'page' : undefined"
            :title="`${brand.name} dashboard`"
            class="pressable mr-2 flex shrink-0 items-center gap-2 rounded-sm"
          >
            <img
              v-if="brand.logoUrl"
              :src="brand.logoUrl"
              :alt="brand.name"
              class="h-4 w-auto max-w-[8rem] object-contain"
            />
            <span v-else class="text-chrome font-semibold">{{ brand.name }}</span>
          </Link>

          <button
            type="button"
            class="pressable text-content-muted hover:text-content rounded-sm p-1.5 lg:hidden"
            :aria-expanded="mobileOpen"
            :aria-label="t('ui.shell.sections', {}, 'Sections')"
            @click="mobileOpen = !mobileOpen"
          >
            <AppIcon name="more" :size="16" />
          </button>

          <!--
            The groups. Below `lg` they collapse into the sheet, because
            seven of them on one line is a nav that wraps, and a two-line nav
            at desktop is broken design.
          -->
          <nav class="hidden min-w-0 flex-1 items-center gap-0.5 lg:flex">
            <button
              v-for="group in navSections.flatMap((entry) => entry.groups)"
              :key="group.label"
              type="button"
              class="pressable text-chrome rounded-sm px-2.5 py-1.5 whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
              :class="
                isCurrentGroup(group)
                  ? 'text-content font-medium'
                  : 'text-content-muted hover:text-content'
              "
              :aria-expanded="openGroup === group.label"
              @click="toggle(group.label, $event)"
            >
              {{ group.label }}
            </button>
          </nav>

          <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
            <CommandPalette :destinations="destinations" />

            <!-- What the platform is doing, and what went wrong (§8). It draws
               nothing at all for somebody who may not see operations. -->
            <OperationsDrawer />

            <LanguageSwitch url="/admin/locale" />
            <ThemeSwitch />

            <!-- The spanner: what an installation is configured to be, rather
               than what somebody works in. Drawn only when there is something
               on it, because each row now answers for itself. -->
            <AppMenu
              v-if="tools.length > 0"
              :label="t('ui.shell.tools', {}, 'Tools')"
              align="end"
              width="15rem"
              icon="utilities"
            >
              <Link
                v-for="tool in tools"
                :key="tool.href"
                :href="tool.href"
                class="pressable hover:bg-surface-secondary text-body block rounded-sm px-2 py-1.5"
                role="menuitem"
              >
                {{ tool.label }}
              </Link>
            </AppMenu>

            <!-- Where to get help. Every link is configurable, because a
               white-label installation sends its operators to its own
               documentation, not to ours. -->
            <AppMenu :label="t('ui.shell.help', {}, 'Help')" align="end" width="15rem" icon="help">
              <a
                v-for="(url, key) in help"
                :key="key"
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="pressable hover:bg-surface-secondary text-body block rounded-sm px-2 py-1.5"
                role="menuitem"
              >
                {{ helpLabels[key] ?? key }}
              </a>
              <p
                v-if="Object.keys(help).length === 0"
                class="text-content-muted text-chrome px-2 py-1.5"
              >
                {{
                  t('ui.shell.no_help', {}, 'No help links are configured for this installation.')
                }}
              </p>
            </AppMenu>

            <!-- A face rather than an address. An email read across the top of
               every page is somebody's identifier on a screen other people
               walk past, and it told an operator nothing they did not
               already know. -->
            <AppMenu v-if="user" :label="initials" align="end" width="14rem" avatar>
              <p class="border-line mb-1 border-b px-2 pb-2">
                <span class="text-body block truncate font-medium">{{ user.name }}</span>
                <span class="text-content-muted text-chrome block truncate">{{ user.email }}</span>
              </p>
              <Link
                href="/admin/security"
                class="pressable hover:bg-surface-secondary text-body block rounded-sm px-2 py-1.5"
                role="menuitem"
              >
                {{ t('ui.shell.my_account', {}, 'My Account') }}
              </Link>
              <a
                href="/client"
                class="pressable hover:bg-surface-secondary text-body block rounded-sm px-2 py-1.5"
                role="menuitem"
              >
                {{ t('ui.shell.client_area', {}, 'Visit Client Area') }}
              </a>
              <Link
                href="/admin/logout"
                method="post"
                as="button"
                class="pressable hover:bg-surface-secondary text-body block w-full rounded-sm px-2 py-1.5 text-left"
                role="menuitem"
              >
                {{ t('ui.shell.sign_out', {}, 'Sign out') }}
              </Link>
            </AppMenu>
          </div>
        </div>
      </header>

      <!--
        `sub-nav-frosted`: where you are. A second bar rather than a line in
        the first, because the first one is a fixed set of destinations and
        this one changes on every page - and putting both in one bar is how a
        nav ends up two lines tall.
      -->
      <div class="bg-background/80 sticky top-11 z-20 backdrop-blur-xl">
        <div class="mx-auto flex h-13 max-w-[1600px] items-center px-4 sm:px-6">
          <nav :aria-label="t('ui.shell.breadcrumb', {}, 'Breadcrumb')" class="min-w-0">
            <ol class="text-chrome flex items-center gap-1.5">
              <li v-for="crumb in breadcrumbs" :key="crumb.label" class="flex items-center gap-1.5">
                <component
                  :is="crumb.href ? Link : 'span'"
                  :href="crumb.href"
                  class="text-content-subtle hover:text-content truncate transition-colors duration-(--duration-fast)"
                >
                  {{ crumb.label }}
                </component>
                <AppIcon name="chevronRight" :size="11" class="text-content-subtle" />
              </li>
              <li class="text-content min-w-0 truncate font-medium">{{ heading }}</li>
            </ol>
          </nav>
        </div>
      </div>

      <main id="main" class="flex-1 px-4 pt-5 pb-10 sm:px-6">
        <div class="mx-auto max-w-[110rem]">
          <!--
            Title, state and actions in one band (PageHeader). A screen that
            needs more — a resource's status and identity — passes its own
            header; the rest get this one from `heading`.
          -->
          <div class="mb-5">
            <slot name="header">
              <PageHeader :title="heading" :description="description">
                <template v-if="$slots.status" #status><slot name="status" /></template>
                <template v-if="$slots.meta" #meta><slot name="meta" /></template>
                <template v-if="$slots.actions" #actions><slot name="actions" /></template>
              </PageHeader>
            </slot>
          </div>

          <AppAlert v-if="flash?.error" tone="danger" class="mb-5">{{ flash.error }}</AppAlert>
          <AppAlert v-else-if="flash?.status" tone="success" class="mb-5">
            {{ flash.status }}
          </AppAlert>

          <slot />
        </div>
      </main>

      <!--
        At the end of the document, not fixed. A fixed footer cost every
        screen 36px of rows to repeat a copyright line; the links on it are
        also on the Help menu, which *is* always on screen.
      -->
      <footer
        class="border-line text-content-subtle text-label flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-4 py-2.5 sm:px-6"
      >
        <p>&copy; {{ year }} {{ brand.name }}</p>

        <nav
          v-if="footerLinks.length > 0"
          :aria-label="t('ui.shell.help', {}, 'Help')"
          class="flex items-center gap-2"
        >
          <template v-for="(link, index) in footerLinks" :key="link.key">
            <span v-if="index > 0" aria-hidden="true">|</span>
            <a
              :href="link.href"
              target="_blank"
              rel="noopener noreferrer"
              class="hover:text-content underline-offset-4 transition-colors duration-(--duration-fast) hover:underline"
            >
              {{ link.label }}
            </a>
          </template>
        </nav>
      </footer>
    </div>
  </div>
</template>
