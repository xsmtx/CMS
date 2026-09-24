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
 * the figure somebody quotes.
 *
 * **MRR and ARR are labelled as what they are.** ARR is twelve times the month,
 * not a year of collected revenue. Both are legitimate and they answer different
 * questions; printing one and calling it the other is the classic reporting lie.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'

import AppBarChart from '../../../Components/AppBarChart.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTable from '../../../Components/AppTable.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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

const AGING_ORDER = ['current', '1_30', '31_60', '61_90', 'over_90', 'no_due_date'] as const

const agingRows = computed(() =>
  AGING_ORDER.map((bucket) => ({
    bucket,
    label: t(`reports.aging.${bucket}`),
    count: props.aging.counts[bucket] ?? 0,
    money: props.aging.buckets[bucket] ?? [],
  })).filter((row) => row.count > 0 || row.bucket === 'current'),
)

function formatMinor(minor: number, currency: string): string {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(minor / 100)
}
</script>

<template>
  <Head title="Reports" />

  <AdminLayout
    heading="Reports"
    description="The monthly review: what recurs, what is owed, what is coming, and where it came from."
  >
    <div class="flex flex-col gap-5">
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
        <AppInput v-model="period.from" label="From" type="date" />
        <AppInput v-model="period.to" label="To" type="date" />
        <AppButton type="submit">Apply</AppButton>
      </form>

      <!-- Recurring revenue, and what it is. A figure called MRR that is
           actually an average of the period is the commonest reporting lie. -->
      <div
        class="border-line bg-surface-primary [&>*]:border-line grid divide-y rounded-lg border sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 sm:[&>*+*]:border-l"
      >
        <div class="flex flex-col gap-1 px-5 py-4">
          <span class="text-content-subtle text-label uppercase">Recurring, per month</span>
          <span
            v-for="row in revenue.mrr"
            :key="row.currency"
            class="text-[1.5rem] leading-none font-semibold tabular-nums"
          >
            {{ row.amount }}
          </span>
          <span v-if="revenue.mrr.length === 0" class="text-content-subtle text-[1.5rem]">—</span>
          <span class="text-content-subtle text-chrome">
            Every active service, divided down to a month
          </span>
        </div>

        <div class="flex flex-col gap-1 px-5 py-4">
          <span class="text-content-subtle text-label uppercase">Annualised</span>
          <span
            v-for="row in revenue.arr"
            :key="row.currency"
            class="text-[1.5rem] leading-none font-semibold tabular-nums"
          >
            {{ row.amount }}
          </span>
          <span v-if="revenue.arr.length === 0" class="text-content-subtle text-[1.5rem]">—</span>
          <!-- Said out loud: this is arithmetic on the figure to the left, not a
               year of collected revenue. -->
          <span class="text-content-subtle text-chrome"
            >Twelve times the month, not a year read</span
          >
        </div>

        <Link
          href="/admin/services?status=active"
          class="hover:bg-surface-hover flex flex-col gap-1 px-5 py-4"
        >
          <span class="text-content-subtle text-label uppercase">Active services</span>
          <span class="text-[1.5rem] leading-none font-semibold tabular-nums">
            {{ revenue.active }}
          </span>
          <span class="text-content-subtle text-chrome"> {{ revenue.suspended }} suspended </span>
        </Link>

        <div class="flex flex-col gap-1 px-5 py-4">
          <span class="text-content-subtle text-label uppercase">Movement in the period</span>
          <span class="text-[1.5rem] leading-none font-semibold tabular-nums">
            +{{ revenue.added }} / −{{ revenue.lost }}
          </span>
          <span
            v-for="row in netMovement"
            :key="row.currency"
            class="text-chrome tabular-nums"
            :class="row.minor < 0 ? 'text-danger' : 'text-success'"
          >
            {{ row.minor >= 0 ? '+' : '' }}{{ formatMinor(row.minor, row.currency) }} a month
          </span>
        </div>
      </div>

      <div class="grid gap-5 lg:grid-cols-3">
        <AppCard
          class="lg:col-span-2"
          title="Money in, by month"
          :description="`From the ledger, in ${collected.currency}. An invoice's date is when it was issued, not when it was paid.`"
        >
          <AppBarChart title="Money in, by month" :rows="collected.months" :format="chartFormat" />
        </AppCard>

        <AppCard
          title="What is owed"
          :description="`As at ${aging.asOf}, measured from the due date rather than the issue date.`"
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
              <span class="text-body font-medium">Outstanding</span>
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
        </AppCard>
      </div>

      <AppCard
        title="What is coming"
        description="Active services due to renew, counted from today. The figure is the whole term, which is what will be billed."
      >
        <AppTable :headers="['Window', 'Services', 'Value']" :numeric="[1, 2]">
          <tr v-for="window in renewals" :key="window.days">
            <td class="px-4 py-2.5">Next {{ window.days }} days</td>
            <td class="numeric px-4 py-2.5">{{ window.services }}</td>
            <td class="numeric px-4 py-2.5">
              <span v-for="money in window.value" :key="money.currency" class="block tabular-nums">
                {{ money.amount }}
              </span>
              <span v-if="window.value.length === 0" class="text-content-subtle">—</span>
            </td>
          </tr>
        </AppTable>
      </AppCard>

      <div class="grid gap-5 lg:grid-cols-2">
        <AppCard
          title="By product"
          description="What recurs, per month, per product. From the services — an invoice line copies a description, which cannot be grouped."
        >
          <AppTable
            v-if="breakdown.products.length > 0"
            :headers="['Product', 'Services', 'Recurring / month']"
            :numeric="[1, 2]"
          >
            <tr v-for="product in breakdown.products" :key="product.id ?? 'unassigned'">
              <td class="px-4 py-2.5">
                <!-- Not dropped: a report whose total does not match the MRR
                     figure above it is a report nobody trusts. -->
                <span v-if="product.name">{{ product.name }}</span>
                <span v-else class="text-content-muted italic">No product attached</span>
              </td>
              <td class="numeric px-4 py-2.5">{{ product.services }}</td>
              <td class="numeric px-4 py-2.5">
                <span
                  v-for="money in product.recurring"
                  :key="money.currency"
                  class="block tabular-nums"
                >
                  {{ money.amount }}
                </span>
              </td>
            </tr>
          </AppTable>

          <p v-else class="text-content-muted text-body">No active services yet.</p>
        </AppCard>

        <AppCard
          title="By gateway"
          description="What each gateway collected in the period, net of what it gave back."
        >
          <AppTable
            v-if="breakdown.gateways.length > 0"
            :headers="['Gateway', 'Payments', 'Net', 'Refunded']"
            :numeric="[1, 2, 3]"
          >
            <tr v-for="gateway in breakdown.gateways" :key="gateway.gateway">
              <td class="px-4 py-2.5">{{ gateway.label }}</td>
              <td class="numeric px-4 py-2.5">{{ gateway.payments }}</td>
              <td class="numeric px-4 py-2.5">
                <span v-for="money in gateway.net" :key="money.currency" class="block tabular-nums">
                  {{ money.amount }}
                </span>
              </td>
              <td class="numeric text-content-muted px-4 py-2.5">
                <span
                  v-for="money in gateway.refunded"
                  :key="money.currency"
                  class="block tabular-nums"
                >
                  {{ money.amount }}
                </span>
                <span v-if="gateway.refunded.length === 0">—</span>
              </td>
            </tr>
          </AppTable>

          <p v-else class="text-content-muted text-body">Nothing was collected in this period.</p>
        </AppCard>
      </div>

      <p class="text-content-subtle text-label">
        Support metrics are on the
        <Link href="/admin/support/overview" class="underline underline-offset-4">
          support overview
        </Link>
        ; what each reseller sold is on
        <Link href="/admin/reports/resellers" class="underline underline-offset-4">
          reseller performance </Link
        >.
      </p>
    </div>
  </AdminLayout>
</template>
