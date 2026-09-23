<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

import AppAlert from '../Components/AppAlert.vue'
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
}

const searching = ref(false)
const term = ref('')
const searchField = ref<HTMLInputElement | null>(null)

async function openSearch(): Promise<void> {
  searching.value = true
  await nextTick()
  searchField.value?.focus()
}

function submitSearch(): void {
  if (term.value.trim() === '') return

  router.get('/admin/search', { q: term.value })
  searching.value = false
}

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
   * Apps and Integrations only. An Administrator holds every staff
   * permission by design, so no permission could mean "owner of this
   * installation".
   */
  superAdmin?: boolean
}

interface NavSection {
  label?: string
  items: NavItem[]
}

interface NavGroup {
  label: string
  /** A group with an href is a link rather than a dropdown. Dashboard. */
  href?: string
  permission?: string
  sections?: NavSection[]
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
const groups: NavGroup[] = [
  { label: 'Dashboard', href: '/admin', permission: 'platform.health.view' },
  {
    label: 'Clients',
    sections: [
      {
        items: [
          {
            label: 'View/Search Clients',
            href: '/admin/customers',
            permission: 'crm.customers.view',
          },
          {
            label: 'Manage Users',
            href: '/admin/customer-users',
            permission: 'crm.customers.view',
          },
          {
            label: 'Add New Client',
            href: '/admin/clients/create',
            permission: 'crm.customers.manage',
          },
        ],
      },
      {
        label: 'Products/Services',
        items: [
          { label: 'All products/services', href: '/admin/services', permission: 'services.view' },
          // The product types this platform sells. Filters on the same
          // screen rather than screens of their own: the list already
          // answers "show me the shared hosting", and a second page that
          // did the same thing would drift from it.
          {
            label: 'Shared Hosting',
            href: '/admin/services?product_type=shared_hosting',
            permission: 'services.view',
          },
          {
            label: 'Reseller Hosting',
            href: '/admin/services?product_type=reseller',
            permission: 'services.view',
          },
          { label: 'VPS', href: '/admin/services?product_type=vps', permission: 'services.view' },
          {
            label: 'Dedicated',
            href: '/admin/services?product_type=dedicated',
            permission: 'services.view',
          },
          { label: 'SSL', href: '/admin/services?product_type=ssl', permission: 'services.view' },
          {
            label: 'Service Addons',
            href: '/admin/services/addons',
            permission: 'services.view',
          },
        ],
      },
      {
        items: [
          { label: 'Domain Registrations', href: '/admin/domains', permission: 'domains.view' },
          // A real filter on real rows: `cancel_pending` is the state a
          // service enters when a customer asks to stop at the end of the
          // term.
          {
            label: 'Cancellation Requests',
            href: '/admin/services?status=cancel_pending',
            permission: 'services.view',
          },
          {
            label: 'Organizations',
            href: '/admin/organizations',
            permission: 'organizations.view',
          },
        ],
      },
    ],
  },
  {
    label: 'Orders',
    sections: [
      {
        items: [
          { label: 'List All Orders', href: '/admin/orders', permission: 'orders.view' },
          {
            label: 'Pending Orders',
            href: '/admin/orders?status=pending',
            permission: 'orders.view',
          },
          {
            label: 'Active Orders',
            href: '/admin/orders?status=active',
            permission: 'orders.view',
          },
          {
            label: 'Fraud Orders',
            href: '/admin/orders?status=fraud_review',
            permission: 'orders.view',
          },
          {
            label: 'Cancelled Orders',
            href: '/admin/orders?status=cancelled',
            permission: 'orders.view',
          },
        ],
      },
      {
        items: [{ label: 'Review Queue', href: '/admin/orders/review', permission: 'orders.view' }],
      },
    ],
  },
  {
    label: 'Billing',
    sections: [
      {
        items: [
          {
            label: 'Transactions List',
            href: '/admin/transactions',
            permission: 'billing.invoices.view',
          },
        ],
      },
      {
        label: 'Invoices',
        items: [
          { label: 'All invoices', href: '/admin/invoices', permission: 'billing.invoices.view' },
          {
            label: 'Paid',
            href: '/admin/invoices?status=paid',
            permission: 'billing.invoices.view',
          },
          {
            label: 'Draft',
            href: '/admin/invoices?status=draft',
            permission: 'billing.invoices.view',
          },
          {
            label: 'Unpaid',
            href: '/admin/invoices?status=unpaid',
            permission: 'billing.invoices.view',
          },
          {
            label: 'Overdue',
            href: '/admin/invoices?status=overdue',
            permission: 'billing.invoices.view',
          },
          {
            label: 'Partially paid',
            href: '/admin/invoices?status=partially_paid',
            permission: 'billing.invoices.view',
          },
          {
            label: 'Cancelled',
            href: '/admin/invoices?status=cancelled',
            permission: 'billing.invoices.view',
          },
          {
            label: 'Refunded',
            href: '/admin/invoices?status=refunded',
            permission: 'billing.invoices.view',
          },
        ],
      },
      {
        items: [
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
    ],
  },
  {
    label: 'Services',
    sections: [
      {
        items: [
          {
            label: 'Products/Services',
            href: '/admin/services',
            permission: 'services.view',
          },
          {
            label: 'Service addons',
            href: '/admin/services/addons',
            permission: 'services.view',
          },
        ],
      },
    ],
  },
  {
    label: 'Domains',
    sections: [
      {
        items: [
          { label: 'Domains', href: '/admin/domains', permission: 'domains.view' },
          { label: 'Extensions', href: '/admin/catalog/tlds', permission: 'domains.tlds.manage' },
        ],
      },
    ],
  },
  {
    label: 'Support',
    sections: [
      {
        items: [
          { label: 'Support Overview', href: '/admin/support', permission: 'support.tickets.view' },
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
        ],
      },
      {
        label: 'Support Tickets',
        items: [
          // Only the statuses this platform actually has. A menu offering
          // "Technical Intervene" against an enum with no such member is a
          // menu that returns an empty list and blames the operator.
          {
            label: 'All Active Tickets',
            href: '/admin/support?status=open',
            permission: 'support.tickets.view',
          },
          {
            label: 'Open',
            href: '/admin/support?status=open',
            permission: 'support.tickets.view',
          },
          {
            label: 'Customer-Reply',
            href: '/admin/support?status=customer_reply',
            permission: 'support.tickets.view',
          },
          {
            label: 'Answered',
            href: '/admin/support?status=answered',
            permission: 'support.tickets.view',
          },
          {
            label: 'On Hold',
            href: '/admin/support?status=on_hold',
            permission: 'support.tickets.view',
          },
          {
            label: 'Closed',
            href: '/admin/support?status=closed',
            permission: 'support.tickets.view',
          },
        ],
      },
      {
        label: 'Content',
        items: [
          {
            label: 'Announcements',
            href: '/admin/content/announcements',
            permission: 'content.manage',
          },
          {
            label: 'Knowledge base',
            href: '/admin/content/articles',
            permission: 'content.manage',
          },
        ],
      },
    ],
  },
  {
    label: 'Utilities',
    sections: [
      {
        items: [
          // WHMCS calls this the Module Queue. It is the same thing: every
          // background operation, what it was for, and what went wrong.
          { label: 'Module Queue', href: '/admin/operations', permission: 'operations.view' },
          { label: 'Automation', href: '/admin/automation', permission: 'automation.view' },
          { label: 'System Health', href: '/admin/health', permission: 'platform.health.view' },
        ],
      },
      {
        label: 'Logs',
        items: [
          {
            label: 'Notification log',
            href: '/admin/notifications/log',
            permission: 'notifications.view',
          },
          { label: 'API activity', href: '/admin/api/activity', permission: 'platform.audit.view' },
        ],
      },
    ],
  },
  {
    label: 'Setup',
    sections: [
      {
        label: 'Products and services',
        items: [
          {
            label: 'Products',
            href: '/admin/catalog/products',
            permission: 'catalog.products.view',
          },
          {
            label: 'Product groups',
            href: '/admin/catalog/groups',
            permission: 'catalog.groups.view',
          },
          { label: 'Promotions', href: '/admin/promotions', permission: 'promotions.view' },
        ],
      },
      {
        label: 'Staff',
        items: [
          { label: 'Staff members', href: '/admin/staff', permission: 'identity.staff.view' },
          { label: 'Roles', href: '/admin/roles', permission: 'access.roles.view' },
        ],
      },
      {
        label: 'Platform',
        items: [
          { label: 'General settings', href: '/admin/settings', permission: 'settings.view' },
          {
            label: 'Notification templates',
            href: '/admin/notifications/templates',
            permission: 'notifications.view',
          },
        ],
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
    : [...groups, { label: 'Addons', sections: [{ items: addons.value }] }],
)

const visibleGroups = computed(() =>
  withAddons.value
    .map((group) => ({
      ...group,
      sections: (group.sections ?? [])
        .map((section) => ({
          ...section,
          items: section.items.filter(
            (item) =>
              (!item.superAdmin || isSuperAdmin.value) &&
              (!item.permission || can(item.permission)),
          ),
        }))
        .filter((section) => section.items.length > 0),
    }))
    .filter(
      (group) =>
        (group.href !== undefined && (!group.permission || can(group.permission))) ||
        group.sections.length > 0,
    ),
)

const currentPath = computed(() => page.url.split('?')[0] ?? '/')

function hrefsOf(group: NavGroup): string[] {
  return group.href === undefined
    ? (group.sections ?? []).flatMap((section) => section.items.map((item) => item.href))
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

function isCurrentGroup(group: { href?: string; sections: NavSection[] }): boolean {
  return currentHref.value !== null && hrefsOf(group as NavGroup).includes(currentHref.value)
}

const openGroup = ref<string | null>(null)
const mobileOpen = ref(false)

function toggle(label: string): void {
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

    <header class="border-line bg-surface-raised sticky top-0 z-20 border-b">
      <!-- Top strip: who you are and how you leave. Kept apart from the
           menu so that signing out is never one row away from Setup. -->
      <div class="border-line flex h-14 items-center gap-3 border-b px-5 sm:px-8">
        <Link
          href="/admin"
          class="pressable flex items-center gap-2 rounded-[var(--radius-sm)] text-sm font-semibold tracking-tight"
        >
          <img
            v-if="brand.logoUrl"
            :src="brand.logoUrl"
            :alt="brand.name"
            class="h-6 w-auto max-w-[9rem] object-contain"
          />
          <span v-else>{{ brand.name }}</span>
        </Link>
        <span class="text-content-subtle text-xs">Admin</span>

        <div class="ml-auto flex items-center gap-1 sm:gap-2">
          <!-- One box, every kind of record: a support call carries one
               fact and no idea which screen it belongs to. -->
          <form v-if="searching" class="flex items-center gap-1.5" @submit.prevent="submitSearch">
            <input
              ref="searchField"
              v-model="term"
              type="search"
              placeholder="Client, domain, hostname, invoice…"
              aria-label="Search everything"
              class="border-line bg-surface-raised text-content placeholder:text-content-subtle w-56 rounded-[var(--radius-sm)] border px-3 py-1.5 text-sm sm:w-72"
              @keydown.escape="searching = false"
            />
          </form>
          <button
            v-else
            type="button"
            class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] p-1.5 transition-colors duration-(--duration-fast)"
            aria-label="Search"
            @click="openSearch"
          >
            <svg class="size-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <circle cx="7" cy="7" r="4.5" stroke="currentColor" stroke-width="1.5" />
              <path
                d="m10.5 10.5 3 3"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
              />
            </svg>
          </button>

          <ThemeSwitch />

          <!-- The spanner: what an installation is wired to, and what it
               wrote down. Shut to everybody but the owner. -->
          <AppMenu v-if="isSuperAdmin" label="Tools" align="end" width="15rem" icon="wrench">
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
          <AppMenu label="Help" align="end" width="15rem" icon="question">
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

      <nav data-admin-nav aria-label="Admin" class="px-3 sm:px-6">
        <button
          type="button"
          class="pressable text-content-muted hover:text-content my-1.5 rounded-[var(--radius-sm)] px-2 py-1.5 text-sm lg:hidden"
          :aria-expanded="mobileOpen"
          @click="mobileOpen = !mobileOpen"
        >
          Menu
        </button>

        <ul class="hidden items-center lg:flex">
          <li v-for="group in visibleGroups" :key="group.label" class="relative">
            <Link
              v-if="group.href"
              :href="group.href"
              :aria-current="isCurrentGroup(group) ? 'page' : undefined"
              class="pressable inline-flex h-12 items-center rounded-[var(--radius-sm)] px-3.5 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
              :class="
                isCurrentGroup(group)
                  ? 'text-content font-medium'
                  : 'text-content-muted hover:text-content'
              "
            >
              {{ group.label }}
            </Link>

            <button
              v-else
              type="button"
              class="pressable inline-flex h-12 items-center gap-1.5 rounded-[var(--radius-sm)] px-3.5 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
              :class="
                isCurrentGroup(group) || openGroup === group.label
                  ? 'text-content font-medium'
                  : 'text-content-muted hover:text-content'
              "
              :aria-expanded="openGroup === group.label"
              @click="toggle(group.label)"
            >
              {{ group.label }}
              <svg
                class="size-3 transition-transform duration-(--duration-fast) ease-(--ease-out)"
                :class="openGroup === group.label ? 'rotate-180' : ''"
                viewBox="0 0 12 12"
                fill="none"
                aria-hidden="true"
              >
                <path
                  d="M3 4.5 6 7.5 9 4.5"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </button>

            <!-- The marker sits on the bar rather than under the label, so
                 a group and its open panel read as one object. -->
            <span
              v-if="isCurrentGroup(group)"
              class="bg-accent absolute inset-x-3.5 bottom-0 h-0.5 rounded-full"
              aria-hidden="true"
            />

            <!-- Grows out of its own trigger: a panel anchored to the thing
                 you pressed needs no explanation. -->
            <div
              v-if="group.sections.length > 0 && openGroup === group.label"
              class="border-line bg-surface-raised absolute top-full left-0 z-20 mt-1 origin-top-left rounded-[var(--radius-lg)] border shadow-(--shadow-panel)"
              :class="group.sections.length > 1 ? 'flex gap-7 p-3.5' : 'min-w-[16rem] p-2.5'"
            >
              <div v-for="(section, index) in group.sections" :key="index" class="min-w-[13rem]">
                <p
                  v-if="section.label"
                  class="text-content-subtle px-2.5 pt-1 pb-2 text-[11px] font-semibold tracking-wide uppercase"
                >
                  {{ section.label }}
                </p>
                <ul class="space-y-0.5">
                  <li v-for="item in section.items" :key="item.href">
                    <Link
                      :href="item.href"
                      :aria-current="isCurrent(item.href) ? 'page' : undefined"
                      class="pressable block rounded-[var(--radius-sm)] px-2.5 py-2 text-sm whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
                      :class="
                        isCurrent(item.href)
                          ? 'bg-surface-sunken text-content font-medium'
                          : 'text-content-muted hover:bg-surface-sunken hover:text-content'
                      "
                    >
                      {{ item.label }}
                    </Link>
                  </li>
                </ul>
              </div>
            </div>
          </li>
        </ul>

        <!-- Addons. Rendered from what the enabled modules registered, so
             an installation with none sees nothing rather than an empty
             menu promising extensions. -->

        <!-- Below lg the whole map is one list. A dropdown inside a drawer
             is two taps to reach one link. -->
        <div v-if="mobileOpen" class="border-line border-t py-3 lg:hidden">
          <div v-for="group in visibleGroups" :key="group.label" class="mb-4 last:mb-0">
            <p class="text-content-subtle px-2 pb-1 text-[11px] font-medium">{{ group.label }}</p>

            <ul class="space-y-0.5">
              <li v-if="group.href">
                <Link
                  :href="group.href"
                  class="text-content-muted hover:bg-surface-sunken block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
                >
                  Overview
                </Link>
              </li>
              <template v-for="(section, index) in group.sections" :key="index">
                <li v-for="item in section.items" :key="item.href">
                  <Link
                    :href="item.href"
                    :aria-current="isCurrent(item.href) ? 'page' : undefined"
                    class="block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm"
                    :class="
                      isCurrent(item.href)
                        ? 'bg-surface-sunken text-content font-medium'
                        : 'text-content-muted hover:bg-surface-sunken'
                    "
                  >
                    {{ item.label }}
                  </Link>
                </li>
              </template>
            </ul>
          </div>
        </div>
      </nav>
    </header>

    <main id="main" class="px-5 py-9 sm:px-8 sm:py-12">
      <!-- Wider than the sidebar allowed: the horizontal space the menu
           gave back belongs to the tables, which is where an operator
           actually spends the day. -->
      <div class="mx-auto max-w-7xl">
        <div class="mb-9">
          <h1 class="text-[1.75rem] leading-[1.15] font-semibold tracking-[-0.02em]">
            {{ heading }}
          </h1>
          <p
            v-if="description"
            class="text-content-muted mt-2.5 max-w-[62ch] text-[0.9375rem] leading-relaxed"
          >
            {{ description }}
          </p>
        </div>

        <AppAlert v-if="flash?.error" tone="danger" class="mb-6">{{ flash.error }}</AppAlert>
        <AppAlert v-else-if="flash?.status" tone="success" class="mb-6">
          {{ flash.status }}
        </AppAlert>

        <slot />
      </div>
    </main>
  </div>
</template>
