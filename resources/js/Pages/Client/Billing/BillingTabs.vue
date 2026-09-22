<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

import { useTranslations } from '../../../composables/useTranslations'

/**
 * The three billing destinations.
 *
 * A sub-navigation rather than seven top-level rows: billing is one place
 * in a customer's head, and the top bar already carries the sections that
 * are genuinely different from each other.
 */
defineProps<{ current: 'invoices' | 'transactions' | 'details' }>()

const { t } = useTranslations()

const tabs = [
  { key: 'invoices', href: '/client/billing', label: 'portal.billing.invoices' },
  {
    key: 'transactions',
    href: '/client/billing/transactions',
    label: 'portal.billing.transactions',
  },
  { key: 'details', href: '/client/billing/details', label: 'portal.billing.details' },
] as const
</script>

<template>
  <nav class="border-line mb-6 flex gap-1 border-b pb-px" aria-label="Billing">
    <Link
      v-for="tab in tabs"
      :key="tab.key"
      :href="tab.href"
      :aria-current="current === tab.key ? 'page' : undefined"
      class="pressable -mb-px rounded-t-[var(--radius-sm)] border-b-2 px-3 py-2 text-sm transition-colors duration-(--duration-fast)"
      :class="
        current === tab.key
          ? 'border-accent text-content font-medium'
          : 'text-content-muted hover:text-content border-transparent'
      "
    >
      {{ t(tab.label) }}
    </Link>
  </nav>
</template>
