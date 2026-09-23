<script setup lang="ts">
/**
 * The ledger, newest first, with the shape of the month above it.
 *
 * Read-only and it will stay that way: a transaction is append-only, so
 * there is nothing here to edit. A correction is another transaction, and
 * **Add Transaction** writes one — it never rewrites one.
 *
 * The chart is drawn from the same criteria as the list, so narrowing the
 * search narrows the picture. A chart that ignored the filter would answer
 * a question nobody asked.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppBarChart from '../../../Components/AppBarChart.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
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
  fees: string | null
  creditBalance: string
  customer: string | null
  customerId: string | null
  invoice: string | null
  invoiceId: string | null
  description: string | null
  gateway: string | null
  gatewayLabel: string | null
  reference: string | null
  recordedBy: string | null
  occurredAt: string
}

interface Criteria {
  kind: string
  direction: string
  client: string
  reference: string
  invoice: string
  gateway: string
  description: string
  from: string
  to: string
  amount: string
}

type Option = { value: string; label: string }

const props = defineProps<{
  transactions: { data: TransactionRow[]; currentPage: number; lastPage: number; total: number }
  flow: {
    in: { label: string; value: number }[]
    out: { label: string; value: number }[]
    totalIn: string
    totalOut: string
    net: string
    currency: string
  }
  filters: Partial<Criteria>
  kinds: Option[]
  gateways: Option[]
  canAdd: boolean
}>()

const EMPTY: Criteria = {
  kind: '',
  direction: '',
  client: '',
  reference: '',
  invoice: '',
  gateway: '',
  description: '',
  from: '',
  to: '',
  amount: '',
}

const form = ref<Criteria>({ ...EMPTY, ...stripNulls(props.filters) })
const open = ref(keys().some((key) => form.value[key] !== ''))

function stripNulls(filters: Partial<Criteria>): Partial<Criteria> {
  return Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== null && value !== undefined),
  ) as Partial<Criteria>
}

function keys(): (keyof Criteria)[] {
  return Object.keys(EMPTY) as (keyof Criteria)[]
}

const hasFilters = computed(() => keys().some((key) => form.value[key] !== ''))

function apply(): void {
  const params: Record<string, string> = {}

  for (const key of keys()) {
    if (form.value[key] !== '') params[key] = form.value[key]
  }

  router.get('/admin/transactions', params, { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
  apply()
}

function only(direction: string): void {
  form.value = { ...form.value, direction: form.value.direction === direction ? '' : direction }
  apply()
}

/**
 * Minor units into money, in the reader's own locale.
 *
 * The chart is drawn from integers because that is what money is here;
 * the decimal point exists in this one function and nowhere else.
 */
const money = new Intl.NumberFormat(undefined, {
  style: 'currency',
  currency: props.flow.currency,
  maximumFractionDigits: 0,
})

function formatMinor(value: number): string {
  return money.format(value / 100)
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}

function withBlank(options: Option[], label = 'All'): Option[] {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head title="Transactions" />

  <AdminLayout
    heading="Transactions"
    description="Every movement of money. The ledger is the truth; an invoice's paid amount is a total of these rows."
  >
    <AppCard
      class="mb-6"
      title="Amount in / out"
      :description="`Net ${flow.net} over the period shown.`"
    >
      <AppBarChart
        title="Amount in and out, per day"
        :rows="flow.in"
        :compare="flow.out"
        series-label="In"
        compare-label="Out"
        :format="formatMinor"
      />

      <div class="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          class="pressable border-line hover:border-line-strong rounded-[var(--radius-sm)] border px-3 py-1.5 text-xs transition-colors duration-(--duration-fast)"
          :class="form.direction === 'in' ? 'border-accent text-content font-medium' : ''"
          :aria-pressed="form.direction === 'in'"
          @click="only('in')"
        >
          In {{ flow.totalIn }}
        </button>
        <button
          type="button"
          class="pressable border-line hover:border-line-strong rounded-[var(--radius-sm)] border px-3 py-1.5 text-xs transition-colors duration-(--duration-fast)"
          :class="form.direction === 'out' ? 'border-accent text-content font-medium' : ''"
          :aria-pressed="form.direction === 'out'"
          @click="only('out')"
        >
          Out {{ flow.totalOut }}
        </button>
      </div>
    </AppCard>

    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <Link v-if="canAdd" href="/admin/transactions/add">
        <AppButton variant="primary" size="sm">Add Transaction</AppButton>
      </Link>
      <AppButton size="sm" :aria-expanded="open" @click="open = !open">
        {{ open ? 'Hide search' : 'Search / filter' }}
      </AppButton>
      <span v-if="hasFilters" class="text-content-muted text-xs">
        {{ transactions.total }} match
      </span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-raised grid gap-4 rounded-[var(--radius-lg)] border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput v-model="form.client" label="Client" />
        <AppSelect v-model="form.kind" label="Kind" :options="withBlank(kinds)" />
        <AppSelect
          v-model="form.gateway"
          label="Payment method"
          :options="withBlank(gateways, 'Any')"
        />
        <AppInput v-model="form.invoice" label="Invoice" />
        <AppInput v-model="form.reference" label="Transaction ID" />
        <AppInput v-model="form.description" label="Description" />
        <AppInput v-model="form.from" label="From" type="date" />
        <AppInput v-model="form.to" label="To" type="date" />
        <AppInput v-model="form.amount" label="Amount" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">Search</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">Clear</AppButton>
      </div>
    </form>

    <AppTable
      v-if="transactions.data.length > 0"
      :headers="[
        'Client name',
        'Date',
        'Payment method',
        'Description',
        'Amount in',
        'Fees',
        'Amount out',
      ]"
    >
      <tr v-for="row in transactions.data" :key="row.id">
        <td class="px-4 py-2.5">
          <Link
            v-if="row.customerId"
            :href="`/admin/customers/${row.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ row.customer ?? '—' }}
          </Link>
          <span v-else>—</span>
          <Link
            v-if="row.invoiceId"
            :href="`/admin/invoices/${row.invoiceId}`"
            class="text-content-muted block font-mono text-xs underline-offset-4 hover:underline"
          >
            {{ row.invoice }}
          </Link>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(row.occurredAt) }}
        </td>
        <td class="px-4 py-2.5">
          <span v-if="row.gatewayLabel">{{ row.gatewayLabel }}</span>
          <span v-else class="text-content-muted">—</span>
          <span v-if="row.reference" class="text-content-subtle text-label block font-mono">
            {{ row.reference }}
          </span>
        </td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="row.increasesBalance ? 'success' : 'neutral'">
            {{ row.kindLabel }}
          </AppBadge>
          <span v-if="row.description" class="text-content-muted mt-1 block text-xs">
            {{ row.description }}
          </span>
        </td>
        <td class="px-4 py-2.5 text-right tabular-nums">
          <span v-if="row.increasesBalance">{{ row.amount }}</span>
          <span v-else class="text-content-subtle">—</span>
        </td>
        <td class="text-content-muted px-4 py-2.5 text-right tabular-nums">
          {{ row.fees ?? '—' }}
        </td>
        <td class="px-4 py-2.5 text-right tabular-nums">
          <span v-if="!row.increasesBalance">{{ row.amount }}</span>
          <span v-else class="text-content-subtle">—</span>
        </td>
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
