<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface OrderRow {
  id: string
  number: string
  customer: string | null
  status: string
  statusLabel: string
  currency: string
  total: string
  placedAt: string | null
  riskDecision: string | null
}

const props = defineProps<{
  orders: { data: OrderRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null }
  statuses: { value: string; label: string }[]
  reviewCount: number
}>()

const active = computed(() => props.filters.status)

function filterBy(status: string | null): void {
  router.get('/admin/orders', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
  })
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
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
            active === null
              ? 'bg-surface-sunken text-content'
              : 'text-content-muted hover:text-content'
          "
          @click="filterBy(null)"
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

      <AppButton v-if="reviewCount > 0" href="/admin/orders/review" variant="secondary" size="sm">
        Review queue ({{ reviewCount }})
      </AppButton>
    </div>

    <AppTable
      v-if="orders.data.length > 0"
      :headers="['Order', 'Customer', 'Status', 'Total', 'Placed', '']"
    >
      <tr v-for="order in orders.data" :key="order.id">
        <td class="px-5 py-3.5 font-mono text-xs">{{ order.number }}</td>
        <td class="px-5 py-3.5">{{ order.customer ?? '—' }}</td>
        <td class="px-5 py-3.5">
          <AppBadge>{{ order.statusLabel }}</AppBadge>
          <span v-if="order.riskDecision === 'review'" class="text-warning ml-2 text-xs">
            Held
          </span>
        </td>
        <td class="px-5 py-3.5 tabular-nums">{{ order.total }}</td>
        <td class="text-content-muted px-5 py-3.5 text-xs">{{ formatDate(order.placedAt) }}</td>
        <td class="px-5 py-3.5 text-right">
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
      v-else
      title="No orders yet"
      description="Orders appear here as soon as a customer checks out. Each one keeps its own copy of what it cost."
    />

    <p v-if="orders.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ orders.currentPage }} of {{ orders.lastPage }} — {{ orders.total }} orders
    </p>
  </AdminLayout>
</template>
