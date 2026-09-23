<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../Components/AppAlert.vue'
import ThemeSwitch from '../Components/ThemeSwitch.vue'
import { usePermissions } from '../composables/usePermissions'

/**
 * Admin shell.
 *
 * Staff open this dozens of times a day, so there is no page transition and
 * no entrance animation. Motion here would be felt as latency. The only
 * motion in the shell is press feedback and colour on hover, both of which
 * answer a user action rather than announce themselves.
 *
 * Navigation renders only what the signed-in staff member may actually
 * reach. Hiding is presentation, not authorization: every destination
 * re-checks the same gate server side.
 */
defineProps<{ heading: string; description?: string }>()

const page = usePage()
const { can } = usePermissions()

const brand = computed(() => page.props.brand?.name ?? 'InfraCMS')

// Confirmation belongs to the shell rather than to each page: an action that
// redirects has no page left to report on.
const flash = computed(() => page.props.flash)
const user = computed(() => page.props.auth.user)

interface NavItem {
  label: string
  href: string
  permission?: string
}

interface NavGroup {
  label: string
  items: NavItem[]
}

/**
 * The admin panel map from the product specification, which is deliberately
 * shaped like the one WHMCS operators already know: Customers, Orders,
 * Billing, Products, System, in that order.
 *
 * Only the sections that exist are listed. A menu that advertises Billing
 * before invoices exist is a menu that lies, so each phase fills in its own
 * rows rather than the whole map being stubbed out up front.
 */
const groups: NavGroup[] = [
  {
    label: 'Dashboard',
    items: [{ label: 'Overview', href: '/admin', permission: 'platform.health.view' }],
  },
  {
    label: 'Customers',
    items: [
      { label: 'Customers', href: '/admin/customers', permission: 'crm.customers.view' },
      { label: 'Organizations', href: '/admin/organizations', permission: 'organizations.view' },
    ],
  },
  {
    label: 'Orders',
    items: [
      { label: 'Orders', href: '/admin/orders', permission: 'orders.view' },
      { label: 'Review queue', href: '/admin/orders/review', permission: 'orders.view' },
    ],
  },
  {
    // Where the platform meets somebody else's machines. Infrastructure
    // sits beside services rather than under System: an operator adding a
    // node is thinking about what runs on it.
    label: 'Services',
    items: [
      { label: 'Services', href: '/admin/services', permission: 'services.view' },
      { label: 'Infrastructure', href: '/admin/infrastructure', permission: 'infrastructure.view' },
    ],
  },
  {
    label: 'Domains',
    items: [{ label: 'Domains', href: '/admin/domains', permission: 'domains.view' }],
  },
  {
    // Invoices, payments and transactions land here in Phase 4. Currencies
    // sit with them rather than with the catalog, the way an operator
    // thinks of them.
    label: 'Billing',
    items: [
      { label: 'Invoices', href: '/admin/invoices', permission: 'billing.invoices.view' },
      {
        label: 'Currencies',
        href: '/admin/catalog/currencies',
        permission: 'catalog.products.view',
      },
    ],
  },
  {
    label: 'Products',
    items: [
      { label: 'Products', href: '/admin/catalog/products', permission: 'catalog.products.view' },
      { label: 'Groups', href: '/admin/catalog/groups', permission: 'catalog.groups.view' },
      { label: 'Promotions', href: '/admin/promotions', permission: 'promotions.view' },
      { label: 'TLD pricing', href: '/admin/catalog/tlds', permission: 'catalog.tlds.view' },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Staff', href: '/admin/staff', permission: 'identity.staff.view' },
      { label: 'Roles', href: '/admin/roles', permission: 'access.roles.view' },
      { label: 'Settings', href: '/admin/settings', permission: 'settings.view' },
      { label: 'Audit log', href: '/admin/audit', permission: 'platform.audit.view' },
      { label: 'Queues', href: '/horizon', permission: 'platform.queue.view' },
    ],
  },
]

const visibleGroups = computed(() =>
  groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.permission || can(item.permission)),
    }))
    .filter((group) => group.items.length > 0),
)

const currentPath = computed(() => page.url.split('?')[0] ?? '/')

/**
 * The longest matching destination wins.
 *
 * `/admin/orders` is a prefix of `/admin/orders/review`, and without this
 * both light up at once — which tells the operator nothing about where
 * they are.
 */
const currentHref = computed(() => {
  const candidates = groups
    .flatMap((group) => group.items.map((item) => item.href))
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
</script>

<template>
  <div class="bg-surface min-h-[100dvh]">
    <a
      href="#main"
      class="focus:bg-surface-raised sr-only rounded-[var(--radius-sm)] focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-20 focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
      Skip to content
    </a>

    <div class="lg:grid lg:grid-cols-[248px_1fr]">
      <!-- Sidebar. Collapses to a horizontal strip below lg rather than a
           drawer: Phase 0 has too few destinations to justify one. -->
      <aside
        class="border-line bg-surface-raised border-b lg:sticky lg:top-0 lg:h-[100dvh] lg:border-r lg:border-b-0"
      >
        <div class="flex h-16 items-center px-5">
          <Link
            href="/admin"
            class="pressable rounded-[var(--radius-sm)] text-sm font-semibold tracking-tight"
          >
            {{ brand }}
          </Link>
          <span class="text-content-subtle ml-2 text-xs">Admin</span>
        </div>

        <nav aria-label="Admin" class="px-3 pb-4 lg:pb-6">
          <div v-for="group in visibleGroups" :key="group.label" class="mb-5 last:mb-0">
            <p class="text-content-subtle px-2 pb-1.5 text-[11px] font-medium">
              {{ group.label }}
            </p>
            <ul class="space-y-0.5">
              <li v-for="item in group.items" :key="item.href">
                <Link
                  :href="item.href"
                  :aria-current="isCurrent(item.href) ? 'page' : undefined"
                  class="pressable block rounded-[var(--radius-sm)] px-2 py-1.5 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
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
        </nav>
      </aside>

      <div class="flex min-h-[100dvh] flex-col">
        <header
          class="border-line flex h-16 shrink-0 items-center justify-end gap-3 border-b px-5 sm:px-8"
        >
          <ThemeSwitch />
          <span v-if="user" class="text-content-muted text-sm">{{ user.email }}</span>
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
        </header>

        <main id="main" class="flex-1 px-5 py-8 sm:px-8 sm:py-10">
          <div class="mx-auto max-w-5xl">
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
    </div>
  </div>
</template>
