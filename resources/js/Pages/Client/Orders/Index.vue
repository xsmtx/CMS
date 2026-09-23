<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface OrderRow {
  id: string
  number: string
  status: string
  statusLabel: string
  total: string
  placedAt: string | null
}

defineProps<{
  orders: { data: OrderRow[]; currentPage: number; lastPage: number; total: number }
}>()

const { t } = useTranslations()

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active' || status === 'paid') return 'success'
  if (status === 'cancelled' || status === 'failed') return 'danger'
  if (status === 'awaiting_payment') return 'warning'
  return 'neutral'
}

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
    <AppTable
      v-if="orders.data.length > 0"
      :headers="[
        t('ordering.orders.number'),
        t('ordering.orders.placed'),
        t('ordering.orders.status'),
        t('ordering.orders.total'),
        '',
      ]"
    >
      <tr v-for="order in orders.data" :key="order.id">
        <td class="px-4 py-2.5 font-medium">
          <Link :href="`/client/orders/${order.number}`" class="underline-offset-4 hover:underline">
            {{ order.number }}
          </Link>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(order.placedAt) }}
        </td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="tone(order.status)">{{ order.statusLabel }}</AppBadge>
        </td>
        <td class="px-4 py-2.5 tabular-nums">{{ order.total }}</td>
        <td class="px-4 py-2.5 text-right">
          <Link
            :href="`/client/orders/${order.number}`"
            class="text-content-muted hover:text-content text-xs underline-offset-4 hover:underline"
          >
            {{ t('ordering.portal.view') }}
          </Link>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      :title="t('ordering.portal.none')"
      :description="t('ordering.portal.description')"
    />

    <p v-if="orders.lastPage > 1" class="text-content-muted mt-4 text-xs">
      {{ orders.currentPage }} / {{ orders.lastPage }} — {{ orders.total }}
    </p>
  </ClientLayout>
</template>
