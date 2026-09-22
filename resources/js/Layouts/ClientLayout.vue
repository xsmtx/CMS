<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Client area shell.
 *
 * Top navigation rather than a sidebar: a customer has a handful of
 * destinations, and the horizontal bar keeps the content column wide on the
 * laptop screens most customers use. No page transitions, for the same
 * reason as the admin shell.
 */
defineProps<{ heading: string; description?: string }>()

const page = usePage()

const brand = computed(() => page.props.brand?.name ?? 'InfraCMS')
const user = computed(() => page.props.auth.user)
const impersonation = computed(() => page.props.impersonation)

function stopImpersonating(): void {
  router.delete('/client/impersonation')
}

// Destinations land with the phases that build them; the shell already
// reserves their place so the information architecture does not shift under
// customers later.
const items = [
  { label: 'Overview', href: '/client' },
  { label: 'Services', href: '/client/services' },
  { label: 'Domains', href: '/client/domains' },
  { label: 'Billing', href: '/client/billing' },
  { label: 'Support', href: '/client/support' },
  { label: 'Contacts', href: '/client/contacts' },
  { label: 'Profile', href: '/client/profile' },
  { label: 'Security', href: '/security' },
]

const currentPath = computed(() => page.url.split('?')[0] ?? '/')

function isCurrent(href: string): boolean {
  return href === '/client' ? currentPath.value === '/client' : currentPath.value.startsWith(href)
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

    <!--
      Not dismissible, and deliberately loud. The whole safeguard of
      impersonation is that the person doing it can always see that the
      session is not really theirs.
    -->
    <div
      v-if="impersonation?.active"
      class="bg-warning text-surface-sunken flex flex-wrap items-center justify-center gap-3 px-4 py-2 text-sm font-medium"
    >
      <span>You are viewing this account as {{ impersonation.subjectName }}.</span>
      <button
        type="button"
        class="pressable rounded-[var(--radius-sm)] bg-black/15 px-2 py-0.5 text-xs underline underline-offset-4"
        @click="stopImpersonating"
      >
        Stop
      </button>
    </div>

    <header class="border-line bg-surface-raised border-b">
      <div class="mx-auto flex h-16 w-full max-w-5xl items-center gap-6 px-5 sm:px-6">
        <Link
          href="/client"
          class="pressable rounded-[var(--radius-sm)] text-sm font-semibold tracking-tight"
        >
          {{ brand }}
        </Link>

        <nav aria-label="Client area" class="min-w-0 flex-1">
          <ul class="flex items-center gap-0.5 overflow-x-auto">
            <li v-for="item in items" :key="item.href" class="shrink-0">
              <Link
                :href="item.href"
                :aria-current="isCurrent(item.href) ? 'page' : undefined"
                class="pressable block rounded-[var(--radius-sm)] px-3 py-1.5 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
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
        </nav>

        <span v-if="user" class="text-content-muted hidden shrink-0 text-sm sm:block">
          {{ user.name }}
        </span>
      </div>
    </header>

    <main id="main" class="mx-auto w-full max-w-5xl px-5 py-10 sm:px-6">
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
    </main>
  </div>
</template>
