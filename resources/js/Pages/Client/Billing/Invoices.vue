<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'
import BillingTabs from './BillingTabs.vue'

interface InvoiceRow {
  id: string
  number: string
  status: string
  statusLabel: string
  total: string
  balance: string
  isOwed: boolean
  dueOn: string | null
}

const props = defineProps<{
  invoices: { data: InvoiceRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null }
  statuses: { value: string; label: string }[]
  outstanding: { currency: string; amount: string }[]
}>()

const { t } = useTranslations()

const active = computed(() => props.filters.status)

function filterBy(status: string | null): void {
  router.get('/client/billing', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
  })
}
</script>

<template>
  <Head :title="t('billing.portal.invoices_title')" />

  <ClientLayout
    :heading="t('billing.portal.invoices_title')"
    :description="t('billing.portal.invoices_description')"
  >
    <BillingTabs current="invoices" />

    <!--
      Grouped by currency, never summed across them. A single figure would
      need a rate nobody in this conversation agreed on.
    -->
    <div v-if="outstanding.length > 0" class="mb-6 flex flex-wrap gap-6">
      <div v-for="row in outstanding" :key="row.currency">
        <p class="text-content-muted text-chrome">
          {{ t('billing.portal.outstanding') }} · {{ row.currency }}
        </p>
        <p class="mt-0.5 text-xl font-semibold tracking-tight tabular-nums">{{ row.amount }}</p>
      </div>
    </div>
    <p v-else class="text-content-muted text-body mb-6">{{ t('billing.portal.nothing_owed') }}</p>

    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          active === null
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterBy(null)"
      >
        {{ t('billing.invoices.all') }}
      </button>
      <button
        v-for="status in statuses"
        :key="status.value"
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          active === status.value
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterBy(status.value)"
      >
        {{ status.label }}
      </button>
    </div>

    <AppTable
      v-if="invoices.data.length > 0"
      :headers="[
        t('billing.invoices.number'),
        t('billing.invoices.status'),
        t('billing.invoices.due'),
        t('billing.invoices.total'),
        t('billing.invoices.balance'),
        '',
      ]"
    >
      <tr v-for="invoice in invoices.data" :key="invoice.id">
        <td class="px-4 py-2.5 font-medium">
          <Link
            :href="`/client/billing/invoices/${invoice.number}`"
            class="underline-offset-4 hover:underline"
          >
            {{ invoice.number }}
          </Link>
        </td>
        <td class="px-4 py-2.5">
          <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">{{ invoice.dueOn ?? '—' }}</td>
        <td class="px-4 py-2.5 tabular-nums">{{ invoice.total }}</td>
        <td class="px-4 py-2.5 font-medium tabular-nums">{{ invoice.balance }}</td>
        <td class="px-4 py-2.5 text-right">
          <Link
            :href="`/client/billing/invoices/${invoice.number}`"
            class="text-content-muted hover:text-content text-chrome underline-offset-4 hover:underline"
          >
            {{ invoice.isOwed ? t('billing.payments.pay_now') : t('billing.portal.view') }}
          </Link>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      :title="t('billing.portal.no_invoices')"
      :description="t('billing.portal.invoices_description')"
    />

    <p v-if="invoices.lastPage > 1" class="text-content-muted text-chrome mt-4">
      {{ invoices.currentPage }} / {{ invoices.lastPage }} — {{ invoices.total }}
    </p>
  </ClientLayout>
</template>
