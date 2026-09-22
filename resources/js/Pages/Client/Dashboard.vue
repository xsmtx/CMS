<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../Components/AppBadge.vue'
import AppCard from '../../Components/AppCard.vue'
import EmptyState from '../../Components/EmptyState.vue'
import ClientLayout from '../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../composables/useTranslations'

interface UnpaidInvoice {
  number: string
  balance: string
  dueOn: string | null
  isPastDue: boolean
}

interface RecentOrder {
  number: string
  status: string
  statusLabel: string
  total: string
  placedAt: string | null
}

defineProps<{
  name: string
  unpaid: UnpaidInvoice[]
  credit: { balance: string } | null
  orders: RecentOrder[]
  can: { billing: boolean; orders: boolean }
}>()

const { t } = useTranslations()

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('portal.dashboard.title')" />

  <ClientLayout
    :heading="t('portal.dashboard.greeting', { name })"
    :description="t('portal.dashboard.description')"
  >
    <div class="flex flex-col gap-5">
      <!--
        Owed first, and soonest first inside it. It is the reason a customer
        opens this page, and the reason a dunning run exists.
      -->
      <AppCard v-if="can.billing" :title="t('portal.dashboard.unpaid_title')">
        <template #actions>
          <Link
            href="/client/billing"
            class="text-content-muted hover:text-content text-sm underline-offset-4 hover:underline"
          >
            {{ t('portal.dashboard.see_all') }}
          </Link>
        </template>

        <EmptyState
          v-if="unpaid.length === 0"
          :title="t('portal.dashboard.unpaid_none')"
          :description="t('portal.dashboard.unpaid_none_description')"
        />

        <ul v-else class="divide-line divide-y">
          <li v-for="invoice in unpaid" :key="invoice.number">
            <Link
              :href="`/client/billing/invoices/${invoice.number}`"
              class="hover:bg-surface-sunken -mx-2 flex items-center justify-between gap-4 rounded-[var(--radius-sm)] px-2 py-3 transition-colors duration-(--duration-fast)"
            >
              <span class="min-w-0">
                <span class="block text-sm font-medium">{{ invoice.number }}</span>
                <span class="text-content-muted block text-xs">
                  {{ t('billing.portal.due', { date: invoice.dueOn ?? '—' }) }}
                </span>
              </span>
              <span class="flex shrink-0 items-center gap-2">
                <AppBadge v-if="invoice.isPastDue" tone="danger">
                  {{ t('billing.portal.past_due') }}
                </AppBadge>
                <span class="text-sm font-semibold tabular-nums">{{ invoice.balance }}</span>
              </span>
            </Link>
          </li>
        </ul>
      </AppCard>

      <div class="grid gap-5 lg:grid-cols-2">
        <AppCard v-if="can.orders" :title="t('portal.dashboard.orders_title')">
          <template #actions>
            <Link
              href="/client/orders"
              class="text-content-muted hover:text-content text-sm underline-offset-4 hover:underline"
            >
              {{ t('portal.dashboard.see_all') }}
            </Link>
          </template>

          <EmptyState
            v-if="orders.length === 0"
            :title="t('portal.dashboard.orders_none')"
            :description="t('portal.dashboard.orders_none_description')"
          />

          <ul v-else class="divide-line divide-y">
            <li v-for="order in orders" :key="order.number">
              <Link
                :href="`/client/orders/${order.number}`"
                class="hover:bg-surface-sunken -mx-2 flex items-center justify-between gap-4 rounded-[var(--radius-sm)] px-2 py-3 transition-colors duration-(--duration-fast)"
              >
                <span class="min-w-0">
                  <span class="block text-sm font-medium">{{ order.number }}</span>
                  <span class="text-content-muted block text-xs">
                    {{ formatDate(order.placedAt) }} · {{ order.statusLabel }}
                  </span>
                </span>
                <span class="shrink-0 text-sm tabular-nums">{{ order.total }}</span>
              </Link>
            </li>
          </ul>
        </AppCard>

        <div class="flex flex-col gap-5">
          <!-- Only when there is something in it. A zero balance is not news. -->
          <AppCard v-if="credit" :title="t('portal.dashboard.credit_title')">
            <p class="text-2xl font-semibold tracking-tight tabular-nums">
              {{ credit.balance }}
            </p>
            <p class="text-content-muted mt-2 text-sm leading-relaxed">
              {{ t('billing.portal.credit_explained') }}
            </p>
          </AppCard>

          <AppCard
            :title="t('portal.dashboard.coming_title')"
            :description="t('portal.dashboard.coming_description')"
          />
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
