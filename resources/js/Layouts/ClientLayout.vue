<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppMenu from '../Components/AppMenu.vue'
import ThemeSwitch from '../Components/ThemeSwitch.vue'
import { useBranding } from '../composables/useBranding'
import { useTranslations } from '../composables/useTranslations'

/**
 * Client area shell.
 *
 * Deliberately not the admin console. An operator lives in a dense rail with
 * forty destinations and a command palette; a customer visits half a dozen
 * times a year to answer one question — what am I paying for, and what do I
 * owe. So the portal is a **brand band with a tab bar under it**: the shape
 * of every account area a customer has ever used, and the one place in this
 * product where the seller's own name is the first thing on the page.
 *
 * Two rows, because they answer different questions. The first is *who*: the
 * brand they buy from, the theme, and their own account. The second is
 * *where*: the six things they came for, with the current one underlined in
 * the brand's accent.
 *
 * Everything about the account itself — profile, security, contacts, API
 * tokens, notifications — lives in the account menu rather than the bar.
 * Eleven destinations in one row wrapped onto a second line on a laptop, and
 * a customer looking for their invoices had to read past "Webhooks" to find
 * them.
 *
 * The column is 1024px and not wider. The admin is edge-to-edge because an
 * operator compares forty rows; a customer reads one invoice, and a form
 * whose fields run 1100px is a form nobody's eye tracks back across.
 *
 * No page transitions, for the same reason as the admin shell.
 */
defineProps<{ heading: string; description?: string }>()

const page = usePage()

// The brand a customer buys from — their reseller's, not the provider's
// behind it — with its colours written onto the document.
const { brand } = useBranding()
const user = computed(() => page.props.auth.user)
const impersonation = computed(() => page.props.impersonation)

const { t } = useTranslations()

const year = new Date().getFullYear()

function stopImpersonating(): void {
  router.delete('/client/impersonation')
}

const can = computed(() => new Set(page.props.auth.permissions))

/**
 * The placeholder face: whatever letters the account already has.
 *
 * An email address read across the top of every page is somebody's
 * identifier on a screen other people walk past.
 */
const initials = computed(() => {
  const name = user.value?.name ?? ''

  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toLocaleUpperCase())
      .join('') || '?'
  )
})

interface Destination {
  label: string
  href: string
  permission: string | null
}

function allowed(items: Destination[]): Destination[] {
  return items.filter((item) => item.permission === null || can.value.has(item.permission))
}

// What they came for. Destinations land with the phases that build them: a
// row is here when the screen behind it exists, because a nav item that
// leads to "coming soon" teaches a customer that the navigation lies.
const destinations = computed(() =>
  allowed([
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
    { label: t('portal.nav.support'), href: '/client/support', permission: 'portal.tickets.view' },
  ]),
)

// The account itself, behind the face in the corner.
const accountLinks = computed(() =>
  allowed([
    { label: t('portal.nav.profile'), href: '/client/profile', permission: null },
    { label: t('portal.nav.security'), href: '/security', permission: null },
    { label: t('portal.nav.notifications'), href: '/client/notifications', permission: null },
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
  ]),
)

const currentPath = computed(() => page.url.split('?')[0] ?? '/')

function isCurrent(href: string): boolean {
  return href === '/client' ? currentPath.value === '/client' : currentPath.value.startsWith(href)
}
</script>

<template>
  <div class="bg-background flex min-h-[100dvh] flex-col">
    <a
      href="#main"
      class="focus:bg-surface-primary sr-only rounded-sm focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-20 focus:px-3 focus:py-2 focus:shadow-(--shadow-panel)"
    >
      {{ t('portal.shell.skip') }}
    </a>

    <!--
      Not dismissible, and deliberately loud. The whole safeguard of
      impersonation is that the person doing it can always see that the
      session is not really theirs.
    -->
    <div
      v-if="impersonation?.active"
      class="bg-warning text-surface-secondary text-body flex flex-wrap items-center justify-center gap-3 px-4 py-2 font-medium"
    >
      <span>
        {{ t('identity.impersonation.active', { name: impersonation.subjectName ?? '' }) }}
      </span>
      <button
        type="button"
        class="pressable text-chrome rounded-sm bg-black/15 px-2 py-0.5 underline underline-offset-4"
        @click="stopImpersonating"
      >
        {{ t('identity.impersonation.stop') }}
      </button>
    </div>

    <header class="border-line bg-surface-chrome border-b">
      <!-- Who. The brand first, because on a white-label installation this
           page belongs to the reseller and not to us. -->
      <div class="mx-auto flex w-full max-w-5xl items-center gap-4 px-5 pt-4 sm:px-8">
        <Link
          href="/client"
          class="pressable text-title flex min-w-0 items-center gap-2 rounded-sm font-semibold tracking-tight"
        >
          <img
            v-if="brand.logoUrl"
            :src="brand.logoUrl"
            :alt="brand.name"
            class="h-7 w-auto max-w-[10rem] object-contain"
          />
          <span v-else class="truncate">{{ brand.portalName }}</span>
        </Link>

        <div class="ml-auto flex shrink-0 items-center gap-2">
          <ThemeSwitch />

          <AppMenu v-if="user" :label="initials" align="end" width="14rem" avatar>
            <p class="border-line mb-1 border-b px-2 pb-2">
              <span class="text-body block truncate font-medium">{{ user.name }}</span>
              <span class="text-content-muted text-chrome block truncate">{{ user.email }}</span>
            </p>
            <Link
              v-for="link in accountLinks"
              :key="link.href"
              :href="link.href"
              class="pressable hover:bg-surface-secondary text-body block rounded-sm px-2 py-1.5"
              role="menuitem"
            >
              {{ link.label }}
            </Link>
            <Link
              href="/logout"
              method="post"
              as="button"
              class="pressable hover:bg-surface-secondary text-body border-line mt-1 block w-full rounded-sm border-t px-2 py-1.5 text-left"
              role="menuitem"
            >
              {{ t('portal.shell.sign_out') }}
            </Link>
          </AppMenu>
        </div>
      </div>

      <!--
        Where. Underlined rather than filled: a tab bar is what a customer
        reads as "the sections of my account", and the accent under the
        current one is the only brand colour on the page that is not a link.

        Wrapping, not scrolling. `overflow-x-auto` drew a scrollbar under the
        portal nav on every desktop and still clipped the last destination,
        which is a link a customer cannot see and cannot scroll to without
        noticing the bar.
      -->
      <nav
        :aria-label="t('portal.shell.destinations')"
        class="mx-auto w-full max-w-5xl px-5 sm:px-8"
      >
        <ul class="-mb-px flex flex-wrap items-center gap-x-1">
          <li v-for="item in destinations" :key="item.href">
            <Link
              :href="item.href"
              :aria-current="isCurrent(item.href) ? 'page' : undefined"
              class="pressable text-body -mb-px block border-b-2 px-3 py-3 transition-colors duration-(--duration-fast) ease-(--ease-out)"
              :class="
                isCurrent(item.href)
                  ? 'border-brand text-content font-medium'
                  : 'text-content-muted hover:border-line-strong hover:text-content border-transparent'
              "
            >
              {{ item.label }}
            </Link>
          </li>
        </ul>
      </nav>
    </header>

    <main id="main" class="mx-auto w-full max-w-5xl flex-1 px-5 py-8 sm:px-8">
      <!-- Title and actions on one line, the explanation under it in chrome
           type — the same rule the admin header follows. A customer reading
           their own invoices wants the name of the screen, not a masthead. -->
      <div class="mb-6 flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
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

    <!-- Whose shop this is. The admin footer says the same thing for the
         same reason: on a white-label installation the name at the bottom
         of the page is the one the customer has a contract with. -->
    <footer class="border-line mt-8 border-t">
      <div
        class="text-content-subtle text-label mx-auto w-full max-w-5xl px-5 py-4 sm:px-8"
        data-portal-footer
      >
        &copy; {{ year }} {{ brand.name }}
      </div>
    </footer>
  </div>
</template>
