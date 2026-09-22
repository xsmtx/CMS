<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

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

// Phase 0 ships the sections the foundation actually owns. Each later phase
// appends its own group rather than editing this one.
const groups: NavGroup[] = [
  {
    label: 'Operations',
    items: [
      { label: 'Dashboard', href: '/admin', permission: 'platform.health.view' },
      { label: 'Queues', href: '/horizon', permission: 'platform.queue.view' },
      { label: 'Audit log', href: '/admin/audit', permission: 'platform.audit.view' },
    ],
  },
  {
    label: 'People',
    items: [
      { label: 'Customers', href: '/admin/customers', permission: 'crm.customers.view' },
      { label: 'Staff', href: '/admin/staff', permission: 'identity.staff.view' },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Organizations', href: '/admin/organizations', permission: 'organizations.view' },
      { label: 'Roles', href: '/admin/roles', permission: 'access.roles.view' },
      { label: 'Settings', href: '/admin/settings', permission: 'settings.view' },
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

function isCurrent(href: string): boolean {
  return href === '/admin' ? currentPath.value === '/admin' : currentPath.value.startsWith(href)
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

            <slot />
          </div>
        </main>
      </div>
    </div>
  </div>
</template>
