<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AppStatus from '../../../Components/AppStatus.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface ReviewRow {
  id: string
  number: string
  customer: string | null
  status: string
  statusLabel: string
  total: string
  placedAt: string | null
  riskDecision: string | null
  riskReasons: string[]
}

defineProps<{ orders: ReviewRow[]; canReview: boolean }>()

const { t } = useTranslations()

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('ordering.orders.review_queue')" />

  <AdminLayout
    :heading="t('ordering.orders.review_queue')"
    :description="t('ordering.orders.review_intro')"
  >
    <!-- One framed surface with hairline-divided rows rather than a card each: the
         queue is a list of orders, not a page of separate objects. -->
    <div
      v-if="orders.length > 0"
      class="border-line bg-surface-primary divide-line divide-y rounded-lg border"
    >
      <div v-for="order in orders" :key="order.id" class="p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-body font-medium">
              <Link :href="`/admin/orders/${order.id}`" class="underline underline-offset-4">
                {{ order.number }}
              </Link>
              <AppStatus class="ml-2" :tone="statusTone(order.status)" :label="order.statusLabel" />
            </p>
            <p class="text-content-muted text-chrome mt-0.5">
              {{ order.customer ?? '—' }} · {{ formatDateTime(order.placedAt) }}
            </p>

            <ul v-if="order.riskReasons.length > 0" class="mt-3 space-y-1">
              <li v-for="(reason, index) in order.riskReasons" :key="index" class="text-body">
                {{ reason }}
              </li>
            </ul>
          </div>

          <div class="text-right">
            <p class="text-title font-semibold tabular-nums">{{ order.total }}</p>
            <AppButton
              v-if="canReview"
              :href="`/admin/orders/${order.id}`"
              size="sm"
              variant="primary"
              class="mt-3"
            >
              {{ t('ordering.orders.decide') }}
            </AppButton>
          </div>
        </div>
      </div>
    </div>

    <EmptyState
      v-else
      icon="orders"
      :title="t('ordering.orders.review_empty')"
      :description="t('ordering.orders.review_empty_description')"
    />

    <div class="mt-6">
      <AppButton href="/admin/orders" variant="ghost">{{ t('ordering.orders.back') }}</AppButton>
    </div>
  </AdminLayout>
</template>
