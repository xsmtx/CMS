<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import AppAlert from '../Components/AppAlert.vue'
import AppIcon from '../Components/AppIcon.vue'
import CommandPalette, { type Destination } from '../Components/CommandPalette.vue'
import { type IconName } from '../icons'
import AppMenu from '../Components/AppMenu.vue'
import OperationsDrawer from '../Components/OperationsDrawer.vue'
import ThemeSwitch from '../Components/ThemeSwitch.vue'
import { useAnchoredPanel } from '../composables/useAnchoredPanel'
import { useBranding } from '../composables/useBranding'
import { usePermissions } from '../composables/usePermissions'

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

const HELP_LABELS: Record<string, string> = {
  documentation: 'Documentation',
  support: 'Technical Support',
  community: 'Community Forums',
  license: 'License Information',
  bug: 'Report a Bug',
  contact: 'Contact us',
}

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
    label: HELP_LABELS[key] ?? key,
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
const groups: NavGroup[] = [
  {
    label: 'Clients',
    section: 'Business',
    icon: 'clients',
    items: [
      { label: 'View/Search Clients', href: '/admin/customers', permission: 'crm.customers.view' },
      { label: 'Manage Users', href: '/admin/customer-users', permission: 'crm.customers.view' },
      {
        label: 'Add New Client',
        href: '/admin/clients/create',
        permission: 'crm.customers.manage',
      },
      {
        label: 'Products/Services',
        href: '/admin/services',
        permission: 'services.view',
        // The product types this platform sells, as filters on the one
        // list. A screen of its own per type would drift from the list
        // that already answers the question.
        children: [
          { label: 'All products/services', href: '/admin/services' },
          { label: 'Shared Hosting', href: '/admin/services?product_type=shared_hosting' },
          { label: 'Reseller Hosting', href: '/admin/services?product_type=reseller' },
          { label: 'VPS', href: '/admin/services?product_type=vps' },
          { label: 'Dedicated', href: '/admin/services?product_type=dedicated' },
          { label: 'SSL', href: '/admin/services?product_type=ssl' },
          { label: 'Email', href: '/admin/services?product_type=email' },
        ],
      },
      { label: 'Service Addons', href: '/admin/services/addons', permission: 'services.view' },
      { label: 'Domain Registrations', href: '/admin/domains', permission: 'domains.view' },
      { label: 'Cancellation Requests', href: '/admin/cancellations', permission: 'services.view' },
      { label: 'Organizations', href: '/admin/organizations', permission: 'organizations.view' },
    ],
  },
  {
    label: 'Orders',
    section: 'Business',
    icon: 'orders',
    items: [
      {
        label: 'List All Orders',
        href: '/admin/orders',
        permission: 'orders.view',
        children: [
          { label: 'All orders', href: '/admin/orders' },
          { label: 'Pending Orders', href: '/admin/orders?status=pending' },
          { label: 'Active Orders', href: '/admin/orders?status=active' },
          { label: 'Fraud Orders', href: '/admin/orders?status=fraud_review' },
          { label: 'Cancelled Orders', href: '/admin/orders?status=cancelled' },
        ],
      },
      { label: 'Add New Order', href: '/admin/orders/add', permission: 'orders.manage' },
      { label: 'Review Queue', href: '/admin/orders/review', permission: 'orders.view' },
    ],
  },
  {
    label: 'Billing',
    section: 'Business',
    icon: 'billing',
    items: [
      {
        label: 'Transactions List',
        href: '/admin/transactions',
        permission: 'billing.invoices.view',
        children: [
          { label: 'All transactions', href: '/admin/transactions' },
          { label: 'Amount in', href: '/admin/transactions?direction=in' },
          { label: 'Amount out', href: '/admin/transactions?direction=out' },
        ],
      },
      {
        label: 'Add Transaction',
        href: '/admin/transactions/add',
        permission: 'billing.payments.record',
      },
      {
        label: 'Invoices',
        href: '/admin/invoices',
        permission: 'billing.invoices.view',
        children: [
          { label: 'All invoices', href: '/admin/invoices' },
          { label: 'Paid', href: '/admin/invoices?status=paid' },
          { label: 'Draft', href: '/admin/invoices?status=draft' },
          { label: 'Unpaid', href: '/admin/invoices?status=unpaid' },
          { label: 'Overdue', href: '/admin/invoices?status=overdue' },
          { label: 'Partially paid', href: '/admin/invoices?status=partially_paid' },
          { label: 'Cancelled', href: '/admin/invoices?status=cancelled' },
          { label: 'Refunded', href: '/admin/invoices?status=refunded' },
        ],
      },
      {
        label: 'Gateway Log',
        href: '/admin/billing/gateway-log',
        permission: 'billing.payments.manage',
      },
      {
        label: 'Unpaid invoice sequence',
        href: '/admin/automation/dunning',
        permission: 'automation.view',
      },
      {
        label: 'Currencies',
        href: '/admin/catalog/currencies',
        permission: 'catalog.products.view',
      },
    ],
  },
  {
    label: 'Support',
    section: 'Support',
    icon: 'support',
    items: [
      {
        label: 'Support Overview',
        href: '/admin/support/overview',
        permission: 'support.tickets.view',
      },
      {
        label: 'Support Tickets',
        href: '/admin/support',
        permission: 'support.tickets.view',
        // Only the statuses this platform has. A row against an enum with
        // no such member returns an empty list and blames the operator.
        children: [
          { label: 'All Active Tickets', href: '/admin/support?status=open' },
          { label: 'Open', href: '/admin/support?status=open' },
          { label: 'Customer-Reply', href: '/admin/support?status=customer_reply' },
          { label: 'Answered', href: '/admin/support?status=answered' },
          { label: 'On Hold', href: '/admin/support?status=on_hold' },
          { label: 'Closed', href: '/admin/support?status=closed' },
        ],
      },
      {
        label: 'Open New Ticket',
        href: '/admin/support/create',
        permission: 'support.tickets.manage',
      },
      {
        label: 'Predefined Replies',
        href: '/admin/support/replies',
        permission: 'support.tickets.manage',
      },
      {
        label: 'Announcements',
        href: '/admin/content/announcements',
        permission: 'content.manage',
      },
      { label: 'Knowledge Base', href: '/admin/content/articles', permission: 'content.manage' },
    ],
  },
  {
    label: 'Utilities',
    section: 'System',
    icon: 'utilities',
    items: [
      // The passwordless way into a server's panel. Owner only, like
      // everything that reaches somebody else's machine.
      { label: 'Connect', href: '/admin/apps/connect', superAdmin: true },
      // WHMCS calls this the Module Queue. It is the same thing: every
      // background operation, what it was for, and what went wrong.
      { label: 'Module Queue', href: '/admin/operations', permission: 'operations.view' },
      { label: 'Todo List', href: '/admin/todo', permission: 'platform.health.view' },
      { label: 'Automation', href: '/admin/automation', permission: 'automation.view' },
      { label: 'System Health', href: '/admin/health', permission: 'platform.health.view' },
      {
        label: 'Notification Log',
        href: '/admin/notifications/log',
        permission: 'notifications.view',
      },
      { label: 'API Activity', href: '/admin/api/activity', permission: 'platform.audit.view' },
    ],
  },
  {
    label: 'Setup',
    section: 'System',
    icon: 'setup',
    items: [
      { label: 'Products', href: '/admin/catalog/products', permission: 'catalog.products.view' },
      { label: 'Product Groups', href: '/admin/catalog/groups', permission: 'catalog.groups.view' },
      { label: 'Promotions', href: '/admin/promotions', permission: 'promotions.view' },
      {
        label: 'Domain Extensions',
        href: '/admin/catalog/tlds',
        permission: 'domains.tlds.manage',
      },
      { label: 'Staff Members', href: '/admin/staff', permission: 'identity.staff.view' },
      { label: 'Roles', href: '/admin/roles', permission: 'access.roles.view' },
      { label: 'General Settings', href: '/admin/settings', permission: 'settings.view' },
      {
        label: 'Notification Templates',
        href: '/admin/notifications/templates',
        permission: 'notifications.view',
      },
    ],
  },
]

/**
 * The map, plus whatever the enabled modules added.
 *
 * Appended rather than woven in: a module cannot put a row next to Billing,
 * because a row that looked like Billing would be indistinguishable from
 * the platform's own.
 */
const withAddons = computed<NavGroup[]>(() =>
  addons.value.length === 0
    ? groups
    : // A module's rows get the extension glyph, not one of their own. A
      // module choosing its own icon could choose Billing's.
      [
        ...groups,
        {
          label: 'Addons',
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

// Which row inside an open group has its own submenu showing. One at a
// time: two flyouts overlapping is how a menu stops being readable.
const openItem = ref<string | null>(null)

const currentPath = computed(() => page.url.split('?')[0] ?? '/')

function hrefsOf(group: NavGroup): string[] {
  return group.href === undefined
    ? (group.items ?? []).flatMap((item) => [
        item.href,
        ...(item.children ?? []).map((c) => c.href),
      ])
    : [group.href]
}

/**
 * The longest matching destination wins.
 *
 * `/admin/orders` is a prefix of `/admin/orders/review`, and without this
 * both light up at once — which tells the operator nothing about where
 * they are.
 */
const currentHref = computed(() => {
  const candidates = groups
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

const railSections = computed(() =>
  SECTIONS.map((section) => ({
    section,
    groups: visibleGroups.value.filter((group) => group.section === section),
  })).filter((entry) => entry.groups.length > 0),
)

/**
 * Where you are, as words.
 *
 * Section › group › screen, and the screen's own name comes from the
 * heading the page passed rather than from the map — a detail page
 * ("Acme Ltd") has no entry in a menu, and the breadcrumb is the only place
 * that says which record you are looking at.
 */
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

    if (item.label !== props.heading) trail.push({ label: item.label, href: item.href })

    break
  }

  return trail
})

/**
 * The rail's width, remembered per browser.
 *
 * `localStorage` rather than the account: this is a per-viewer convenience,
 * not a setting, and an operator who opens the panel on a laptop and a
 * 34-inch monitor wants a different answer on each.
 */
const RAIL_KEY = 'admin.rail'

const railOpen = ref(readRail())

function readRail(): boolean {
  try {
    return window.localStorage.getItem(RAIL_KEY) === 'open'
  } catch {
    // Storage blocked. Collapsed is the default, which is also the answer
    // that needs no storage to be right.
    return false
  }
}

function toggleRail(): void {
  railOpen.value = !railOpen.value
  close()

  try {
    window.localStorage.setItem(RAIL_KEY, railOpen.value ? 'open' : 'closed')
  } catch {
    // Losing the preference is not worth an error.
  }
}

/**
 * The collapsed rail's flyout, teleported out of the bar.
 *
 * It used to be `absolute left-full` inside the nav — and the nav scrolls,
 * so the nav clips, so the flyout opened *inside* a 72px bar and was cut off
 * at its edge. The same bug the row menus had, for the same reason, fixed
 * the same way: `fixed`, against the button's own rectangle, outside every
 * ancestor's overflow. `useAnchoredPanel` is that one place.
 */
const flyout = useAnchoredPanel({ side: 'right', width: '15.5rem' })

// Template refs and bindings want a setup-scope name, so the pieces the
// template touches are named here rather than reached through `flyout.`.
const flyoutOpen = flyout.open
const flyoutPanel = flyout.panel
const flyoutStyle = flyout.style

/** The group the flyout is showing, or null when it is shut. */
const flyoutGroup = computed(
  () => visibleGroups.value.find((group) => group.label === openGroup.value) ?? null,
)

// Click rather than hover: a hover menu is unreachable on a touch screen and
// unforgiving with a trackpad.
function toggle(label: string, event?: Event): void {
  openItem.value = null

  const next = openGroup.value === label ? null : label

  openGroup.value = next

  // Expanded, the group opens in place and there is no panel to place.
  if (railOpen.value) {
    flyout.open.value = false

    return
  }

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
  // Expanded, the group you are in is already open: a rail that made
  // somebody press their own section to see where they are is a rail that
  // forgot.
  if (railOpen.value) {
    openGroup.value = visibleGroups.value.find(isCurrentGroup)?.label ?? null
  }

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
  <div
    class="text-content min-h-dvh"
    :style="{ '--rail-w': railOpen ? '248px' : '72px' }"
    :data-rail="railOpen ? 'open' : 'closed'"
  >
    <a
      href="#main"
      class="focus:bg-surface-elevated sr-only rounded-[var(--radius-sm)] focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-40 focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
      Skip to content
    </a>

    <!-- The scrim exists only below lg, where the rail is a drawer. -->
    <div
      v-if="mobileOpen"
      class="bg-background/70 fixed inset-0 z-30 lg:hidden"
      @click="mobileOpen = false"
    />

    <!--
      The rail.

      `fixed` rather than sticky: a rail that scrolled away would take the
      navigation with it on exactly the long list where somebody wants to
      leave. Its own scroll, because Setup is long.
    -->
    <aside
      data-admin-nav
      class="border-line bg-surface-chrome fixed inset-y-0 left-0 z-40 flex w-(--rail-w) flex-col border-r transition-transform duration-(--duration-fast) ease-(--ease-out) lg:translate-x-0"
      :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
      aria-label="Sections"
    >
      <!--
        Collapsed, the header stacks: the mark, and the control that opens
        the bar directly under it. Side by side they do not fit in 72px, and
        a control that does not fit is a control that gets dropped.
      -->
      <div
        class="border-line flex h-14 shrink-0 border-b"
        :class="
          railOpen ? 'items-center gap-2 px-3' : 'flex-col items-center justify-center gap-0.5 px-1'
        "
      >
        <!-- The way home, and the only one. -->
        <Link
          href="/admin"
          :aria-current="currentPath === '/admin' ? 'page' : undefined"
          :title="`${brand.name} dashboard`"
          class="pressable flex min-w-0 items-center gap-2 rounded-[var(--radius-sm)]"
        >
          <img
            v-if="brand.logoUrl"
            :src="brand.logoUrl"
            :alt="brand.name"
            class="h-6 w-6 shrink-0 rounded-[5px] object-contain"
          />
          <span
            v-else
            class="bg-brand text-content-inverse grid size-6 shrink-0 place-items-center rounded-[5px] text-[11px] font-bold"
            aria-hidden="true"
          >
            {{ brand.name.slice(0, 1).toUpperCase() }}
          </span>
          <span v-if="railOpen" class="text-title truncate font-semibold">{{ brand.name }}</span>
          <span v-else class="sr-only">{{ brand.name }} dashboard</span>
        </Link>

        <!--
          The collapse control, in the rail's header.

          It used to sit at the foot of the bar, under a list long enough to
          scroll — findable only by somebody who already knew it was there,
          which is the same as not being collapsible. Here it is the first
          thing in the rail after the way home, at both widths.
        -->
        <button
          type="button"
          data-rail-toggle
          class="pressable text-content-subtle hover:bg-surface-hover hover:text-content shrink-0 rounded-[var(--radius-sm)] transition-colors duration-(--duration-fast)"
          :class="railOpen ? 'ml-auto p-1.5' : 'p-0.5'"
          :aria-expanded="railOpen"
          :aria-label="railOpen ? 'Collapse the sidebar' : 'Expand the sidebar'"
          :title="railOpen ? 'Collapse the sidebar' : 'Expand the sidebar'"
          @click="toggleRail"
        >
          <span
            class="block transition-transform duration-(--duration-base) ease-(--ease-out)"
            :class="railOpen ? 'rotate-180' : ''"
          >
            <AppIcon name="chevronRight" :size="railOpen ? 16 : 13" />
          </span>
        </button>
      </div>

      <nav class="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
        <div v-for="entry in railSections" :key="entry.section" class="mb-3 last:mb-0">
          <!-- The heading only exists when the rail has room for it. Collapsed,
               a hairline is what separates one category from the next. -->
          <p v-if="railOpen" class="text-content-subtle text-label px-2 pt-2 pb-1 uppercase">
            {{ entry.section }}
          </p>
          <div v-else class="bg-line mx-2 mt-2 mb-1 h-px" aria-hidden="true" />

          <ul class="space-y-0.5">
            <li v-for="group in entry.groups" :key="group.label" class="relative">
              <button
                type="button"
                class="pressable text-body flex w-full items-center gap-2.5 rounded-[var(--radius-sm)] px-2 py-1.5 font-medium transition-colors duration-(--duration-fast) ease-(--ease-out)"
                :class="[
                  isCurrentGroup(group)
                    ? 'bg-surface-selected text-content'
                    : 'text-content-muted hover:bg-surface-hover hover:text-content',
                  railOpen ? '' : 'justify-center',
                ]"
                :aria-expanded="openGroup === group.label"
                :title="railOpen ? undefined : group.label"
                @click="toggle(group.label, $event)"
              >
                <AppIcon :name="group.icon" :size="17" />
                <template v-if="railOpen">
                  <span class="flex-1 truncate text-left">{{ group.label }}</span>
                  <span
                    class="text-content-subtle transition-transform duration-(--duration-fast) ease-(--ease-out)"
                    :class="openGroup === group.label ? 'rotate-180' : ''"
                  >
                    <AppIcon name="chevronDown" :size="12" />
                  </span>
                </template>
                <span v-else class="sr-only">{{ group.label }}</span>
              </button>

              <!-- Expanded: in place. The rows sit under their group, indented
                   past the glyph so the column of labels is one column. -->
              <ul
                v-if="railOpen && openGroup === group.label"
                class="border-line-subtle mt-0.5 mb-1 ml-4 space-y-0.5 border-l pl-2"
              >
                <li v-for="item in group.items" :key="item.label">
                  <div class="flex items-stretch">
                    <Link
                      :href="item.href"
                      :aria-current="isCurrent(item.href) ? 'page' : undefined"
                      class="pressable text-body block flex-1 truncate rounded-[var(--radius-sm)] px-2 py-1 transition-colors duration-(--duration-fast)"
                      :class="
                        isCurrent(item.href)
                          ? 'text-content font-medium'
                          : 'text-content-muted hover:bg-surface-hover hover:text-content'
                      "
                    >
                      {{ item.label }}
                    </Link>
                    <button
                      v-if="item.children"
                      type="button"
                      class="pressable text-content-subtle hover:text-content rounded-[var(--radius-sm)] px-1"
                      :aria-expanded="openItem === item.label"
                      :aria-label="`${item.label} submenu`"
                      @click.stop="openItem = openItem === item.label ? null : item.label"
                    >
                      <AppIcon name="chevronDown" :size="11" />
                    </button>
                  </div>

                  <ul
                    v-if="item.children && openItem === item.label"
                    class="border-line-subtle mt-0.5 ml-2 space-y-0.5 border-l pl-2"
                  >
                    <li v-for="child in item.children" :key="child.href">
                      <Link
                        :href="child.href"
                        :aria-current="isCurrent(child.href) ? 'page' : undefined"
                        class="pressable text-chrome block truncate rounded-[var(--radius-sm)] px-2 py-1 transition-colors duration-(--duration-fast)"
                        :class="
                          isCurrent(child.href)
                            ? 'text-content font-medium'
                            : 'text-content-subtle hover:bg-surface-hover hover:text-content'
                        "
                      >
                        {{ child.label }}
                      </Link>
                    </li>
                  </ul>
                </li>
              </ul>
            </li>
          </ul>
        </div>
      </nav>
    </aside>

    <!--
      Collapsed, a group opens as a flyout, because 72px has nowhere to put a
      list. One panel rather than one per group: only one can be open, and a
      panel per row would be seven hidden panels on every page load.

      Teleported to the body and positioned `fixed`, which is the only
      placement no ancestor's `overflow` can clip.
    -->
    <Teleport to="body">
      <div
        v-if="flyoutOpen && !railOpen && flyoutGroup"
        ref="flyoutPanel"
        data-rail-flyout
        :style="flyoutStyle"
        class="panel-enter border-line bg-surface-elevated text-content z-50 rounded-[var(--radius-lg)] border p-1.5 shadow-(--shadow-panel)"
      >
        <p class="text-content-subtle text-label px-2 pt-1 pb-1.5 uppercase">
          {{ flyoutGroup.label }}
        </p>
        <ul class="max-h-[70dvh] space-y-0.5 overflow-y-auto">
          <li v-for="item in flyoutGroup.items" :key="item.label">
            <Link
              :href="item.href"
              :aria-current="isCurrent(item.href) ? 'page' : undefined"
              class="pressable text-body block rounded-[var(--radius-sm)] border-l-2 px-2.5 py-1.5 whitespace-nowrap transition-colors duration-(--duration-fast)"
              :class="
                isCurrent(item.href)
                  ? 'bg-surface-hover text-content border-accent font-medium'
                  : 'text-content-muted hover:bg-surface-hover hover:text-content border-transparent'
              "
            >
              {{ item.label }}
            </Link>
          </li>
        </ul>
      </div>
    </Teleport>

    <div class="flex min-h-dvh flex-col lg:ml-(--rail-w)">
      <!--
        The topbar carries where you are and what belongs to the session.
        Nothing on it is page content, which is what keeps it from becoming a
        second header.
      -->
      <header
        class="border-line bg-surface-chrome sticky top-0 z-20 flex h-14 items-center gap-3 border-b px-4 sm:px-6"
      >
        <button
          type="button"
          class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] p-1.5 lg:hidden"
          :aria-expanded="mobileOpen"
          aria-label="Sections"
          @click="mobileOpen = !mobileOpen"
        >
          <AppIcon name="more" :size="18" />
        </button>

        <nav aria-label="Breadcrumb" class="min-w-0 flex-1">
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

        <div class="flex shrink-0 items-center gap-1 sm:gap-2">
          <CommandPalette :destinations="destinations" />

          <!-- What the platform is doing, and what went wrong (§8). It draws
               nothing at all for somebody who may not see operations. -->
          <OperationsDrawer />

          <ThemeSwitch />

          <!-- The spanner: what an installation is wired to, and what it
               wrote down. Shut to everybody but the owner. -->
          <AppMenu v-if="isSuperAdmin" label="Tools" align="end" width="15rem" icon="utilities">
            <Link
              href="/admin/apps"
              class="pressable hover:bg-surface-secondary block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              Apps &amp; Integrations
            </Link>
            <Link
              href="/admin/apps/connect"
              class="pressable hover:bg-surface-secondary block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              Connect
            </Link>
            <Link
              href="/admin/api/activity"
              class="pressable hover:bg-surface-secondary block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              System logs
            </Link>
          </AppMenu>

          <!-- Where to get help. Every link is configurable, because a
               white-label installation sends its operators to its own
               documentation, not to ours. -->
          <AppMenu label="Help" align="end" width="15rem" icon="help">
            <a
              v-for="(url, key) in help"
              :key="key"
              :href="url"
              target="_blank"
              rel="noopener noreferrer"
              class="pressable hover:bg-surface-secondary block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              {{ HELP_LABELS[key] ?? key }}
            </a>
            <p v-if="Object.keys(help).length === 0" class="text-content-muted px-2 py-1.5 text-xs">
              No help links are configured for this installation.
            </p>
          </AppMenu>

          <!-- A face rather than an address. An email read across the top of
               every page is somebody's identifier on a screen other people
               walk past, and it told an operator nothing they did not
               already know. -->
          <AppMenu v-if="user" :label="initials" align="end" width="14rem" avatar>
            <p class="border-line mb-1 border-b px-2 pb-2">
              <span class="block truncate text-sm font-medium">{{ user.name }}</span>
              <span class="text-content-muted block truncate text-xs">{{ user.email }}</span>
            </p>
            <Link
              href="/admin/security"
              class="pressable hover:bg-surface-secondary block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              My Account
            </Link>
            <a
              href="/client"
              class="pressable hover:bg-surface-secondary block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              Visit Client Area
            </a>
            <Link
              href="/admin/logout"
              method="post"
              as="button"
              class="pressable hover:bg-surface-secondary block w-full rounded-[var(--radius-sm)] px-2 py-1.5 text-left text-sm"
              role="menuitem"
            >
              Sign out
            </Link>
          </AppMenu>
        </div>
      </header>

      <!-- pb: the footer is fixed, so the last row of a table would sit
           underneath it without this. -->
      <main id="main" class="flex-1 px-4 pt-5 pb-20 sm:px-6 sm:pt-6">
        <div class="mx-auto max-w-[110rem]">
          <!-- Title and actions on one line. An operator wants the name of
               the screen and the button they came for, in one glance; the
               breadcrumb above already said how they got here. -->
          <div class="mb-5 flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0">
              <h1 class="text-page font-semibold">{{ heading }}</h1>
              <p v-if="description" class="text-content-muted text-chrome mt-1 max-w-[90ch]">
                {{ description }}
              </p>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
              <slot name="actions" />
            </div>
          </div>

          <AppAlert v-if="flash?.error" tone="danger" class="mb-5">{{ flash.error }}</AppAlert>
          <AppAlert v-else-if="flash?.status" tone="success" class="mb-5">
            {{ flash.status }}
          </AppAlert>

          <slot />
        </div>
      </main>
    </div>

    <!-- Fixed rather than at the end of the document: an operator three
         hundred rows into a list still needs the link that reports what is
         wrong with the page they are looking at. -->
    <footer
      class="border-line bg-surface-chrome text-content-muted text-label fixed right-0 bottom-0 left-0 z-10 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-4 py-2.5 sm:px-6 lg:left-(--rail-w)"
    >
      <p>&copy; {{ year }} {{ brand.name }}</p>

      <nav v-if="footerLinks.length > 0" aria-label="Help" class="flex items-center gap-2">
        <template v-for="(link, index) in footerLinks" :key="link.key">
          <span v-if="index > 0" class="text-content-subtle" aria-hidden="true">|</span>
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
</template>
