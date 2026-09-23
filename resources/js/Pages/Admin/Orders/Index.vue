<script setup lang="ts">
/**
 * Orders, with the panel an operator searches from.
 *
 * Payment status is shown beside the order status and they are different
 * questions: an order can be active and unpaid, or cancelled and refunded.
 * It is derived from the invoices the order raised rather than stored, so
 * there is only ever one answer to "did they pay".
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface OrderRow {
  id: string
  number: string
  customer: string | null
  customerId: string | null
  status: string
  statusLabel: string
  currency: string
  total: string
  placedAt: string | null
  riskDecision: string | null
  ipAddress: string | null
  paymentStatus: string
  paymentMethod: string | null
}

interface Criteria {
  status: string
  number: string
  client: string
  payment: string
  from: string
  to: string
  amount: string
  ip: string
}

const props = defineProps<{
  orders: { data: OrderRow[]; currentPage: number; lastPage: number; total: number }
  filters: Partial<Criteria>
  statuses: { value: string; label: string }[]
  gateways: { value: string; label: string }[]
  reviewCount: number
}>()

const EMPTY: Criteria = {
  status: '',
  number: '',
  client: '',
  payment: '',
  from: '',
  to: '',
  amount: '',
  ip: '',
}

const form = ref<Criteria>({ ...EMPTY, ...props.filters })
const open = ref(
  Object.entries(props.filters).some(([key, value]) => key !== 'status' && Boolean(value)),
)

const active = computed(() => form.value.status)

const hasFilters = computed(() =>
  (Object.keys(EMPTY) as (keyof Criteria)[]).some((key) => form.value[key] !== ''),
)

function query(): Record<string, string> {
  const params: Record<string, string> = {}

  for (const key of Object.keys(EMPTY) as (keyof Criteria)[]) {
    if (form.value[key] !== '') params[key] = form.value[key]
  }

  return params
}

function apply(): void {
  router.get('/admin/orders', query(), { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
  apply()
}

function filterBy(status: string): void {
  form.value.status = status
  apply()
}

function paymentTone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'paid') return 'success'
  if (status === 'overdue') return 'danger'
  if (status === 'unpaid') return 'warning'
  return 'neutral'
}

const PAYMENT_LABELS: Record<string, string> = {
  unbilled: 'Not invoiced',
  unpaid: 'Unpaid',
  overdue: 'Overdue',
  paid: 'Paid',
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(options: { value: string; label: string }[]) {
  return [{ value: '', label: 'Any' }, ...options]
}
</script>

<template>
  <Head title="Orders" />

  <AdminLayout
    heading="Orders"
    description="What customers have agreed to buy. Every line records the price as it was at the moment of ordering."
  >
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-1.5">
        <button
          type="button"
          class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
          :class="
            active === ''
              ? 'bg-surface-sunken text-content'
              : 'text-content-muted hover:text-content'
          "
          @click="filterBy('')"
        >
          All
        </button>
        <button
          v-for="status in statuses"
          :key="status.value"
          type="button"
          class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
          :class="
            active === status.value
              ? 'bg-surface-sunken text-content'
              : 'text-content-muted hover:text-content'
          "
          @click="filterBy(status.value)"
        >
          {{ status.label }}
        </button>
      </div>

      <div class="flex flex-wrap items-center gap-2.5">
        <AppButton size="sm" :aria-expanded="open" @click="open = !open">
          {{ open ? 'Hide search' : 'Search / filter' }}
        </AppButton>
        <AppButton v-if="reviewCount > 0" href="/admin/orders/review" size="sm">
          Review queue ({{ reviewCount }})
        </AppButton>
      </div>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-raised grid gap-4 rounded-[var(--radius-lg)] border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput v-model="form.number" label="Order # or ID" />
        <AppInput v-model="form.client" label="Client" />
        <AppSelect
          v-model="form.payment"
          label="Payment method"
          :options="withBlank(gateways)"
          hint="What actually took the money."
        />
        <AppSelect v-model="form.status" label="Status" :options="withBlank(statuses)" />
        <AppInput v-model="form.from" type="date" label="Placed from" />
        <AppInput v-model="form.to" type="date" label="Placed to" />
        <AppInput v-model="form.amount" label="Amount" hint="Exact total, as typed on the order." />
        <AppInput v-model="form.ip" label="IP address" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">Search</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">Clear</AppButton>
        <span v-if="hasFilters" class="text-content-muted self-center text-xs">
          {{ orders.total }} match
        </span>
      </div>
    </form>

    <AppTable
      v-if="orders.data.length > 0"
      :headers="[
        'ID',
        'Order #',
        'Date',
        'Client name',
        'Payment method',
        'Total',
        'Payment status',
        'Status',
        '',
      ]"
    >
      <tr v-for="order in orders.data" :key="order.id">
        <td class="text-content-subtle px-4 py-2.5 font-mono text-xs">{{ order.id.slice(-8) }}</td>
        <td class="px-4 py-2.5 font-mono text-xs">{{ order.number }}</td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(order.placedAt) }}
        </td>
        <td class="px-4 py-2.5">
          <Link
            v-if="order.customerId"
            :href="`/admin/customers/${order.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ order.customer ?? '—' }}
          </Link>
          <span v-else>—</span>
          <span v-if="order.ipAddress" class="text-content-subtle block font-mono text-xs">
            {{ order.ipAddress }}
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5">{{ order.paymentMethod ?? '—' }}</td>
        <td class="px-4 py-2.5 tabular-nums">{{ order.total }}</td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="paymentTone(order.paymentStatus)">
            {{ PAYMENT_LABELS[order.paymentStatus] ?? order.paymentStatus }}
          </AppBadge>
        </td>
        <td class="px-4 py-2.5">
          <AppBadge>{{ order.statusLabel }}</AppBadge>
          <span v-if="order.riskDecision === 'review'" class="text-warning ml-2 text-xs">Held</span>
        </td>
        <td class="px-4 py-2.5 text-right">
          <Link
            :href="`/admin/orders/${order.id}`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Open
          </Link>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else-if="hasFilters"
      title="No order matches"
      description="Every criterion here runs against a real column: an order number, a client, a gateway that took money, an address."
    />

    <EmptyState
      v-else
      title="No orders yet"
      description="Orders appear here as soon as a customer checks out. Each one keeps its own copy of what it cost."
    />

    <p v-if="orders.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ orders.currentPage }} of {{ orders.lastPage }} — {{ orders.total }} orders
    </p>
  </AdminLayout>
</template>
