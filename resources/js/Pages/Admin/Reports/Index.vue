<script setup lang="ts">
/**
 * The monthly review.
 *
 * One page, in the order the questions get asked: what recurs, what moved, what
 * is owed, what is coming, where it came from. Somebody sits down once a month,
 * sets a period, reads it in one pass and screenshots it — so six screens with
 * six period pickers would be six chances for two of them to cover different
 * months.
 *
 * **Every money figure is a list, not a number.** A reseller selling in lira and
 * euros has two MRRs and there is no rate in this product to make them one.
 * Adding them would put a figure on the page that means nothing, and it would be
 * the figure somebody quotes. That is why the headline figures are a
 * `MetricStrip` with a slot per key rather than four numbers.
 *
 * **MRR and ARR are labelled as what they are.** ARR is twelve times the month,
 * not a year of collected revenue. Both are legitimate and they answer different
 * questions; printing one and calling it the other is the classic reporting lie.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'

import AppBarChart from '../../../Components/AppBarChart.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface MoneyRow {
  currency: string
  amount: string
  minor: number
}

const props = defineProps<{
  period: { from: string; to: string }
  revenue: {
    mrr: MoneyRow[]
    arr: MoneyRow[]
    active: number
    suspended: number
    added: number
    lost: number
    addedRecurring: MoneyRow[]
    lostRecurring: MoneyRow[]
  }
  aging: {
    asOf: string
    buckets: Record<string, MoneyRow[]>
    counts: Record<string, number>
    total: MoneyRow[]
  }
  breakdown: {
    gateways: {
      gateway: string
      label: string
      payments: number
      net: MoneyRow[]
      refunded: MoneyRow[]
    }[]
    products: { id: string | null; name: string | null; services: number; recurring: MoneyRow[] }[]
  }
  renewals: { days: number; services: number; value: MoneyRow[] }[]
  collected: { currency: string; months: { label: string; value: number }[] }
}>()

const { t } = useTranslations()

const period = reactive({ from: props.period.from, to: props.period.to })

function apply(): void {
  router.get('/admin/reports', { ...period }, { preserveState: true, replace: true })
}

/** Minor units into money, for the chart only. */
const chartFormat = computed(() => {
  const formatter = new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: props.collected.currency,
    maximumFractionDigits: 0,
  })

  return (value: number): string => formatter.format(value / 100)
})

/**
 * Net movement, per currency.
 *
 * Computed here because it is a subtraction of two figures that are already on
 * the page, and doing it on the server would mean a third list to keep in step
 * with the two it is derived from.
 */
const netMovement = computed(() => {
  const byCurrency = new Map<string, number>()

  for (const row of props.revenue.addedRecurring) {
    byCurrency.set(row.currency, (byCurrency.get(row.currency) ?? 0) + row.minor)
  }

  for (const row of props.revenue.lostRecurring) {
    byCurrency.set(row.currency, (byCurrency.get(row.currency) ?? 0) - row.minor)
  }

  return [...byCurrency.entries()].map(([currency, minor]) => ({ currency, minor }))
})

/**
 * The four headline figures, in one strip.
 *
 * `value` is only the fallback: MRR, ARR and movement all render through their
 * slot, because each of them is a list. Active services is the one honest
 * single number on the page.
 */
const figures = computed<Metric[]>(() => [
  {
    key: 'mrr',
    label: t('ui.reports.mrr'),
    value: '—',
    hint: t('ui.reports.mrr_hint'),
  },
  {
    key: 'arr',
    label: t('ui.reports.arr'),
    value: '—',
    hint: t('ui.reports.arr_hint'),
  },
  {
    key: 'active',
    label: t('ui.reports.active'),
    value: props.revenue.active,
    hint: t('ui.reports.suspended', { count: props.revenue.suspended }),
    href: '/admin/services?status=active',
  },
  {
    key: 'movement',
    label: t('ui.reports.movement'),
    value: '—',
  },
])

const AGING_ORDER = ['current', '1_30', '31_60', '61_90', 'over_90', 'no_due_date'] as const

const agingRows = computed(() =>
  AGING_ORDER.map((bucket) => ({
    bucket,
    label: t(`reports.aging.${bucket}`),
    count: props.aging.counts[bucket] ?? 0,
    money: props.aging.buckets[bucket] ?? [],
  })).filter((row) => row.count > 0 || row.bucket === 'current'),
)

const renewalColumns: TableColumn[] = [
  { key: 'window', label: t('ui.reports.window') },
  { key: 'services', label: t('ui.reports.services'), numeric: true },
  { key: 'value', label: t('ui.reports.value'), numeric: true },
]

const productColumns: TableColumn[] = [
  { key: 'product', label: t('ui.reports.product') },
  { key: 'services', label: t('ui.reports.services'), numeric: true },
  { key: 'recurring', label: t('ui.reports.recurring_month'), numeric: true },
]

const gatewayColumns: TableColumn[] = [
  { key: 'gateway', label: t('ui.reports.gateway') },
  { key: 'payments', label: t('ui.reports.payments'), numeric: true },
  { key: 'net', label: t('ui.reports.net'), numeric: true },
  { key: 'refunded', label: t('ui.reports.refunded'), numeric: true },
]

function formatMinor(minor: number, currency: string): string {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(minor / 100)
}
</script>

<template>
  <Head :title="t('ui.reports.title')" />

  <AdminLayout :heading="t('ui.reports.title')" :description="t('ui.reports.intro')">
    <div class="flex flex-col gap-8">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <AppInput v-model="period.from" type="date" :label="t('ui.reports.from')" />
        <AppInput v-model="period.to" type="date" :label="t('ui.reports.to')" />
        <AppButton type="submit">{{ t('ui.reports.apply') }}</AppButton>
      </form>

      <!--
        Recurring revenue, and what it is. A figure called MRR that is actually
        an average of the period is the commonest reporting lie, so both cells
        say in their hint which arithmetic produced them.
      -->
      <MetricStrip :items="figures">
        <template #mrr>
          <span v-for="row in revenue.mrr" :key="row.currency" class="block">{{ row.amount }}</span>
          <span v-if="revenue.mrr.length === 0" class="text-content-subtle">—</span>
        </template>

        <template #arr>
          <span v-for="row in revenue.arr" :key="row.currency" class="block">{{ row.amount }}</span>
          <span v-if="revenue.arr.length === 0" class="text-content-subtle">—</span>
        </template>

        <template #movement>
          <span>+{{ revenue.added }} / −{{ revenue.lost }}</span>
          <span
            v-for="row in netMovement"
            :key="row.currency"
            class="text-chrome block font-normal"
            :class="row.minor < 0 ? 'text-danger' : 'text-success'"
          >
            {{
              t('ui.reports.a_month', {
                amount: `${row.minor >= 0 ? '+' : ''}${formatMinor(row.minor, row.currency)}`,
              })
            }}
          </span>
        </template>
      </MetricStrip>

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <DetailSection
          :title="t('ui.reports.money_in')"
          :description="t('ui.reports.money_in_intro', { currency: collected.currency })"
        >
          <AppBarChart
            :title="t('ui.reports.money_in')"
            :rows="collected.months"
            :format="chartFormat"
            hide-title
          />
        </DetailSection>

        <DetailSection
          :title="t('ui.reports.owed')"
          :description="t('ui.reports.owed_intro', { date: aging.asOf })"
        >
          <ul class="flex flex-col gap-2">
            <li
              v-for="row in agingRows"
              :key="row.bucket"
              class="flex items-baseline justify-between gap-4"
            >
              <span
                class="text-body"
                :class="row.bucket === 'over_90' ? 'text-danger font-medium' : 'text-content-muted'"
              >
                {{ row.label }}
                <span class="text-content-subtle text-label">({{ row.count }})</span>
              </span>
              <span class="text-right">
                <span
                  v-for="money in row.money"
                  :key="money.currency"
                  class="text-body block tabular-nums"
                >
                  {{ money.amount }}
                </span>
                <span v-if="row.money.length === 0" class="text-content-subtle text-body">—</span>
              </span>
            </li>
          </ul>

          <div v-if="aging.total.length > 0" class="border-line mt-3 border-t pt-2">
            <div class="flex items-baseline justify-between gap-4">
              <span class="text-body font-medium">{{ t('ui.reports.outstanding') }}</span>
              <span class="text-right">
                <span
                  v-for="money in aging.total"
                  :key="money.currency"
                  class="text-body block font-semibold tabular-nums"
                >
                  {{ money.amount }}
                </span>
              </span>
            </div>
          </div>
        </DetailSection>
      </div>

      <DetailSection
        :title="t('ui.reports.coming')"
        :description="t('ui.reports.coming_intro')"
        :divided="false"
      >
        <AppTable name="reports-renewals" :columns="renewalColumns">
          <AppTableRow v-for="window in renewals" :key="window.days">
            <td data-col="window">{{ t('ui.reports.next_days', { days: window.days }) }}</td>
            <td data-col="services" class="numeric">{{ window.services }}</td>
            <td data-col="value" class="numeric">
              <span v-for="money in window.value" :key="money.currency" class="block tabular-nums">
                {{ money.amount }}
              </span>
              <span v-if="window.value.length === 0" class="text-content-subtle">—</span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-2">
        <DetailSection
          :title="t('ui.reports.by_product')"
          :description="t('ui.reports.by_product_intro')"
          :divided="breakdown.products.length === 0"
        >
          <AppTable
            v-if="breakdown.products.length > 0"
            name="reports-products"
            :columns="productColumns"
          >
            <AppTableRow v-for="product in breakdown.products" :key="product.id ?? 'unassigned'">
              <td data-col="product">
                <!-- Not dropped: a report whose total does not match the MRR
                     figure above it is a report nobody trusts. -->
                <span v-if="product.name">{{ product.name }}</span>
                <span v-else class="text-content-muted italic">
                  {{ t('ui.reports.no_product') }}
                </span>
              </td>
              <td data-col="services" class="numeric">{{ product.services }}</td>
              <td data-col="recurring" class="numeric">
                <span
                  v-for="money in product.recurring"
                  :key="money.currency"
                  class="block tabular-nums"
                >
                  {{ money.amount }}
                </span>
              </td>
            </AppTableRow>
          </AppTable>

          <EmptyState
            v-else
            variant="plain"
            icon="services"
            :title="t('ui.reports.no_services')"
            :description="t('ui.reports.no_services_detail')"
          />
        </DetailSection>

        <DetailSection
          :title="t('ui.reports.by_gateway')"
          :description="t('ui.reports.by_gateway_intro')"
          :divided="breakdown.gateways.length === 0"
        >
          <AppTable
            v-if="breakdown.gateways.length > 0"
            name="reports-gateways"
            :columns="gatewayColumns"
          >
            <AppTableRow v-for="gateway in breakdown.gateways" :key="gateway.gateway">
              <td data-col="gateway">{{ gateway.label }}</td>
              <td data-col="payments" class="numeric">{{ gateway.payments }}</td>
              <td data-col="net" class="numeric">
                <span v-for="money in gateway.net" :key="money.currency" class="block tabular-nums">
                  {{ money.amount }}
                </span>
              </td>
              <td data-col="refunded" class="numeric text-content-muted">
                <span
                  v-for="money in gateway.refunded"
                  :key="money.currency"
                  class="block tabular-nums"
                >
                  {{ money.amount }}
                </span>
                <span v-if="gateway.refunded.length === 0">—</span>
              </td>
            </AppTableRow>
          </AppTable>

          <EmptyState
            v-else
            variant="plain"
            icon="billing"
            :title="t('ui.reports.no_collection')"
            :description="t('ui.reports.no_collection_detail')"
          />
        </DetailSection>
      </div>

      <p class="text-content-subtle text-chrome">
        {{ t('ui.reports.elsewhere') }}
        <Link href="/admin/support/overview" class="underline underline-offset-4">
          {{ t('ui.reports.support_overview') }}</Link
        >{{ t('ui.reports.elsewhere_and') }}
        <Link href="/admin/reports/resellers" class="underline underline-offset-4">
          {{ t('ui.reports.reseller_performance') }}</Link
        >.
      </p>
    </div>
  </AdminLayout>
</template>
