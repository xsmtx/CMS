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

import AppPagination from '../../../Components/AppPagination.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

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
  orders: {
    data: OrderRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: Partial<Criteria>
  statuses: { value: string; label: string }[]
  gateways: { value: string; label: string }[]
  reviewCount: number
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'id', label: t('ordering.orders.id'), optional: true, offByDefault: true },
  { key: 'number', label: t('ordering.orders.number'), sticky: true },
  { key: 'date', label: t('ordering.orders.placed') },
  { key: 'client', label: t('ordering.orders.customer') },
  { key: 'method', label: t('ordering.orders.payment_method'), optional: true },
  { key: 'total', label: t('ordering.orders.total'), numeric: true },
  { key: 'payment', label: t('ordering.orders.payment_status') },
  { key: 'status', label: t('ordering.orders.status') },
  { key: 'actions', label: '' },
]

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

/*
 * The payment statuses, named by the same file the rest of the product names
 * them in. A page that maps a status to a word is a second vocabulary, and
 * the one nobody remembers to translate.
 */
const PAYMENT_LABELS: Record<string, string> = {
  unbilled: t('ordering.orders.unbilled'),
  unpaid: t('billing.statuses.unpaid'),
  overdue: t('billing.statuses.overdue'),
  paid: t('billing.statuses.paid'),
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(options: { value: string; label: string }[]) {
  return [{ value: '', label: t('ordering.orders.any') }, ...options]
}
</script>

<template>
  <Head :title="t('ordering.orders.title')" />

  <AdminLayout :heading="t('ordering.orders.title')" :description="t('ordering.orders.subtitle')">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-1.5">
        <button
          type="button"
          class="pressable text-chrome rounded-sm px-2.5 py-1 font-medium transition-colors duration-(--duration-fast)"
          :class="
            active === ''
              ? 'bg-surface-secondary text-content'
              : 'text-content-muted hover:text-content'
          "
          @click="filterBy('')"
        >
          {{ t('ordering.orders.all') }}
        </button>
        <button
          v-for="status in statuses"
          :key="status.value"
          type="button"
          class="pressable text-chrome rounded-sm px-2.5 py-1 font-medium transition-colors duration-(--duration-fast)"
          :class="
            active === status.value
              ? 'bg-surface-secondary text-content'
              : 'text-content-muted hover:text-content'
          "
          @click="filterBy(status.value)"
        >
          {{ status.label }}
        </button>
      </div>

      <div class="flex flex-wrap items-center gap-2.5">
        <AppButton size="sm" :aria-expanded="open" @click="open = !open">
          {{ open ? t('ordering.orders.hide_search') : t('ordering.orders.search_filter') }}
        </AppButton>
        <AppButton v-if="reviewCount > 0" href="/admin/orders/review" size="sm">
          {{ t('ordering.orders.review_queue_count', { count: reviewCount }) }}
        </AppButton>
      </div>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput v-model="form.number" :label="t('ordering.orders.number_search')" />
        <AppInput v-model="form.client" :label="t('ordering.orders.customer')" />
        <AppSelect
          v-model="form.payment"
          :label="t('ordering.orders.payment_method')"
          :options="withBlank(gateways)"
          :hint="t('ordering.orders.payment_method_hint')"
        />
        <AppSelect
          v-model="form.status"
          :label="t('ordering.orders.status')"
          :options="withBlank(statuses)"
        />
        <AppInput v-model="form.from" type="date" :label="t('ordering.orders.placed_from')" />
        <AppInput v-model="form.to" type="date" :label="t('ordering.orders.placed_to')" />
        <AppInput
          v-model="form.amount"
          :label="t('ordering.orders.amount')"
          :hint="t('ordering.orders.amount_hint')"
        />
        <AppInput v-model="form.ip" :label="t('ordering.orders.ip')" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">{{ t('ordering.orders.search') }}</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">{{
          t('ordering.orders.clear')
        }}</AppButton>
        <span v-if="hasFilters" class="text-content-muted text-chrome self-center">
          {{ t('ordering.orders.matches', { count: orders.total }) }}
        </span>
      </div>
    </form>

    <AppTable v-if="orders.data.length > 0" name="admin-orders" :columns="COLUMNS">
      <AppTableRow v-for="order in orders.data" :key="order.id">
        <td data-col="id" class="text-content-subtle text-chrome font-mono">
          {{ order.id.slice(-8) }}
        </td>
        <td data-col="number" class="text-chrome font-mono">{{ order.number }}</td>
        <td data-col="date" class="text-content-muted whitespace-nowrap">
          {{ formatDate(order.placedAt) }}
        </td>
        <td data-col="client">
          <Link
            v-if="order.customerId"
            :href="`/admin/customers/${order.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ order.customer ?? '—' }}
          </Link>
          <span v-else>—</span>
          <span v-if="order.ipAddress" class="text-content-subtle text-chrome block font-mono">
            {{ order.ipAddress }}
          </span>
        </td>
        <td data-col="method" class="text-content-muted">{{ order.paymentMethod ?? '—' }}</td>
        <td data-col="total" class="numeric tabular-nums">{{ order.total }}</td>
        <td data-col="payment">
          <AppStatus
            :tone="statusTone(order.paymentStatus)"
            :label="PAYMENT_LABELS[order.paymentStatus] ?? order.paymentStatus"
          />
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(order.status)" :label="order.statusLabel" />
          <span v-if="order.riskDecision === 'review'" class="text-warning text-chrome ml-2">
            {{ t('ordering.orders.held') }}
          </span>
        </td>
        <td data-col="actions" class="text-right">
          <span class="row-actions">
            <Link
              :href="`/admin/orders/${order.id}`"
              class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
            >
              {{ t('ordering.orders.open') }}
            </Link>
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else-if="hasFilters"
      icon="orders"
      :title="t('ordering.orders.no_match')"
      :description="t('ordering.orders.no_match_description')"
    />

    <EmptyState
      v-else
      icon="orders"
      :title="t('ordering.orders.empty')"
      :description="t('ordering.orders.empty_description')"
    />

    <AppPagination :links="orders.links" :total="orders.total" />
  </AdminLayout>
</template>
