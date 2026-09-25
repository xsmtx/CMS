<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import FilterBar from '../../../Components/FilterBar.vue'
import FilterSelect from '../../../Components/FilterSelect.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
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
  invoices: {
    data: InvoiceRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: { status: string | null }
  statuses: { value: string; label: string }[]
  outstanding: { currency: string; amount: string }[]
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'number', label: t('billing.invoices.number'), sticky: true },
  { key: 'status', label: t('billing.invoices.status') },
  { key: 'due', label: t('billing.invoices.due') },
  { key: 'total', label: t('billing.invoices.total'), numeric: true },
  { key: 'balance', label: t('billing.invoices.balance'), numeric: true },
  { key: 'actions', label: '' },
]

/**
 * What is owed, per currency and never summed across them: a single figure
 * would need a rate nobody in this conversation agreed on.
 */
const outstanding = computed<Metric[]>(() =>
  props.outstanding.map((row) => ({
    key: row.currency,
    label: `${t('billing.portal.outstanding')} · ${row.currency}`,
    value: row.amount,
  })),
)

/** The empty value is "all", which is a real choice in the same list. */
const statusOptions = computed(() => [
  { value: '', label: t('billing.invoices.all') },
  ...props.statuses,
])

const statusFilter = computed({
  get: () => props.filters.status ?? '',
  set: (value: string) => filterBy(value === '' ? null : value),
})

function filterBy(status: string | null): void {
  router.get('/client/billing', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
  })
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('billing.portal.invoices_title')" />

  <ClientLayout
    :heading="t('billing.portal.invoices_title')"
    :description="t('billing.portal.invoices_description')"
  >
    <BillingTabs current="invoices" />

    <MetricStrip v-if="outstanding.length > 0" class="mb-6" :items="outstanding" />
    <p v-else class="text-content-muted text-body mb-6">{{ t('billing.portal.nothing_owed') }}</p>

    <div class="mb-4">
      <FilterBar>
        <FilterSelect
          v-model="statusFilter"
          :label="t('billing.invoices.status')"
          :options="statusOptions"
        />
      </FilterBar>
    </div>

    <AppTable v-if="invoices.data.length > 0" name="portal-invoices" :columns="COLUMNS">
      <AppTableRow v-for="invoice in invoices.data" :key="invoice.id">
        <td data-col="number">
          <Link
            :href="`/client/billing/invoices/${invoice.number}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ invoice.number }}
          </Link>
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
        </td>
        <td data-col="due" class="text-content-muted whitespace-nowrap">
          {{ formatDate(invoice.dueOn) }}
        </td>
        <td data-col="total" class="numeric tabular-nums">{{ invoice.total }}</td>
        <td data-col="balance" class="numeric font-medium tabular-nums">{{ invoice.balance }}</td>
        <td data-col="actions" class="text-right">
          <Link
            :href="`/client/billing/invoices/${invoice.number}`"
            class="text-content-muted hover:text-content text-chrome underline-offset-4 hover:underline"
          >
            {{ invoice.isOwed ? t('billing.payments.pay_now') : t('billing.portal.view') }}
          </Link>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="billing"
      :title="t('billing.portal.no_invoices')"
      :description="t('billing.portal.invoices_description')"
    />

    <AppPagination :links="invoices.links" :total="invoices.total" />
  </ClientLayout>
</template>
