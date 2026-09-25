<script setup lang="ts">
/**
 * The client area landing page.
 *
 * What is running comes first — it is the account, and the reason the rest
 * of the page exists. Then what is owed, soonest first, which is the reason
 * a dunning run exists. Everything below that is something a customer might
 * want rather than something they came for.
 */
import { Head, Link } from '@inertiajs/vue3'

import AppStatus from '../../Components/AppStatus.vue'
import AppTable from '../../Components/AppTable.vue'
import AppTableRow from '../../Components/AppTableRow.vue'
import AppCard from '../../Components/AppCard.vue'
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

interface RunningService {
  id: string
  name: string
  domain: string | null
  status: string
  statusLabel: string
  nextDueOn: string | null
}

interface HeldDomain {
  id: string
  name: string
  status: string
  statusLabel: string
  expiresOn: string | null
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
  services: RunningService[]
  domains: HeldDomain[]
  can: { billing: boolean; orders: boolean; services: boolean; domains: boolean }
}>()

const { t } = useTranslations()

const UNPAID_COLUMNS: TableColumn[] = [
  { key: 'invoice', label: t('portal.columns.invoice') },
  { key: 'due', label: t('portal.columns.due') },
  { key: 'amount', label: t('portal.columns.amount'), numeric: true },
]

const SERVICE_COLUMNS: TableColumn[] = [
  { key: 'service', label: t('portal.columns.service') },
  { key: 'status', label: t('portal.columns.status') },
  { key: 'renews', label: t('portal.columns.next_due') },
]

const DOMAIN_COLUMNS: TableColumn[] = [
  { key: 'domain', label: t('portal.columns.domain') },
  { key: 'status', label: t('portal.columns.status') },
  { key: 'expires', label: t('portal.columns.expires') },
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
      <AppCard
        v-if="can.services"
        :flush="services.length > 0"
        :title="t('portal.dashboard.services_title')"
      >
        <template #actions>
          <Link
            href="/client/services"
            class="text-content-muted hover:text-content text-body underline-offset-4 hover:underline"
          >
            {{ t('portal.dashboard.see_all') }}
          </Link>
        </template>

        <EmptyState
          v-if="services.length === 0"
          variant="plain"
          icon="services"
          :title="t('portal.dashboard.services_none')"
          :description="t('portal.dashboard.services_none_description')"
        />

        <AppTable v-else flush name="portal-services" :columns="SERVICE_COLUMNS">
          <AppTableRow v-for="service in services" :key="service.id">
            <td data-col="service">
              <Link
                :href="`/client/services/${service.id}`"
                class="font-medium underline-offset-4 hover:underline"
              >
                {{ service.name }}
              </Link>
              <span v-if="service.domain" class="text-content-muted text-chrome block font-mono">
                {{ service.domain }}
              </span>
            </td>
            <td data-col="status">
              <AppStatus :tone="statusTone(service.status)" :label="service.statusLabel" />
            </td>
            <td data-col="renews" class="text-content-muted">
              {{ formatDate(service.nextDueOn) }}
            </td>
          </AppTableRow>
        </AppTable>
      </AppCard>

      <AppCard
        v-if="can.domains"
        :flush="domains.length > 0"
        :title="t('portal.dashboard.domains_title')"
      >
        <template #actions>
          <Link
            href="/client/domains"
            class="text-content-muted hover:text-content text-body underline-offset-4 hover:underline"
          >
            {{ t('portal.dashboard.see_all') }}
          </Link>
        </template>

        <EmptyState
          v-if="domains.length === 0"
          variant="plain"
          icon="domains"
          :title="t('portal.dashboard.domains_none')"
          :description="t('portal.dashboard.domains_none_description')"
        />

        <AppTable v-else flush name="portal-domains" :columns="DOMAIN_COLUMNS">
          <AppTableRow v-for="domain in domains" :key="domain.id">
            <td data-col="domain">
              <Link
                :href="`/client/domains/${domain.id}`"
                class="font-medium underline-offset-4 hover:underline"
              >
                {{ domain.name }}
              </Link>
            </td>
            <td data-col="status">
              <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
            </td>
            <td data-col="expires" class="text-content-muted">
              {{ formatDate(domain.expiresOn) }}
            </td>
          </AppTableRow>
        </AppTable>
      </AppCard>

      <AppCard
        v-if="can.billing"
        :flush="unpaid.length > 0"
        :title="t('portal.dashboard.unpaid_title')"
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

        <AppTable v-else flush name="portal-unpaid" :columns="UNPAID_COLUMNS">
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
      </AppCard>

      <!-- Two columns only when the credit panel is there to fill the second
           one: a half-width panel beside nothing reads as something missing. -->
      <div class="grid gap-8" :class="credit ? 'lg:grid-cols-2' : ''">
        <AppCard
          v-if="can.orders"
          :flush="orders.length > 0"
          :title="t('portal.dashboard.orders_title')"
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

          <AppTable v-else flush name="portal-orders" :columns="ORDER_COLUMNS">
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
        </AppCard>

        <!-- Only when there is something in it. A zero balance is not news. -->
        <AppCard v-if="credit" :title="t('portal.dashboard.credit_title')">
          <p class="text-page font-semibold tabular-nums">{{ credit.balance }}</p>
          <p class="text-content-muted text-body mt-2 max-w-[60ch] leading-relaxed">
            {{ t('billing.portal.credit_explained') }}
          </p>
        </AppCard>
      </div>
    </div>
  </ClientLayout>
</template>
