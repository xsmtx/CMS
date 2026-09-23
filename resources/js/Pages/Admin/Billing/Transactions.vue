<script setup lang="ts">
/**
 * The ledger, newest first.
 *
 * Read-only and it will stay that way: a transaction is append-only, so
 * there is nothing here to edit. A correction is another transaction, made
 * by whatever caused it.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface TransactionRow {
  id: string
  kind: string
  kindLabel: string
  increasesBalance: boolean
  amount: string
  creditBalance: string
  customer: string | null
  customerId: string | null
  invoice: string | null
  invoiceId: string | null
  description: string | null
  recordedBy: string | null
  occurredAt: string
}

const props = defineProps<{
  transactions: { data: TransactionRow[]; currentPage: number; lastPage: number; total: number }
  filters: { kind: string | null }
  kinds: { value: string; label: string }[]
}>()

const kind = ref(props.filters.kind ?? '')

function apply(): void {
  router.get('/admin/transactions', kind.value === '' ? {} : { kind: kind.value }, {
    preserveState: true,
    replace: true,
  })
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Transactions" />

  <AdminLayout
    heading="Transactions"
    description="Every movement of money. The ledger is the truth; an invoice's paid amount is a total of these rows."
  >
    <form class="mb-6 max-w-xs" @submit.prevent="apply">
      <AppSelect
        v-model="kind"
        label="Kind"
        :options="[{ value: '', label: 'All' }, ...kinds]"
        @update:model-value="apply"
      />
    </form>

    <AppTable
      v-if="transactions.data.length > 0"
      :headers="['Date', 'Kind', 'Client', 'Invoice', 'Amount', 'Credit balance', 'Recorded by']"
    >
      <tr v-for="row in transactions.data" :key="row.id">
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap">
          {{ formatDateTime(row.occurredAt) }}
        </td>
        <td class="px-5 py-3.5">
          <AppBadge :tone="row.increasesBalance ? 'success' : 'neutral'">
            {{ row.kindLabel }}
          </AppBadge>
          <span v-if="row.description" class="text-content-muted block text-xs">
            {{ row.description }}
          </span>
        </td>
        <td class="px-5 py-3.5">
          <Link
            v-if="row.customerId"
            :href="`/admin/customers/${row.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ row.customer ?? '—' }}
          </Link>
          <span v-else>—</span>
        </td>
        <td class="px-5 py-3.5">
          <Link
            v-if="row.invoiceId"
            :href="`/admin/invoices/${row.invoiceId}`"
            class="font-mono text-xs underline-offset-4 hover:underline"
          >
            {{ row.invoice }}
          </Link>
          <span v-else class="text-content-muted">—</span>
        </td>
        <td class="px-5 py-3.5 tabular-nums">{{ row.amount }}</td>
        <td class="text-content-muted px-5 py-3.5 tabular-nums">{{ row.creditBalance }}</td>
        <td class="text-content-muted px-5 py-3.5">{{ row.recordedBy ?? '—' }}</td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No transactions"
      description="A row appears here the moment money moves — a payment, a refund, a credit."
    />

    <p v-if="transactions.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ transactions.currentPage }} of {{ transactions.lastPage }} —
      {{ transactions.total }} transactions
    </p>
  </AdminLayout>
</template>
