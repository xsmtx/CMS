<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ReviewRow {
  id: string
  number: string
  customer: string | null
  statusLabel: string
  total: string
  placedAt: string | null
  riskDecision: string | null
  riskReasons: string[]
}

defineProps<{ orders: ReviewRow[]; canReview: boolean }>()

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Review queue" />

  <AdminLayout
    heading="Review queue"
    description="Orders the platform held rather than refused. Nothing here moves until someone decides."
  >
    <div v-if="orders.length > 0" class="flex flex-col gap-4">
      <AppCard v-for="order in orders" :key="order.id">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-sm font-medium">
              <Link :href="`/admin/orders/${order.id}`" class="underline underline-offset-4">
                {{ order.number }}
              </Link>
              <AppBadge class="ml-2">{{ order.statusLabel }}</AppBadge>
            </p>
            <p class="text-content-muted mt-0.5 text-xs">
              {{ order.customer ?? '—' }} · {{ formatDateTime(order.placedAt) }}
            </p>

            <ul v-if="order.riskReasons.length > 0" class="mt-3 space-y-1">
              <li v-for="(reason, index) in order.riskReasons" :key="index" class="text-sm">
                {{ reason }}
              </li>
            </ul>
          </div>

          <div class="text-right">
            <p class="text-base font-semibold tabular-nums">{{ order.total }}</p>
            <AppButton
              v-if="canReview"
              :href="`/admin/orders/${order.id}`"
              size="sm"
              variant="primary"
              class="mt-3"
            >
              Decide
            </AppButton>
          </div>
        </div>
      </AppCard>
    </div>

    <EmptyState
      v-else
      title="Nothing is waiting for review"
      description="Orders land here when the risk rules flag one. An empty queue means everything placed so far went straight through."
    />

    <div class="mt-6">
      <AppButton href="/admin/orders" variant="ghost">Back to orders</AppButton>
    </div>
  </AdminLayout>
</template>
