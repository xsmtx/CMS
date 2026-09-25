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

import AppPagination from '../../../Components/AppPagination.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppBarChart from '../../../Components/AppBarChart.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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
  transactions: {
    data: TransactionRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'client', label: t('billing.transactions.client'), sticky: true },
  { key: 'date', label: t('billing.transactions.date') },
  { key: 'method', label: t('billing.transactions.payment_method') },
  { key: 'description', label: t('billing.transactions.description') },
  { key: 'in', label: t('billing.transactions.amount_in'), numeric: true },
  { key: 'fees', label: t('billing.transactions.fees'), numeric: true, optional: true },
  { key: 'out', label: t('billing.transactions.amount_out'), numeric: true },
]

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

function withBlank(options: Option[], label = t('billing.transactions.all')): Option[] {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head :title="t('billing.transactions.title')" />

  <AdminLayout
    :heading="t('billing.transactions.title')"
    :description="t('billing.transactions.subtitle')"
  >
    <AppCard
      class="mb-6"
      :title="t('billing.transactions.flow_title')"
      :description="t('billing.transactions.flow_net', { amount: flow.net })"
    >
      <AppBarChart
        :title="t('billing.transactions.flow_chart')"
        :rows="flow.in"
        :compare="flow.out"
        :series-label="t('billing.transactions.in')"
        :compare-label="t('billing.transactions.out')"
        :format="formatMinor"
      />

      <div class="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          class="pressable border-line hover:border-line-strong text-chrome rounded-sm border px-3 py-1.5 transition-colors duration-(--duration-fast)"
          :class="form.direction === 'in' ? 'border-brand text-content font-medium' : ''"
          :aria-pressed="form.direction === 'in'"
          @click="only('in')"
        >
          {{ t('billing.transactions.in') }} {{ flow.totalIn }}
        </button>
        <button
          type="button"
          class="pressable border-line hover:border-line-strong text-chrome rounded-sm border px-3 py-1.5 transition-colors duration-(--duration-fast)"
          :class="form.direction === 'out' ? 'border-brand text-content font-medium' : ''"
          :aria-pressed="form.direction === 'out'"
          @click="only('out')"
        >
          {{ t('billing.transactions.out') }} {{ flow.totalOut }}
        </button>
      </div>
    </AppCard>

    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <Link v-if="canAdd" href="/admin/transactions/add">
        <AppButton variant="primary" size="sm">{{ t('billing.transactions.add') }}</AppButton>
      </Link>
      <AppButton size="sm" :aria-expanded="open" @click="open = !open">
        {{ open ? t('billing.transactions.hide_search') : t('billing.transactions.search_filter') }}
      </AppButton>
      <span v-if="hasFilters" class="text-content-muted text-chrome">
        {{ t('billing.transactions.matches', { count: transactions.total }) }}
      </span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput v-model="form.client" :label="t('billing.transactions.client')" />
        <AppSelect
          v-model="form.kind"
          :label="t('billing.transactions.kind')"
          :options="withBlank(kinds)"
        />
        <AppSelect
          v-model="form.gateway"
          :label="t('billing.transactions.payment_method')"
          :options="withBlank(gateways, t('billing.transactions.any'))"
        />
        <AppInput v-model="form.invoice" :label="t('billing.transactions.invoice')" />
        <AppInput v-model="form.reference" :label="t('billing.transactions.reference')" />
        <AppInput v-model="form.description" :label="t('billing.transactions.description')" />
        <AppInput v-model="form.from" :label="t('billing.transactions.from')" type="date" />
        <AppInput v-model="form.to" :label="t('billing.transactions.to')" type="date" />
        <AppInput v-model="form.amount" :label="t('billing.transactions.amount')" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">{{
          t('billing.transactions.search')
        }}</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">{{
          t('billing.transactions.clear')
        }}</AppButton>
      </div>
    </form>

    <AppTable v-if="transactions.data.length > 0" name="admin-transactions" :columns="COLUMNS">
      <AppTableRow v-for="row in transactions.data" :key="row.id">
        <td data-col="client">
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
            class="text-content-muted text-chrome block font-mono underline-offset-4 hover:underline"
          >
            {{ row.invoice }}
          </Link>
        </td>
        <td data-col="date" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(row.occurredAt) }}
        </td>
        <td data-col="method">
          <span v-if="row.gatewayLabel">{{ row.gatewayLabel }}</span>
          <span v-else class="text-content-muted">—</span>
          <span v-if="row.reference" class="text-content-subtle text-label block font-mono">
            {{ row.reference }}
          </span>
        </td>
        <td data-col="description">
          <AppBadge :tone="row.increasesBalance ? 'success' : 'neutral'">
            {{ row.kindLabel }}
          </AppBadge>
          <span v-if="row.description" class="text-content-muted text-chrome mt-1 block">
            {{ row.description }}
          </span>
        </td>
        <td data-col="in" class="text-right tabular-nums">
          <span v-if="row.increasesBalance">{{ row.amount }}</span>
          <span v-else class="text-content-subtle">—</span>
        </td>
        <td data-col="fees" class="text-content-muted text-right tabular-nums">
          {{ row.fees ?? '—' }}
        </td>
        <td data-col="out" class="text-right tabular-nums">
          <span v-if="!row.increasesBalance">{{ row.amount }}</span>
          <span v-else class="text-content-subtle">—</span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="billing"
      :title="t('billing.transactions.empty')"
      :description="t('billing.transactions.empty_description')"
    />

    <AppPagination :links="transactions.links" :total="transactions.total" />
  </AdminLayout>
</template>
