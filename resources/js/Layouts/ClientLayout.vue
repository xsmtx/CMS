<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import { useBranding } from '../composables/useBranding'
import { useTranslations } from '../composables/useTranslations'

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

// The brand a customer buys from — their reseller's, not the provider's
// behind it — with its colours written onto the document.
const { brand } = useBranding()
const user = computed(() => page.props.auth.user)
const impersonation = computed(() => page.props.impersonation)

function stopImpersonating(): void {
  router.delete('/client/impersonation')
}

const { t } = useTranslations()

const can = computed(() => new Set(page.props.auth.permissions))

// Destinations land with the phases that build them. A row is here when
// the screen behind it exists: a nav item that leads to "coming soon"
// teaches a customer that the navigation lies.
const items = computed(() =>
  [
    { label: t('portal.nav.overview'), href: '/client', permission: null },
    {
      label: t('portal.nav.services'),
      href: '/client/services',
      permission: 'portal.services.view',
    },
    {
      label: t('portal.nav.domains'),
      href: '/client/domains',
      permission: 'portal.domains.view',
    },
    { label: t('portal.nav.orders'), href: '/client/orders', permission: 'portal.orders.view' },
    { label: t('portal.nav.billing'), href: '/client/billing', permission: 'portal.billing.view' },
    {
      label: t('portal.nav.support'),
      href: '/client/support',
      permission: 'portal.tickets.view',
    },
    {
      label: t('portal.nav.contacts'),
      href: '/client/contacts',
      permission: 'portal.contacts.manage',
    },
    {
      label: t('portal.nav.developer'),
      href: '/client/developer/tokens',
      permission: 'portal.tokens.manage',
    },
    {
      label: t('portal.nav.webhooks'),
      href: '/client/developer/webhooks',
      permission: 'portal.tokens.manage',
    },
    { label: t('portal.nav.profile'), href: '/client/profile', permission: null },
    { label: t('portal.nav.security'), href: '/security', permission: null },
  ].filter((item) => item.permission === null || can.value.has(item.permission)),
)

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

    <header class="border-line bg-chrome border-b">
      <div class="mx-auto flex h-[4.5rem] w-full max-w-5xl items-center gap-6 px-5 sm:px-8">
        <Link
          href="/client"
          class="pressable flex items-center gap-2 rounded-[var(--radius-sm)] text-sm font-semibold tracking-tight"
        >
          <img
            v-if="brand.logoUrl"
            :src="brand.logoUrl"
            :alt="brand.name"
            class="h-7 w-auto max-w-[10rem] object-contain"
          />
          <span v-else>{{ brand.portalName }}</span>
        </Link>

        <nav aria-label="Client area" class="min-w-0 flex-1">
          <ul class="flex items-center gap-0.5 overflow-x-auto">
            <li v-for="item in items" :key="item.href" class="shrink-0">
              <Link
                :href="item.href"
                :aria-current="isCurrent(item.href) ? 'page' : undefined"
                class="pressable block rounded-[var(--radius-sm)] px-3.5 py-2 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
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

    <main id="main" class="mx-auto w-full max-w-5xl px-5 py-8 sm:px-8">
      <!-- Title and actions on one line, the explanation under it in chrome
           type — the same rule the admin header follows. A customer reading
           their own invoices wants the name of the screen, not a masthead. -->
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

      <slot />
    </main>
  </div>
</template>
