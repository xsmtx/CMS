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
import AppPagination from '../../../Components/AppPagination.vue'
import AppStat from '../../../Components/AppStat.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface NodeRow {
  id: string
  label: string
  kind: string
  kindLabel: string
  key: string
}

interface CapacityRow {
  id: string
  node: string
  nodeKey: string
  metric: string
  metricLabel: string
  /** How full it is now, as a ratio of the ceiling. */
  utilisation: number
  daysRemaining: number | null
  fullOn: string | null
  /** How many daily points the line was drawn through. */
  days: number
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
  metrics: {
    links: PaginationLink[]
    data: MetricRow[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { source: string | null; metric: string | null }
  sources: { value: string; label: string; count: number }[]
  stats: { measurements: number; sources: number; stale: number; unwatched: number }
  unwatched: NodeRow[]
  capacity: CapacityRow[]
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

const CAPACITY_COLUMNS: TableColumn[] = [
  { key: 'resource', label: t('infrastructure.telemetry.columns.resource') },
  { key: 'metric', label: t('infrastructure.telemetry.columns.metric') },
  { key: 'now', label: t('infrastructure.capacity.now'), numeric: true },
  { key: 'left', label: t('infrastructure.capacity.days_left'), numeric: true },
  { key: 'when', label: t('infrastructure.capacity.full_on') },
]

/**
 * How loudly to say it.
 *
 * A fortnight is when ordering something starts to be urgent and a quarter is
 * when it is merely worth knowing — the thresholds are about procurement
 * rather than about the numbers.
 */
function capacityTone(row: CapacityRow): 'critical' | 'warning' | 'info' {
  if (row.daysRemaining !== null && row.daysRemaining <= 14) return 'critical'
  if (row.daysRemaining !== null && row.daysRemaining <= 90) return 'warning'

  return 'info'
}

function percent(ratio: number): string {
  return `${(ratio * 100).toFixed(1)}%`
}

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

      <!--
        What is running out, before what is arriving: an operator who opens
        this screen because a graph looked quiet still needs to know that a
        disk fills in nine days. Drawn only when something is actually
        filling — a list that said "not filling" forty times is a list nobody
        reads to the bottom.
      -->
      <DetailSection
        v-if="capacity.length > 0"
        :title="t('infrastructure.capacity.title')"
        :description="t('infrastructure.capacity.intro')"
        :divided="false"
      >
        <AppTable name="capacity" :columns="CAPACITY_COLUMNS">
          <AppTableRow v-for="row in capacity" :key="row.id">
            <td data-col="resource">
              <span class="font-medium">{{ row.node }}</span>
              <span class="text-content-subtle text-chrome block font-mono">{{ row.nodeKey }}</span>
            </td>
            <td data-col="metric">{{ row.metricLabel }}</td>
            <td data-col="now" class="numeric tabular-nums">{{ percent(row.utilisation) }}</td>
            <td data-col="left" class="numeric tabular-nums">
              <AppStatus
                :tone="capacityTone(row)"
                :label="t('infrastructure.capacity.in_days', { days: row.daysRemaining ?? 0 })"
              />
            </td>
            <td data-col="when" class="text-content-muted text-chrome">
              {{ row.fullOn === null ? '—' : new Date(row.fullOn).toLocaleDateString() }}
              <!-- Said out loud: a date from eight points and a date from
                   ninety deserve different amounts of belief. -->
              <span class="text-content-subtle block">
                {{ t('infrastructure.capacity.from_days', { days: row.days }) }}
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

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
          <td data-col="resource">
            <span class="font-medium">{{ metric.node?.label ?? '—' }}</span>
            <span class="text-content-subtle text-chrome block">{{ metric.node?.kindLabel }}</span>
          </td>
          <td data-col="metric">{{ metric.metricLabel }}</td>
          <td data-col="value" class="numeric font-medium tabular-nums">
            {{ reading(metric) }}
          </td>
          <td data-col="sampled" class="text-chrome tabular-nums">
            {{ when(metric.sampledAt) }}
            <AppBadge v-if="metric.stale" tone="warning">
              {{ t('infrastructure.telemetry.stale') }}
            </AppBadge>
          </td>
          <td data-col="source" class="text-content-muted text-chrome font-mono">
            {{ metric.source }}
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="metrics.links" :total="metrics.total" />

      <!-- The absences. Capped on the server, because on a fresh installation
           this is every resource and a list of four thousand is not a finding. -->
      <div v-if="unwatched.length > 0" class="border-line bg-surface-primary rounded-lg border p-4">
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
      </div>
    </div>
  </AdminLayout>
</template>
