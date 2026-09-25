<script setup lang="ts">
/**
 * The client area landing page.
 *
 * Owed first, and soonest first inside it: it is the reason a customer opens
 * this page, and the reason a dunning run exists. Everything else on it is
 * something they might want, rather than something they came for.
 */
import { Head, Link } from '@inertiajs/vue3'

import AppStatus from '../../Components/AppStatus.vue'
import AppTable from '../../Components/AppTable.vue'
import AppTableRow from '../../Components/AppTableRow.vue'
import DetailSection from '../../Components/DetailSection.vue'
import EmptyState from '../../Components/EmptyState.vue'
import { type TableColumn } from '../../Components/tableContext'
import { useTranslations } from '../../composables/useTranslations'
import ClientLayout from '../../Layouts/ClientLayout.vue'
import { statusTone } from '../../status'

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

const UNPAID_COLUMNS: TableColumn[] = [
  { key: 'invoice', label: t('portal.columns.invoice') },
  { key: 'due', label: t('portal.columns.due') },
  { key: 'amount', label: t('portal.columns.amount'), numeric: true },
]

const ORDER_COLUMNS: TableColumn[] = [
  { key: 'order', label: t('portal.columns.order') },
  { key: 'placed', label: t('portal.columns.placed') },
  { key: 'total', label: t('portal.columns.total'), numeric: true },
]

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
    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="can.billing"
        :title="t('portal.dashboard.unpaid_title')"
        :divided="unpaid.length === 0"
      >
        <template #actions>
          <Link
            href="/client/billing"
            class="text-content-muted hover:text-content text-body underline-offset-4 hover:underline"
          >
            {{ t('portal.dashboard.see_all') }}
          </Link>
        </template>

        <EmptyState
          v-if="unpaid.length === 0"
          variant="plain"
          icon="billing"
          :title="t('portal.dashboard.unpaid_none')"
          :description="t('portal.dashboard.unpaid_none_description')"
        />

        <AppTable v-else name="portal-unpaid" :columns="UNPAID_COLUMNS">
          <AppTableRow v-for="invoice in unpaid" :key="invoice.number">
            <td data-col="invoice">
              <Link
                :href="`/client/billing/invoices/${invoice.number}`"
                class="font-medium underline-offset-4 hover:underline"
              >
                {{ invoice.number }}
              </Link>
            </td>
            <td data-col="due">
              <span class="text-content-muted">{{ formatDate(invoice.dueOn) }}</span>
              <AppStatus
                v-if="invoice.isPastDue"
                class="ml-3"
                tone="critical"
                :label="t('billing.portal.past_due')"
              />
            </td>
            <td data-col="amount" class="numeric font-semibold tabular-nums">
              {{ invoice.balance }}
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <div class="grid gap-8 lg:grid-cols-2">
        <DetailSection
          v-if="can.orders"
          :title="t('portal.dashboard.orders_title')"
          :divided="orders.length === 0"
        >
          <template #actions>
            <Link
              href="/client/orders"
              class="text-content-muted hover:text-content text-body underline-offset-4 hover:underline"
            >
              {{ t('portal.dashboard.see_all') }}
            </Link>
          </template>

          <EmptyState
            v-if="orders.length === 0"
            variant="plain"
            icon="orders"
            :title="t('portal.dashboard.orders_none')"
            :description="t('portal.dashboard.orders_none_description')"
          />

          <AppTable v-else name="portal-orders" :columns="ORDER_COLUMNS">
            <AppTableRow v-for="order in orders" :key="order.number">
              <td data-col="order">
                <Link
                  :href="`/client/orders/${order.number}`"
                  class="font-medium underline-offset-4 hover:underline"
                >
                  {{ order.number }}
                </Link>
                <!--
                  Wrapped rather than given `block`: the status is an
                  `inline-flex` and which of two utilities wins is decided by
                  the order Tailwind emits them in, not by the attribute.
                -->
                <span class="mt-0.5 block">
                  <AppStatus :tone="statusTone(order.status)" :label="order.statusLabel" />
                </span>
              </td>
              <td data-col="placed" class="text-content-muted">{{ formatDate(order.placedAt) }}</td>
              <td data-col="total" class="numeric tabular-nums">{{ order.total }}</td>
            </AppTableRow>
          </AppTable>
        </DetailSection>

        <!-- Only when there is something in it. A zero balance is not news. -->
        <DetailSection v-if="credit" :title="t('portal.dashboard.credit_title')">
          <p class="text-page font-semibold tabular-nums">{{ credit.balance }}</p>
          <p class="text-content-muted text-body mt-2 max-w-[60ch] leading-relaxed">
            {{ t('billing.portal.credit_explained') }}
          </p>
        </DetailSection>
      </div>
    </div>
  </ClientLayout>
</template>
