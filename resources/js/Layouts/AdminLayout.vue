<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import AppAlert from '../Components/AppAlert.vue'
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
const { can } = usePermissions()

// The brand, and its colours written onto the document. Reseller staff see
// their own name and their own accent, on the same deployment.
const { brand } = useBranding()

// Confirmation belongs to the shell rather than to each page: an action that
// redirects has no page left to report on.
const flash = computed(() => page.props.flash)
const user = computed(() => page.props.auth.user)

interface NavItem {
  label: string
  href: string
  permission?: string
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
          { label: 'Customers', href: '/admin/customers', permission: 'crm.customers.view' },
          {
            label: 'Manage users',
            href: '/admin/customer-users',
            permission: 'crm.customers.view',
          },
          {
            label: 'Add new client',
            href: '/admin/clients/create',
            permission: 'crm.customers.manage',
          },
          // The same screen the Services menu opens. It is listed twice on
          // purpose: a WHMCS operator looks for it under Clients, and a
          // hosting operator looks for it under Services.
          {
            label: 'Products/Services',
            href: '/admin/services',
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
          { label: 'All orders', href: '/admin/orders', permission: 'orders.view' },
          { label: 'Review queue', href: '/admin/orders/review', permission: 'orders.view' },
        ],
      },
    ],
  },
  {
    label: 'Billing',
    sections: [
      {
        items: [
          { label: 'Invoices', href: '/admin/invoices', permission: 'billing.invoices.view' },
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
    // Where the platform meets somebody else's machines. Its own menu
    // rather than a corner of Setup, because an operator adding a node is
    // thinking about what runs on it.
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
            label: 'Infrastructure',
            href: '/admin/infrastructure',
            permission: 'infrastructure.view',
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
          { label: 'TLD pricing', href: '/admin/catalog/tlds', permission: 'catalog.tlds.view' },
        ],
      },
    ],
  },
  {
    label: 'Support',
    sections: [
      {
        items: [
          { label: 'Tickets', href: '/admin/support', permission: 'support.tickets.view' },
          {
            label: 'Announcements',
            href: '/admin/content/announcements',
            permission: 'content.announcements.manage',
          },
          {
            label: 'Knowledge base',
            href: '/admin/content/articles',
            permission: 'content.kb.manage',
          },
        ],
      },
    ],
  },
  {
    // WHMCS's Utilities: what an operator opens when something looks wrong,
    // rather than when they are configuring something.
    label: 'Utilities',
    sections: [
      {
        label: 'Automation',
        items: [
          { label: 'Scheduled tasks', href: '/admin/automation', permission: 'automation.view' },
          { label: 'Operations', href: '/admin/operations', permission: 'operations.view' },
        ],
      },
      {
        label: 'Logs and health',
        items: [
          { label: 'System health', href: '/admin/health', permission: 'platform.health.view' },
          { label: 'Audit log', href: '/admin/audit', permission: 'platform.audit.view' },
          {
            label: 'Notification log',
            href: '/admin/notifications/log',
            permission: 'notifications.view',
          },
          {
            label: 'API activity',
            href: '/admin/api/activity',
            permission: 'platform.audit.view',
          },
          { label: 'Queues', href: '/horizon', permission: 'platform.queue.view' },
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

const visibleGroups = computed(() =>
  groups
    .map((group) => ({
      ...group,
      sections: (group.sections ?? [])
        .map((section) => ({
          ...section,
          items: section.items.filter((item) => !item.permission || can(item.permission)),
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
      <div class="border-line flex h-12 items-center gap-3 border-b px-4 sm:px-6">
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
          <ThemeSwitch />
          <span v-if="user" class="text-content-muted hidden text-sm sm:inline">
            {{ user.email }}
          </span>
          <Link
            href="/admin/security"
            class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] px-2 py-1 text-sm transition-colors duration-(--duration-fast)"
          >
            Security
          </Link>
          <Link
            href="/admin/logout"
            method="post"
            as="button"
            class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] px-2 py-1 text-sm transition-colors duration-(--duration-fast)"
          >
            Sign out
          </Link>
        </div>
      </div>

      <nav data-admin-nav aria-label="Admin" class="px-2 sm:px-4">
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
              class="pressable inline-flex h-11 items-center rounded-[var(--radius-sm)] px-3 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
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
              class="pressable inline-flex h-11 items-center gap-1.5 rounded-[var(--radius-sm)] px-3 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
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
              class="bg-accent absolute inset-x-3 bottom-0 h-0.5 rounded-full"
              aria-hidden="true"
            />

            <!-- Grows out of its own trigger: a panel anchored to the thing
                 you pressed needs no explanation. -->
            <div
              v-if="group.sections.length > 0 && openGroup === group.label"
              class="border-line bg-surface-raised absolute top-full left-0 z-20 mt-1 origin-top-left rounded-[var(--radius-lg)] border shadow-(--shadow-panel)"
              :class="group.sections.length > 1 ? 'flex gap-6 p-3' : 'min-w-[15rem] p-2'"
            >
              <div v-for="(section, index) in group.sections" :key="index" class="min-w-[13rem]">
                <p
                  v-if="section.label"
                  class="text-content-subtle px-2 pt-1 pb-1.5 text-[11px] font-medium"
                >
                  {{ section.label }}
                </p>
                <ul class="space-y-0.5">
                  <li v-for="item in section.items" :key="item.href">
                    <Link
                      :href="item.href"
                      :aria-current="isCurrent(item.href) ? 'page' : undefined"
                      class="pressable block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
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

    <main id="main" class="px-4 py-8 sm:px-6 sm:py-10">
      <!-- Wider than the sidebar allowed: the horizontal space the menu
           gave back belongs to the tables, which is where an operator
           actually spends the day. -->
      <div class="mx-auto max-w-7xl">
        <div class="mb-8">
          <h1 class="text-2xl font-semibold tracking-tight">{{ heading }}</h1>
          <p
            v-if="description"
            class="text-content-muted mt-1.5 max-w-[60ch] text-sm leading-relaxed"
          >
            {{ description }}
          </p>
        </div>

        <AppAlert v-if="flash?.error" tone="danger" class="mb-5">{{ flash.error }}</AppAlert>
        <AppAlert v-else-if="flash?.status" tone="success" class="mb-5">
          {{ flash.status }}
        </AppAlert>

        <slot />
      </div>
    </main>
  </div>
</template>
