<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import BillingTabs from './BillingTabs.vue'

interface TransactionRow {
  id: string
  kind: string
  kindLabel: string
  increasesPaid: boolean
  amount: string
  creditBalance: string
  invoiceNumber: string | null
  occurredAt: string
}

const props = defineProps<{
  transactions: {
    data: TransactionRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  credit: { balance: string; currency: string }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'when', label: t('billing.payments.received_on'), sticky: true },
  { key: 'kind', label: t('billing.transactions.kind') },
  { key: 'against', label: t('billing.portal.against') },
  { key: 'amount', label: t('billing.payments.amount'), numeric: true },
  { key: 'balance', label: t('billing.portal.running_balance'), numeric: true },
]

const credit = computed<Metric[]>(() => [
  {
    key: 'credit',
    label: `${t('billing.portal.credit_balance')} · ${props.credit.currency}`,
    value: props.credit.balance,
    hint: t('billing.portal.credit_explained'),
  },
])

function formatDate(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('billing.portal.transactions_title')" />

  <ClientLayout
    :heading="t('billing.portal.transactions_title')"
    :description="t('billing.portal.transactions_description')"
  >
    <BillingTabs current="transactions" />

    <MetricStrip class="mb-6" :items="credit" />

    <AppTable v-if="transactions.data.length > 0" name="portal-transactions" :columns="COLUMNS">
      <AppTableRow v-for="transaction in transactions.data" :key="transaction.id">
        <td data-col="when" class="text-content-muted whitespace-nowrap">
          {{ formatDate(transaction.occurredAt) }}
        </td>
        <td data-col="kind">{{ transaction.kindLabel }}</td>
        <td data-col="against">
          <Link
            v-if="transaction.invoiceNumber"
            :href="`/client/billing/invoices/${transaction.invoiceNumber}`"
            class="underline-offset-4 hover:underline"
          >
            {{ transaction.invoiceNumber }}
          </Link>
          <span v-else class="text-content-muted">—</span>
        </td>
        <!--
          The sign comes from the kind, never from the stored amount: every
          ledger row is positive, and the direction is the kind's to say.
        -->
        <td
          data-col="amount"
          class="numeric tabular-nums"
          :class="transaction.increasesPaid ? '' : 'text-content-muted'"
        >
          {{ transaction.increasesPaid ? '' : '−' }}{{ transaction.amount }}
        </td>
        <td data-col="balance" class="numeric text-content-muted tabular-nums">
          {{ transaction.creditBalance }}
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="billing"
      :title="t('billing.portal.no_transactions')"
      :description="t('billing.portal.credit_explained')"
    />

    <AppPagination :links="transactions.links" :total="transactions.total" />
  </ClientLayout>
</template>
