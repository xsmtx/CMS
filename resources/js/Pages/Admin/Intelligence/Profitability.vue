<script setup lang="ts">
/**
 * What a month earned and what it cost to earn it (§21).
 *
 * **A margin is not always a number, and this screen says so.** There is no
 * rate anywhere in this product, so a customer earning euros on a server
 * costing lira has a revenue, a cost and no margin — and printing the
 * revenue as though the cost were zero would be the most misleading figure
 * this product could produce. Those rows say "Not comparable" and carry the
 * reason.
 *
 * **Every money cell is a list.** A reseller selling in lira and euros has
 * two revenues and there is nothing here to make them one, so a cell stacks
 * them rather than printing a total that means nothing.
 */
import { Head, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppSegmented from '../../../Components/AppSegmented.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface Amount {
  currency: string
  amount: string
  minor: number
}

interface Row {
  key: string
  label: string
  services: number
  revenue: Amount[]
  cost: Amount[]
  margin: Amount[] | null
  mixedCurrency: boolean
}

const props = defineProps<{
  month: string
  previous: string
  next: string
  grouping: string
  groupings: { value: string; label: string }[]
  rows: Row[]
  totals: { revenue: Amount[]; cost: Amount[]; unallocated: Amount[] }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'group', label: t('intelligence.profit.columns.group') },
  { key: 'services', label: t('intelligence.profit.columns.services'), numeric: true },
  { key: 'revenue', label: t('intelligence.profit.columns.revenue'), numeric: true },
  { key: 'cost', label: t('intelligence.profit.columns.cost'), numeric: true },
  { key: 'margin', label: t('intelligence.profit.columns.margin'), numeric: true },
]

/*
 * A date worded at the edge, which is this product's rule for every date the
 * server sends as `Y-m-d` — but with the locale the **reader chose**, not
 * the one their operating system happens to be set to.
 *
 * `toLocaleDateString()` with no locale follows the browser, which is
 * invisible on `24.09.2026` and glaring on a month name: an English page
 * headed "Eylül 2026" is what this looked like before the shared prop was
 * read.
 */
const locale = computed(() => usePage().props.locale ?? 'en')

const monthLabel = computed(() =>
  new Date(`${props.month}T00:00:00`).toLocaleDateString(locale.value, {
    month: 'long',
    year: 'numeric',
  }),
)

/*
 * Two cells, not one per currency. `MetricStrip`'s named slot exists for
 * precisely this — "money is a list, not a number" — and a cell per currency
 * printed the word "Cost" twice with nothing to tell the two apart.
 */
const strip = computed(() => [
  { key: 'revenue', label: t('intelligence.profit.revenue'), value: '' },
  { key: 'cost', label: t('intelligence.profit.cost'), value: '' },
])

const anyMixed = computed(() => props.rows.some((row) => row.mixedCurrency))

function go(query: Record<string, string>): void {
  router.get(
    '/admin/intelligence/profitability',
    { month: props.month, by: props.grouping, ...query },
    {
      preserveState: true,
      replace: true,
    },
  )
}
</script>

<template>
  <Head :title="t('intelligence.profit.title')" />

  <AdminLayout :heading="t('ui.nav.profitability')" :description="t('intelligence.profit.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="go({ month: previous })">
        {{ t('intelligence.profit.previous') }}
      </AppButton>
      <AppButton variant="ghost" @click="go({ month: next })">
        {{ t('intelligence.profit.next') }}
      </AppButton>
    </template>

    <template #meta>
      <span class="text-content-muted">{{ monthLabel }}</span>
    </template>

    <div class="flex flex-col gap-8">
      <div class="flex flex-wrap items-center gap-3">
        <AppSegmented
          :model-value="grouping"
          :segments="groupings"
          :label="t('intelligence.profit.title')"
          @update:model-value="(value: string) => go({ by: value })"
        />
      </div>

      <div v-if="strip.length > 0">
        <MetricStrip :items="strip">
          <template #revenue>
            <span v-for="row in totals.revenue" :key="row.currency" class="block">
              {{ row.amount }}
            </span>
            <span v-if="totals.revenue.length === 0" class="text-content-subtle">—</span>
          </template>
          <template #cost>
            <span v-for="row in totals.cost" :key="row.currency" class="block">
              {{ row.amount }}
            </span>
            <span v-if="totals.cost.length === 0" class="text-content-subtle">—</span>
          </template>
        </MetricStrip>
        <!--
          Costs that reached no service. In the month's total and in
          nobody's row, because an empty server belongs to no customer and
          dropping it would understate the month.
        -->
        <p v-if="totals.unallocated.length > 0" class="text-content-muted text-chrome mt-2">
          {{ t('intelligence.profit.unallocated') }}:
          <!--
            Joined with a separator: two figures run together read as one
            number nobody can parse.
          -->
          <span class="tabular-nums">
            {{ totals.unallocated.map((row) => row.amount).join(' · ') }}
          </span>
          — {{ t('intelligence.profit.unallocated_hint') }}
        </p>
      </div>

      <EmptyState
        v-if="rows.length === 0"
        icon="billing"
        :title="t('intelligence.profit.empty')"
        :description="t('intelligence.profit.empty_detail')"
      />

      <AppTable v-else name="profitability" :columns="COLUMNS">
        <AppTableRow v-for="row in rows" :key="row.key">
          <td data-col="group" class="font-medium">{{ row.label }}</td>
          <td data-col="services" class="numeric text-content-muted tabular-nums">
            {{ row.services }}
          </td>
          <td data-col="revenue" class="numeric tabular-nums">
            <span v-for="amount in row.revenue" :key="amount.currency" class="block">
              {{ amount.amount }}
            </span>
            <span v-if="row.revenue.length === 0" class="text-content-subtle">—</span>
          </td>
          <td data-col="cost" class="numeric tabular-nums">
            <span v-for="amount in row.cost" :key="amount.currency" class="block">
              {{ amount.amount }}
            </span>
            <span v-if="row.cost.length === 0" class="text-content-subtle">—</span>
          </td>
          <td data-col="margin" class="numeric tabular-nums">
            <!--
              Never a number across currencies. Both figures beside it are
              true; the difference between them is not a number, and saying
              so is the only honest answer.
            -->
            <template v-if="row.margin">
              <span v-for="amount in row.margin" :key="amount.currency" class="block">
                {{ amount.amount }}
              </span>
            </template>
            <span v-else class="text-content-subtle">
              {{ t('intelligence.profit.mixed') }}
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <!--
        The reason a margin is missing is essential, and essential
        information never lives in a tooltip. Said once, under the table,
        and only when something actually is not comparable.
      -->
      <AppAlert v-if="anyMixed" tone="info">
        {{ t('intelligence.profit.mixed_hint') }}
      </AppAlert>
    </div>
  </AdminLayout>
</template>
