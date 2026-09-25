<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

interface OrderRow {
  id: string
  number: string
  status: string
  statusLabel: string
  total: string
  placedAt: string | null
}

defineProps<{
  orders: {
    data: OrderRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'number', label: t('ordering.orders.number'), sticky: true },
  { key: 'placed', label: t('ordering.orders.placed') },
  { key: 'status', label: t('ordering.orders.status') },
  { key: 'total', label: t('ordering.orders.total'), numeric: true },
  { key: 'actions', label: '' },
]

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('ordering.portal.title')" />

  <ClientLayout
    :heading="t('ordering.portal.title')"
    :description="t('ordering.portal.description')"
  >
    <AppTable v-if="orders.data.length > 0" name="portal-orders-list" :columns="COLUMNS">
      <AppTableRow v-for="order in orders.data" :key="order.id">
        <td data-col="number">
          <Link
            :href="`/client/orders/${order.number}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ order.number }}
          </Link>
        </td>
        <td data-col="placed" class="text-content-muted whitespace-nowrap">
          {{ formatDate(order.placedAt) }}
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(order.status)" :label="order.statusLabel" />
        </td>
        <td data-col="total" class="numeric tabular-nums">{{ order.total }}</td>
        <td data-col="actions" class="text-right">
          <Link
            :href="`/client/orders/${order.number}`"
            class="text-content-muted hover:text-content text-chrome underline-offset-4 hover:underline"
          >
            {{ t('ordering.portal.view') }}
          </Link>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="orders"
      :title="t('ordering.portal.none')"
      :description="t('ordering.portal.description')"
    />

    <AppPagination :links="orders.links" :total="orders.total" />
  </ClientLayout>
</template>
