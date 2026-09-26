<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../Components/AppButton.vue'
import AppCard from '../Components/AppCard.vue'
import AppMenu from '../Components/AppMenu.vue'
import LanguageSwitch from '../Components/LanguageSwitch.vue'
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
const props = defineProps<{ heading: string; description?: string }>()

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
  /** The rows behind a dropdown, where the section has more than one screen. */
  items?: Destination[]
}

function allowed(items: Destination[]): Destination[] {
  return items
    .filter((item) => item.permission === null || can.value.has(item.permission))
    .map((item) => (item.items ? { ...item, items: allowed(item.items) } : item))
}

/*
 * What they came for, grouped the way the reference groups it.
 *
 * A section with one screen is a link; a section with several is a dropdown,
 * which is how a customer who wants "my invoices" finds it without learning
 * that Billing has three pages. Destinations land with the phases that build
 * them: a row is here when the screen behind it exists, because a nav item
 * that leads to "coming soon" teaches a customer that the navigation lies.
 */
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
    {
      label: t('portal.nav.billing'),
      href: '/client/billing',
      permission: 'portal.billing.view',
      items: [
        { label: t('portal.nav.invoices'), href: '/client/billing', permission: null },
        {
          label: t('portal.nav.transactions'),
          href: '/client/billing/transactions',
          permission: null,
        },
        {
          label: t('portal.nav.billing_details'),
          href: '/client/billing/details',
          permission: null,
        },
      ],
    },
    {
      label: t('portal.nav.support'),
      href: '/client/support',
      permission: 'portal.tickets.view',
      items: [
        { label: t('portal.nav.tickets'), href: '/client/support', permission: null },
        { label: t('portal.nav.new_ticket'), href: '/client/support/new', permission: null },
      ],
    },
  ]),
)

/**
 * The panel beside the page.
 *
 * The reference keeps the customer's own details and a short list of things
 * they came to start on the left of every screen, and it is the one piece of
 * chrome that makes a portal feel like an account rather than a website. The
 * rows are all destinations that exist.
 */
const shortcuts = computed(() =>
  allowed([
    { label: t('portal.shell.order_services'), href: '/store', permission: null },
    { label: t('portal.shell.register_domain'), href: '/domains', permission: null },
    {
      label: t('portal.nav.new_ticket'),
      href: '/client/support/new',
      permission: 'portal.tickets.view',
    },
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

/**
 * The trail the reference prints above every page.
 *
 * It names the **section**, never the page's own sentence. An overview headed
 * "Hello, Design." would otherwise put that in the breadcrumb, where it reads
 * as a bug rather than as a greeting - the h1 is the place for a sentence and
 * the trail is the place for a noun.
 */
const breadcrumb = computed(() => {
  const section =
    destinations.value.find(
      (item) => item.href !== '/client' && currentPath.value.startsWith(item.href),
    ) ?? destinations.value.find((item) => item.href === '/client')

  if (!section) return []

  // A screen under a section adds its own name; the section's own landing
  // page does not repeat it.
  return section.label === props.heading ? [section.label] : [section.label, props.heading]
})

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

    <!--
      The utility strip: who is signed in, and nothing else.

      The reference puts it above everything at 26px in the dark, and it earns
      that because a portal is the one surface where somebody may be looking at
      an account that is not theirs - a staff member mid-impersonation, or a
      contact on a company account with four other people on it.
    -->
    <div class="on-chrome bg-background">
      <div
        class="text-chrome mx-auto flex w-full max-w-[1200px] items-center justify-end gap-2 px-5 py-1.5 sm:px-8"
      >
        <span class="text-content-subtle">{{ t('portal.shell.signed_in_as') }}</span>
        <span class="text-content truncate font-medium">{{ user?.name }}</span>
      </div>
    </div>

    <header class="border-line bg-surface-primary border-b">
      <!-- Who. The brand first, because on a white-label installation this
           page belongs to the reseller and not to us. -->
      <div class="mx-auto flex w-full max-w-[1200px] items-center gap-4 px-5 py-4 sm:px-8">
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
          <!-- Not while impersonating: the row it writes is the
               customer's, and their reading language is not what an
               operator is standing in for them to change. -->
          <LanguageSwitch v-if="!impersonation?.active" url="/locale" />
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
      <!--
        Where. Underlined rather than filled: a tab bar is what a customer
        reads as "the sections of my account", and the accent under the
        current one is the only brand colour on the page that is not a link.

        A section with several screens is a dropdown rather than a row, so
        Billing is one destination a customer can aim at instead of three
        they have to choose between before they have arrived.

        Wrapping, not scrolling. `overflow-x-auto` drew a scrollbar under the
        portal nav on every desktop and still clipped the last destination.
      -->
      <nav
        :aria-label="t('portal.shell.destinations')"
        class="mx-auto w-full max-w-[1200px] px-5 sm:px-8"
      >
        <ul class="-mb-px flex flex-wrap items-center gap-x-1">
          <li v-for="item in destinations" :key="item.href">
            <AppMenu
              v-if="item.items && item.items.length > 1"
              :label="item.label"
              width="13rem"
              align="start"
              tab
              :current="isCurrent(item.href)"
            >
              <Link
                v-for="row in item.items"
                :key="row.href"
                :href="row.href"
                class="pressable hover:bg-surface-secondary text-body block rounded-sm px-2 py-1.5"
                role="menuitem"
              >
                {{ row.label }}
              </Link>
            </AppMenu>
            <Link
              v-else
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

    <!-- The trail. One line, and it names the section rather than repeating
         the page: "Billing > Invoices" where the two differ, and nothing at
         all where they do not. -->
    <div class="border-line bg-background-subtle border-b">
      <nav
        :aria-label="t('portal.shell.trail')"
        class="text-chrome mx-auto flex w-full max-w-[1200px] items-center gap-1.5 px-5 py-2 sm:px-8"
      >
        <Link href="/client" class="text-content-muted hover:text-content">
          {{ t('portal.shell.home') }}
        </Link>
        <span aria-hidden="true" class="text-content-subtle">/</span>
        <template v-for="(crumb, index) in breadcrumb" :key="crumb">
          <span v-if="index < breadcrumb.length - 1" class="text-content-muted truncate">{{
            crumb
          }}</span>
          <span v-else class="text-content truncate font-medium">{{ crumb }}</span>
          <span v-if="index < breadcrumb.length - 1" aria-hidden="true" class="text-content-subtle"
            >/</span
          >
        </template>
      </nav>
    </div>

    <div class="mx-auto flex w-full max-w-[1200px] flex-1 gap-6 px-5 py-6 sm:px-8">
      <!--
        The account, beside the page.

        Below `lg` it goes under the content rather than above it: on a phone
        the thing somebody opened the page for should not be pushed off the
        screen by a panel that says their own name back to them.
      -->
      <aside class="order-2 w-full shrink-0 lg:order-1 lg:w-60">
        <div class="flex flex-col gap-4">
          <AppCard v-if="user" :title="t('portal.shell.your_info')" icon="clients" tone="brand">
            <p class="text-body font-medium">{{ user.name }}</p>
            <p class="text-content-muted text-chrome truncate">{{ user.email }}</p>

            <AppButton href="/client/profile" variant="secondary" size="sm" class="mt-4 w-full">
              {{ t('portal.shell.update_details') }}
            </AppButton>
          </AppCard>

          <AppCard
            v-if="shortcuts.length > 0"
            :title="t('portal.shell.shortcuts')"
            icon="add"
            tone="neutral"
            flush
          >
            <ul class="divide-line divide-y">
              <li v-for="row in shortcuts" :key="row.href">
                <Link
                  :href="row.href"
                  class="pressable hover:bg-surface-hover text-body block px-5 py-2.5 transition-colors duration-(--duration-fast)"
                >
                  {{ row.label }}
                </Link>
              </li>
            </ul>
          </AppCard>
        </div>
      </aside>

      <main id="main" class="order-1 min-w-0 flex-1 lg:order-2">
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
    </div>

    <!-- Whose shop this is. The admin footer says the same thing for the
         same reason: on a white-label installation the name at the bottom
         of the page is the one the customer has a contract with. -->
    <footer class="border-line mt-8 border-t">
      <div
        class="text-content-subtle text-label mx-auto w-full max-w-[1200px] px-5 py-4 sm:px-8"
        data-portal-footer
      >
        &copy; {{ year }} {{ brand.name }}
      </div>
    </footer>
  </div>
</template>
