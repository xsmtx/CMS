<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import AppAlert from '../Components/AppAlert.vue'
import AppIcon from '../Components/AppIcon.vue'
import CommandPalette, { type Destination } from '../Components/CommandPalette.vue'
import { type IconName } from '../icons'
import AppMenu from '../Components/AppMenu.vue'
import ThemeSwitch from '../Components/ThemeSwitch.vue'
import { useBranding } from '../composables/useBranding'
import { usePermissions } from '../composables/usePermissions'

/**
 * Admin shell.
 *
 * **The menu is across the top, grouped the way WHMCS groups it.** That is
 * not a style choice: the people who will run this platform have spent
 * years in WHMCS, and every minute they spend hunting for Invoices is a
 * minute the software costs them. Clients, Orders, Billing, Support,
 * Utilities, Setup — in that order, with those words — means an operator
 * who has never opened this panel can still find things on their first day.
 *
 * Where this platform genuinely differs it says so rather than pretending:
 * Services and Domains get their own menus, because a hosting operator
 * thinks about the machine and the name, not about a line on an invoice.
 *
 * Staff open this dozens of times a day, so there is no page transition and
 * no entrance animation. A dropdown opens from its own trigger and is done
 * in 120ms — fast enough to feel like it was already there. Everything else
 * is press feedback and colour on hover.
 *
 * Navigation renders only what the signed-in staff member may actually
 * reach. Hiding is presentation, not authorization: every destination
 * re-checks the same gate server side.
 */
defineProps<{ heading: string; description?: string }>()

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

interface NavGroup {
  label: string
  /** A group with an href is a link rather than a dropdown. */
  href?: string
  permission?: string
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
      [...groups, { label: 'Addons', icon: 'modules' as const, items: addons.value }],
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

function toggle(label: string): void {
  openItem.value = null

  openGroup.value = openGroup.value === label ? null : label
}

function close(): void {
  openGroup.value = null
}

// Click rather than hover: a hover menu is unreachable on a touch screen and
// unforgiving with a trackpad.
function onDocumentClick(event: MouseEvent): void {
  const target = event.target

  if (target instanceof Element && target.closest('[data-admin-nav]') === null) {
    close()
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
    mobileOpen.value = false
  }
}

let stopNavigation: (() => void) | null = null

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)

  // A menu still hanging open over the page it has just navigated to is the
  // commonest way a top nav feels broken.
  stopNavigation = router.on('navigate', () => {
    close()
    mobileOpen.value = false
  })
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
  stopNavigation?.()
})
</script>

<template>
  <div class="bg-surface min-h-[100dvh]">
    <a
      href="#main"
      class="focus:bg-surface-raised sr-only rounded-[var(--radius-sm)] focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-30 focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
      Skip to content
    </a>

    <header class="border-line bg-chrome sticky top-0 z-20 border-b">
      <!-- One row. The map, the search box and the account live on the
           same line, because a second full-width strip costs an inch of
           every screen an operator spends the day scrolling. -->
      <div class="flex h-14 items-center gap-3 px-5 sm:px-8">
        <!-- The way home, and the only one. It carries `aria-current` when
             you are on it, because a logo doing navigation duty silently is
             a link screen readers cannot place. -->
        <Link
          href="/admin"
          :aria-current="currentPath === '/admin' ? 'page' : undefined"
          :title="`${brand.name} dashboard`"
          class="pressable text-title mr-1 flex shrink-0 items-center gap-2 rounded-[var(--radius-sm)] font-semibold transition-colors duration-(--duration-fast)"
          :class="currentPath === '/admin' ? 'text-content' : 'text-content hover:text-accent'"
        >
          <img
            v-if="brand.logoUrl"
            :src="brand.logoUrl"
            :alt="brand.name"
            class="h-6 w-auto max-w-[9rem] object-contain"
          />
          <template v-else>
            <span
              class="bg-accent text-accent-content grid size-5 place-items-center rounded-[5px] text-[10px] font-bold"
              aria-hidden="true"
            >
              {{ brand.name.slice(0, 1).toUpperCase() }}
            </span>
            <span>{{ brand.name }}</span>
          </template>
        </Link>

        <span class="bg-line h-5 w-px shrink-0" aria-hidden="true" />

        <nav data-admin-nav aria-label="Admin" class="min-w-0 flex-1">
          <button
            type="button"
            class="pressable text-content-muted hover:text-content text-body inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2 py-1.5 lg:hidden"
            :aria-expanded="mobileOpen"
            @click="mobileOpen = !mobileOpen"
          >
            <AppIcon name="more" :size="16" />
            Menu
          </button>

          <ul class="hidden items-center lg:flex">
            <li v-for="group in visibleGroups" :key="group.label" class="relative">
              <Link
                v-if="group.href"
                :href="group.href"
                :aria-current="isCurrentGroup(group) ? 'page' : undefined"
                class="pressable text-body inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2.5 py-1.5 font-medium transition-colors duration-(--duration-fast) ease-(--ease-out)"
                :class="
                  isCurrentGroup(group)
                    ? 'bg-surface-sunken text-content'
                    : 'text-content-muted hover:text-content'
                "
              >
                <AppIcon :name="group.icon" :size="15" />
                {{ group.label }}
              </Link>

              <button
                v-else
                type="button"
                class="pressable text-body inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2.5 py-1.5 font-medium transition-colors duration-(--duration-fast) ease-(--ease-out)"
                :class="
                  isCurrentGroup(group) || openGroup === group.label
                    ? 'bg-surface-sunken text-content'
                    : 'text-content-muted hover:text-content'
                "
                :aria-expanded="openGroup === group.label"
                @click="toggle(group.label)"
              >
                <AppIcon :name="group.icon" :size="15" />
                {{ group.label }}
                <span
                  class="text-content-subtle transition-transform duration-(--duration-fast) ease-(--ease-out)"
                  :class="openGroup === group.label ? 'rotate-180' : ''"
                >
                  <AppIcon name="chevronDown" :size="12" />
                </span>
              </button>

              <!-- Grows out of its own trigger: a panel anchored to the thing
                 you pressed needs no explanation. -->
              <div
                v-if="(group.items ?? []).length > 0 && openGroup === group.label"
                class="panel-enter border-line bg-surface-raised absolute top-full left-0 z-20 mt-1.5 min-w-[16rem] origin-top-left rounded-[var(--radius-lg)] border p-1.5 shadow-(--shadow-panel)"
              >
                <ul class="space-y-0.5">
                  <li
                    v-for="item in group.items"
                    :key="item.label"
                    class="relative"
                    @mouseenter="item.children ? (openItem = item.label) : (openItem = null)"
                  >
                    <!-- A row with a submenu is a link *and* a door: clicking
                       it goes to the list, the chevron opens the filters
                       for it. An operator who wanted the whole list should
                       not have to pick a filter first. -->
                    <div class="flex items-stretch">
                      <Link
                        :href="item.href"
                        :aria-current="isCurrent(item.href) ? 'page' : undefined"
                        class="pressable text-body block flex-1 rounded-[var(--radius-sm)] px-2.5 py-1.5 whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
                        :class="
                          isCurrent(item.href)
                            ? 'bg-surface-sunken text-content border-highlight border-l-2 font-medium'
                            : 'text-content-muted hover:bg-surface-sunken hover:text-content border-l-2 border-transparent'
                        "
                      >
                        {{ item.label }}
                      </Link>

                      <button
                        v-if="item.children"
                        type="button"
                        class="pressable text-content-subtle hover:text-content rounded-[var(--radius-sm)] px-1.5"
                        :aria-expanded="openItem === item.label"
                        :aria-label="`${item.label} submenu`"
                        @click.stop="openItem = openItem === item.label ? null : item.label"
                      >
                        <AppIcon name="chevronRight" :size="12" />
                      </button>
                    </div>

                    <!-- To the side, not underneath: a submenu that pushed the
                       rows below it down moves the thing somebody was
                       reaching for. -->
                    <div
                      v-if="item.children && openItem === item.label"
                      class="panel-enter border-line bg-surface-raised absolute top-0 left-full z-30 ml-1.5 min-w-[14rem] origin-top-left rounded-[var(--radius-lg)] border p-1.5 shadow-(--shadow-panel)"
                    >
                      <ul class="space-y-0.5">
                        <li v-for="child in item.children" :key="child.href">
                          <Link
                            :href="child.href"
                            :aria-current="isCurrent(child.href) ? 'page' : undefined"
                            class="pressable text-body block rounded-[var(--radius-sm)] px-2.5 py-1.5 whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
                            :class="
                              isCurrent(child.href)
                                ? 'bg-surface-sunken text-content border-highlight border-l-2 font-medium'
                                : 'text-content-muted hover:bg-surface-sunken hover:text-content border-l-2 border-transparent'
                            "
                          >
                            {{ child.label }}
                          </Link>
                        </li>
                      </ul>
                    </div>
                  </li>
                </ul>
              </div>
            </li>
          </ul>
        </nav>

        <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
          <!-- One box, every kind of record — a support call carries one
               fact and no idea which screen it belongs to — and every
               screen as well, because after a week an operator stops using
               the menu for anything they can name. -->
          <CommandPalette :destinations="destinations" />

          <ThemeSwitch />

          <!-- The spanner: what an installation is wired to, and what it
               wrote down. Shut to everybody but the owner. -->
          <AppMenu v-if="isSuperAdmin" label="Tools" align="end" width="15rem" icon="utilities">
            <Link
              href="/admin/apps"
              class="pressable hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              Apps &amp; Integrations
            </Link>
            <Link
              href="/admin/apps/connect"
              class="pressable hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              Connect
            </Link>
            <Link
              href="/admin/api/activity"
              class="pressable hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
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
              class="pressable hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
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
              class="pressable hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              My Account
            </Link>
            <a
              href="/client"
              class="pressable hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
              role="menuitem"
            >
              Visit Client Area
            </a>
            <Link
              href="/admin/logout"
              method="post"
              as="button"
              class="pressable hover:bg-surface-sunken block w-full rounded-[var(--radius-sm)] px-2 py-1.5 text-left text-sm"
              role="menuitem"
            >
              Sign out
            </Link>
          </AppMenu>
        </div>
      </div>

      <!-- Below lg only. A dropdown inside a drawer is two taps to reach
           one link, so the small screen gets the whole map at once. -->
      <nav aria-label="Admin menu" class="px-3 sm:px-6 lg:hidden">
        <!-- Addons. Rendered from what the enabled modules registered, so
             an installation with none sees nothing rather than an empty
             menu promising extensions. -->

        <!-- Below lg the whole map is one list. A dropdown inside a drawer
             is two taps to reach one link. -->
        <div v-if="mobileOpen" class="border-line border-t py-3 lg:hidden">
          <div v-for="group in visibleGroups" :key="group.label" class="mb-4 last:mb-0">
            <p class="text-content-subtle text-label px-2 pb-1 font-medium">{{ group.label }}</p>

            <ul class="space-y-0.5">
              <li v-if="group.href">
                <Link
                  :href="group.href"
                  class="text-content-muted hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
                >
                  Overview
                </Link>
              </li>
              <template v-for="item in group.items" :key="item.label">
                <li>
                  <Link
                    :href="item.href"
                    :aria-current="isCurrent(item.href) ? 'page' : undefined"
                    class="block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
                    :class="
                      isCurrent(item.href)
                        ? 'bg-surface-sunken text-content border-highlight border-l-2 font-medium'
                        : 'text-content-muted hover:bg-surface-sunken'
                    "
                  >
                    {{ item.label }}
                  </Link>
                </li>
                <li v-for="child in item.children ?? []" :key="child.href">
                  <Link
                    :href="child.href"
                    class="text-content-muted hover:bg-surface-sunken block rounded-[var(--radius-sm)] py-1.5 pr-2 pl-6 text-sm"
                  >
                    {{ child.label }}
                  </Link>
                </li>
              </template>
            </ul>
          </div>
        </div>
      </nav>
    </header>

    <!-- pb: the footer is fixed, so the last row of a table would sit
         underneath it without this. -->
    <main id="main" class="px-5 pt-6 pb-20 sm:px-8 sm:pt-8">
      <!-- Wider than the sidebar allowed: the horizontal space the menu
           gave back belongs to the tables, which is where an operator
           actually spends the day. -->
      <div class="mx-auto max-w-7xl">
        <!-- Title and actions on one line, the explanation under it in
             chrome type. A 28px heading with a paragraph beneath reads like
             a marketing page; an operator wants the name of the screen and
             the button they came for, in the same glance. -->
        <div class="mb-5 flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
          <div class="min-w-0">
            <h1 class="text-page font-semibold">{{ heading }}</h1>
            <p v-if="description" class="text-content-muted text-chrome mt-1 max-w-[80ch]">
              {{ description }}
            </p>
          </div>
          <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
          </div>
        </div>

        <AppAlert v-if="flash?.error" tone="danger" class="mb-6">{{ flash.error }}</AppAlert>
        <AppAlert v-else-if="flash?.status" tone="success" class="mb-6">
          {{ flash.status }}
        </AppAlert>

        <slot />
      </div>
    </main>

    <!-- Fixed rather than at the end of the document: an operator three
         hundred rows into a list still needs the link that reports what is
         wrong with the page they are looking at. -->
    <footer
      class="border-line bg-chrome text-content-muted fixed inset-x-0 bottom-0 z-10 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-5 py-3 text-xs sm:px-8"
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
