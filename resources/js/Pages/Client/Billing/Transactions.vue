<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
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

defineProps<{
  transactions: {
    data: TransactionRow[]
    currentPage: number
    lastPage: number
    total: number
  }
  credit: { balance: string; currency: string }
}>()

const { t } = useTranslations()

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

    <div class="mb-6">
      <p class="text-content-muted text-xs">
        {{ t('billing.portal.credit_balance') }} · {{ credit.currency }}
      </p>
      <p class="mt-0.5 text-xl font-semibold tracking-tight tabular-nums">{{ credit.balance }}</p>
      <p class="text-content-muted mt-1 max-w-[60ch] text-sm leading-relaxed">
        {{ t('billing.portal.credit_explained') }}
      </p>
    </div>

    <AppTable
      v-if="transactions.data.length > 0"
      :headers="[
        t('billing.payments.received_on'),
        t('billing.transactions.kind'),
        t('billing.portal.against'),
        t('billing.payments.amount'),
        t('billing.portal.running_balance'),
      ]"
    >
      <tr v-for="transaction in transactions.data" :key="transaction.id">
        <td class="text-content-muted px-4 py-3 whitespace-nowrap">
          {{ formatDate(transaction.occurredAt) }}
        </td>
        <td class="px-4 py-3">{{ transaction.kindLabel }}</td>
        <td class="px-4 py-3">
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
          class="px-4 py-3 tabular-nums"
          :class="transaction.increasesPaid ? '' : 'text-content-muted'"
        >
          {{ transaction.increasesPaid ? '' : '−' }}{{ transaction.amount }}
        </td>
        <td class="text-content-muted px-4 py-3 tabular-nums">
          {{ transaction.creditBalance }}
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      :title="t('billing.portal.no_transactions')"
      :description="t('billing.portal.credit_explained')"
    />

    <p v-if="transactions.lastPage > 1" class="text-content-muted mt-4 text-xs">
      {{ transactions.currentPage }} / {{ transactions.lastPage }} — {{ transactions.total }}
    </p>
  </ClientLayout>
</template>
