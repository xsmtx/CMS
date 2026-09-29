<script setup lang="ts">
/**
 * Which service on a machine is using more than its neighbours (§21).
 *
 * **A comparison, not a threshold.** 40% of a machine's CPU is fine on a box
 * with two services and a problem on one with forty, so there is no number
 * here that says "too much" — every row is measured against the median of the
 * services beside it, and the median is printed next to the reading so the
 * claim can be checked rather than believed.
 *
 * **The empty answer has three different meanings** and the screen keeps them
 * apart: nothing is noisy, no adapter reports per service, or no machine
 * carries enough neighbours to compare. Drawing one empty state for all three
 * would tell an operator "everything is fine" when the truth is "nothing was
 * measured".
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, watch } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import FilterBar from '../../../Components/FilterBar.vue'
import FilterSelect from '../../../Components/FilterSelect.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { formatReading } from '../../../metrics'

interface Row {
  nodeKey: string
  nodeLabel: string
  serviceKey: string
  serviceLabel: string
  href: string | null
  metric: string
  metricLabel: string
  unit: string | null
  unitLabel: string
  value: number
  median: number
  times: number | null
  neighbours: number
}

const props = defineProps<{
  rows: Row[]
  measured: boolean
  // Worded on the server: the browser has no `trans_choice`, and every one
  // of these sentences has a count in it.
  unmeasuredDetail: string
  emptyDetail: string
  method: string
  multiple: number
  multiples: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const filters = reactive({ multiple: String(props.multiple) })

watch(filters, () => {
  router.get(
    '/admin/intelligence/noisy-neighbours',
    { ...filters },
    {
      preserveState: true,
      replace: true,
    },
  )
})

const COLUMNS: TableColumn[] = [
  { key: 'service', label: t('intelligence.noisy.columns.service') },
  { key: 'host', label: t('intelligence.noisy.columns.host') },
  { key: 'metric', label: t('intelligence.noisy.columns.metric') },
  { key: 'reading', label: t('intelligence.noisy.columns.reading'), numeric: true },
  { key: 'median', label: t('intelligence.noisy.columns.median'), numeric: true },
  { key: 'times', label: t('intelligence.noisy.columns.times'), numeric: true },
]
</script>

<template>
  <Head :title="t('intelligence.noisy.title')" />

  <AdminLayout :heading="t('ui.nav.noisy_neighbours')" :description="t('intelligence.noisy.intro')">
    <div class="flex flex-col gap-8">
      <!--
        Absent rather than disabled when nothing could be compared: a control
        that cannot change what is on the screen is a control that lies about
        what it does, and there is no list underneath for it to narrow.
      -->
      <FilterBar v-if="measured">
        <FilterSelect
          v-model="filters.multiple"
          :label="t('intelligence.noisy.multiple')"
          :options="multiples"
        />
      </FilterBar>

      <!--
        Nothing was measured, which is not the same answer as nothing being
        wrong. Per-service metrics are what this question needs, and most
        monitoring reports per machine.
      -->
      <EmptyState
        v-if="!measured"
        icon="info"
        :title="t('intelligence.noisy.unmeasured')"
        :description="unmeasuredDetail"
      />

      <EmptyState
        v-else-if="rows.length === 0"
        icon="ok"
        :title="t('intelligence.noisy.empty')"
        :description="emptyDetail"
      />

      <template v-else>
        <AppTable name="noisy-neighbours" :columns="COLUMNS">
          <AppTableRow v-for="row in rows" :key="`${row.serviceKey}:${row.metric}`">
            <td data-col="service">
              <Link v-if="row.href" :href="row.href" class="text-brand font-medium hover:underline">
                {{ row.serviceLabel }}
              </Link>
              <span v-else class="font-medium">{{ row.serviceLabel }}</span>
            </td>
            <td data-col="host" class="text-content-muted font-mono">{{ row.nodeLabel }}</td>
            <td data-col="metric">{{ row.metricLabel }}</td>
            <td data-col="reading" class="numeric">
              {{ formatReading(row.value, row.unit, row.unitLabel) }}
            </td>
            <td data-col="median" class="numeric">
              {{ formatReading(row.median, row.unit, row.unitLabel) }}
            </td>
            <td data-col="times" class="numeric">
              <!--
                A median of nought has no multiple, and printing one would be
                inventing a number. The sentence is what the row actually
                found: every other service is using none of this.
              -->
              <span v-if="row.times === null" class="text-content-muted">
                {{ t('intelligence.noisy.no_median') }}
              </span>
              <span v-else>{{ row.times.toFixed(1) }}&times;</span>
            </td>
          </AppTableRow>
        </AppTable>

        <AppAlert tone="info">
          {{ method }}
        </AppAlert>
      </template>
    </div>
  </AdminLayout>
</template>
