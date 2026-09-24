<script setup lang="ts">
/**
 * What is arriving, from where, and what has stopped.
 *
 * The screen nobody asks for until the first time a graph is empty and nobody can
 * say why. In a platform whose whole claim is correlation, "the correlation has no
 * data" is the failure that matters most and the one that is otherwise completely
 * silent — a dashboard with no numbers on it looks like a quiet night.
 *
 * So it shows the absences as loudly as the readings: how many resources nothing
 * reports on, and which readings have outlived the freshness their own source
 * declared. A reading five minutes old from a minutely poller is a problem and the
 * same reading from an hourly one is not, which is why staleness is per row rather
 * than one number for the page.
 */
import { Head, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStat from '../../../Components/AppStat.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface NodeRow {
  id: string
  label: string
  kind: string
  kindLabel: string
  key: string
}

interface MetricRow {
  id: string
  metric: string
  metricLabel: string
  unit: string | null
  unitLabel: string
  value: number
  sampledAt: string
  stale: boolean
  source: string
  node: NodeRow | null
}

const props = defineProps<{
  metrics: { data: MetricRow[]; currentPage: number; lastPage: number; total: number }
  filters: { source: string | null; metric: string | null }
  sources: { value: string; label: string; count: number }[]
  stats: { measurements: number; sources: number; stale: number; unwatched: number }
  unwatched: NodeRow[]
}>()

const { t } = useTranslations()

function go(changes: Record<string, string | number | null>): void {
  router.get(
    '/admin/resources/telemetry',
    { source: props.filters.source, metric: props.filters.metric, ...changes },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

const columns = [
  { key: 'resource', label: t('infrastructure.telemetry.columns.resource') },
  { key: 'metric', label: t('infrastructure.telemetry.columns.metric') },
  { key: 'value', label: t('infrastructure.telemetry.columns.value'), numeric: true },
  { key: 'sampled', label: t('infrastructure.telemetry.columns.sampled') },
  { key: 'source', label: t('infrastructure.telemetry.columns.source'), optional: true },
]

function reading(metric: MetricRow): string {
  if (metric.unit === 'ratio') return `${(metric.value * 100).toFixed(1)}%`
  if (metric.unit === 'bytes') return scaled(metric.value, ['B', 'kB', 'MB', 'GB', 'TB'])
  if (metric.unit === 'bits_per_second') {
    return scaled(metric.value, ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s'])
  }

  const rounded = Math.abs(metric.value) >= 100 ? Math.round(metric.value) : metric.value

  return `${rounded}${metric.unitLabel === '' ? '' : ' ' + metric.unitLabel}`
}

function scaled(value: number, units: string[]): string {
  let index = 0
  let current = value

  while (current >= 1000 && index < units.length - 1) {
    current /= 1000
    index++
  }

  return `${current.toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

function when(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Telemetry" />

  <AdminLayout
    :heading="t('infrastructure.telemetry.title')"
    :description="t('infrastructure.telemetry.intro')"
  >
    <div class="flex flex-col gap-5">
      <div class="flex flex-wrap gap-2">
        <AppStat
          :label="t('infrastructure.telemetry.stats.measurements')"
          :value="stats.measurements"
          :active="filters.source === null && filters.metric === null"
          @select="go({ source: null, metric: null })"
        />
        <AppStat :label="t('infrastructure.telemetry.stats.sources')" :value="stats.sources" />
        <AppStat
          :label="t('infrastructure.telemetry.stats.stale')"
          :value="stats.stale"
          :tone="stats.stale > 0 ? 'warning' : 'neutral'"
        />
        <AppStat
          :label="t('infrastructure.telemetry.stats.unwatched')"
          :value="stats.unwatched"
          :tone="stats.unwatched > 0 ? 'warning' : 'neutral'"
        />
      </div>

      <EmptyState
        v-if="metrics.data.length === 0"
        :title="t('infrastructure.telemetry.title')"
        :description="t('infrastructure.telemetry.empty')"
        icon="automation"
      />

      <AppTable v-else name="telemetry" :columns="columns">
        <template #toolbar>
          <div class="flex flex-wrap items-center gap-1.5">
            <AppButton
              v-for="source in sources"
              :key="source.value"
              size="sm"
              :variant="filters.source === source.value ? 'primary' : 'ghost'"
              @click="go({ source: filters.source === source.value ? null : source.value })"
            >
              {{ source.label }} · {{ source.count }}
            </AppButton>
          </div>
        </template>

        <AppTableRow v-for="metric in metrics.data" :key="metric.id">
          <td data-col="resource" class="px-4 py-2.5">
            <span class="font-medium">{{ metric.node?.label ?? '—' }}</span>
            <span class="text-content-subtle text-chrome block">{{ metric.node?.kindLabel }}</span>
          </td>
          <td data-col="metric" class="px-4 py-2.5">{{ metric.metricLabel }}</td>
          <td data-col="value" class="numeric px-4 py-2.5 font-medium tabular-nums">
            {{ reading(metric) }}
          </td>
          <td data-col="sampled" class="text-chrome px-4 py-2.5 tabular-nums">
            {{ when(metric.sampledAt) }}
            <AppBadge v-if="metric.stale" tone="warning">
              {{ t('infrastructure.telemetry.stale') }}
            </AppBadge>
          </td>
          <td data-col="source" class="text-content-muted text-chrome px-4 py-2.5 font-mono">
            {{ metric.source }}
          </td>
        </AppTableRow>
      </AppTable>

      <div v-if="metrics.lastPage > 1" class="flex items-center gap-3">
        <AppButton
          variant="secondary"
          :disabled="metrics.currentPage <= 1"
          @click="go({ page: metrics.currentPage - 1 })"
        >
          Previous
        </AppButton>
        <span class="text-content-muted text-chrome tabular-nums">
          {{ metrics.currentPage }} / {{ metrics.lastPage }} — {{ metrics.total }}
        </span>
        <AppButton
          variant="secondary"
          :disabled="metrics.currentPage >= metrics.lastPage"
          @click="go({ page: metrics.currentPage + 1 })"
        >
          Next
        </AppButton>
      </div>

      <!-- The absences. Capped on the server, because on a fresh installation
           this is every resource and a list of four thousand is not a finding. -->
      <AppCard v-if="unwatched.length > 0">
        <div class="flex flex-col gap-2">
          <p class="text-content-muted text-body">
            {{ t('infrastructure.telemetry.unwatched_intro') }}
          </p>
          <ul class="flex flex-col gap-1">
            <li v-for="node in unwatched" :key="node.id" class="text-body flex items-center gap-2">
              <span>{{ node.label }}</span>
              <AppBadge tone="neutral">{{ node.kindLabel }}</AppBadge>
            </li>
          </ul>
        </div>
      </AppCard>
    </div>
  </AdminLayout>
</template>
