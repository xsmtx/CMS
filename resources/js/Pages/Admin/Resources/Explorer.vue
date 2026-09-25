<script setup lang="ts">
/**
 * The Explorer: everything this installation knows about, and how it hangs
 * together.
 *
 * A list with a drawer rather than a diagram, and that is the decision worth
 * defending. A topology picture is the thing everyone asks for and the thing
 * nobody can read at four hundred nodes; what an operator actually does is find
 * one resource and ask three questions about it — what does it sit on, what is on
 * it, and who notices if it stops. A list answers the finding and the drawer
 * answers the three, and the diagram can arrive in Phase C on top of the same
 * data.
 *
 * **The impact figure is the point of the whole screen.** Every monitoring system
 * can say a server is unwell. This is the only place that can say the server
 * carries forty services for nine customers and 4,200 EUR a month, because it is
 * the only place that has the billing database.
 *
 * Money is a list of figures, one per currency, and never a total. There is no
 * exchange rate anywhere in this product and inventing one here would put a
 * number on the page that means nothing.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppDrawer from '../../../Components/AppDrawer.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppStat from '../../../Components/AppStat.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface NodeRow {
  id: string
  kind: string
  kindLabel: string
  key: string
  label: string
  source: string
  health: string
  healthLabel: string
  healthMessage: string | null
  attributes: Record<string, unknown>
  lastSeenAt: string | null
  retiredAt: string | null
  subject: { href: string } | null
}

interface TreeRow {
  node: NodeRow
  depth: number
  relation: string | null
  relationLabel: string | null
  parentId: string | null
}

interface MetricRow {
  id: string
  metric: string
  metricLabel: string
  unit: string | null
  unitLabel: string
  value: number
  higherIsWorse: boolean | null
  sampledAt: string
  stale: boolean
  source: string
}

interface HistoryRowShape {
  other: NodeRow
  relation: string
  relationLabel: string
  nodeIsContainer: boolean
  observedAt: string
  endedAt: string | null
  open: boolean
  days: number | null
}

interface MoneyRow {
  currency: string
  amount: string
  minor: number
}

interface Peek {
  node: NodeRow
  above: TreeRow[]
  below: TreeRow[]
  impact: {
    services: number
    customers: number
    recurring: MoneyRow[]
    nodes_by_kind: Record<string, number>
    depth: number
    truncated: boolean
  }
  history: HistoryRowShape[]
  metrics: MetricRow[]
}

const props = defineProps<{
  nodes: {
    links: PaginationLink[]
    data: NodeRow[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { kind: string | null; health: string | null; q: string | null; retired: boolean }
  kinds: { value: string; label: string }[]
  healthStates: { value: string; label: string }[]
  stats: { nodes: number; unwatched: number; retired: number }
  peek?: Peek | null
}>()

const { t } = useTranslations()

const search = ref(props.filters.q ?? '')
const openId = ref<string | null>(null)
const drawerOpen = ref(false)
const loadingPeek = ref(false)

/** The filters, as the query string the server reads. */
function go(changes: Record<string, string | number | boolean | null>): void {
  router.get(
    '/admin/resources',
    {
      kind: props.filters.kind,
      health: props.filters.health,
      q: search.value === '' ? null : search.value,
      retired: props.filters.retired ? 1 : null,
      ...changes,
    },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

/**
 * The drawer's record is a named prop on this same route.
 *
 * `only: ['peek']` builds one resource's context; without it, asking for the
 * drawer would re-run the list, the counts and the filters for a panel that shows
 * none of them.
 */
function open(node: NodeRow): void {
  openId.value = node.id
  drawerOpen.value = true
  loadingPeek.value = true

  router.reload({
    only: ['peek'],
    data: { node: node.id },
    onFinish: () => (loadingPeek.value = false),
  })
}

watch(drawerOpen, (isOpen) => {
  if (!isOpen) openId.value = null
})

const peek = computed<Peek | null>(() => props.peek ?? null)

const columns = [
  { key: 'label', label: t('infrastructure.explorer.columns.label') },
  { key: 'kind', label: t('infrastructure.explorer.columns.kind') },
  { key: 'health', label: t('infrastructure.explorer.columns.health') },
  { key: 'source', label: t('infrastructure.explorer.columns.source'), optional: true },
  { key: 'seen', label: t('infrastructure.explorer.columns.seen'), optional: true },
]

/**
 * A node's state as a status tone.
 *
 * `unknown` is its own tone rather than a shade of warning: "nothing is reporting
 * on this" and "this is unwell" are the two answers an operator most needs to tell
 * apart, and a screen that coloured them the same would teach them to ignore both.
 */
const TONES: Record<string, StatusTone> = {
  ok: 'healthy',
  degraded: 'warning',
  failing: 'critical',
}

function toneOf(node: NodeRow): StatusTone {
  // Retired is maintenance rather than unknown: "we took it out on purpose" and
  // "nothing is telling us" are the two things an operator most needs to tell
  // apart from "it is broken".
  if (node.retiredAt !== null) return 'maintenance'

  return TONES[node.health] ?? 'unknown'
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}

/** A measurement, in the unit it is stored in. */
function reading(metric: MetricRow): string {
  if (metric.unit === 'ratio') return `${(metric.value * 100).toFixed(1)}%`
  if (metric.unit === 'bytes') return bytes(metric.value)
  if (metric.unit === 'bits_per_second') return `${bits(metric.value)}`
  if (metric.unit === 'seconds') return duration(metric.value)

  const rounded = Math.abs(metric.value) >= 100 ? Math.round(metric.value) : metric.value
  return `${rounded}${metric.unitLabel === '' ? '' : ' ' + metric.unitLabel}`
}

function bytes(value: number): string {
  const units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB']
  let index = 0
  let scaled = value

  while (scaled >= 1000 && index < units.length - 1) {
    scaled /= 1000
    index++
  }

  return `${scaled.toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

function bits(value: number): string {
  const units = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s']
  let index = 0
  let scaled = value

  while (scaled >= 1000 && index < units.length - 1) {
    scaled /= 1000
    index++
  }

  return `${scaled.toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

function duration(seconds: number): string {
  if (seconds < 60) return `${Math.round(seconds)}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h`

  return `${Math.round(seconds / 86400)}d`
}
</script>

<template>
  <Head title="Infrastructure" />

  <AdminLayout
    :heading="t('infrastructure.explorer.title')"
    :description="t('infrastructure.explorer.intro')"
  >
    <div class="flex flex-col gap-5">
      <div class="flex flex-wrap gap-2">
        <AppStat
          :label="t('infrastructure.explorer.stats.nodes')"
          :value="stats.nodes"
          :active="!filters.retired"
          @select="go({ retired: null })"
        />
        <AppStat
          :label="t('infrastructure.explorer.stats.unwatched')"
          :value="stats.unwatched"
          :tone="stats.unwatched > 0 ? 'warning' : 'neutral'"
          :active="filters.health === 'unknown'"
          @select="go({ health: filters.health === 'unknown' ? null : 'unknown' })"
        />
        <AppStat
          :label="t('infrastructure.explorer.stats.retired')"
          :value="stats.retired"
          :active="filters.retired"
          @select="go({ retired: filters.retired ? null : 1 })"
        />
      </div>

      <EmptyState
        v-if="nodes.data.length === 0"
        :title="t('infrastructure.explorer.title')"
        :description="t('infrastructure.explorer.empty')"
        icon="servers"
      />

      <AppTable v-else name="resources" :columns="columns">
        <template #toolbar>
          <form class="flex flex-wrap items-end gap-2" @submit.prevent="go({})">
            <AppInput v-model="search" :label="t('infrastructure.explorer.search')" type="search" />
            <AppSelect
              :model-value="filters.kind ?? ''"
              :label="t('infrastructure.explorer.columns.kind')"
              :options="[{ value: '', label: t('infrastructure.explorer.all_kinds') }, ...kinds]"
              @update:model-value="(value) => go({ kind: value === '' ? null : value })"
            />
            <AppSelect
              :model-value="filters.health ?? ''"
              :label="t('infrastructure.explorer.columns.health')"
              :options="[
                { value: '', label: t('infrastructure.explorer.all_health') },
                ...healthStates,
              ]"
              @update:model-value="(value) => go({ health: value === '' ? null : value })"
            />
          </form>
        </template>

        <AppTableRow v-for="node in nodes.data" :key="node.id">
          <td data-col="label">
            <!-- The row whose drawer is open is marked, so somebody who has
                 scrolled the list can still see where they are. -->
            <button
              type="button"
              class="text-left hover:underline"
              :class="openId === node.id ? 'text-brand font-semibold' : 'font-medium'"
              @click="open(node)"
            >
              {{ node.label }}
            </button>
            <span class="text-content-subtle text-chrome block font-mono">{{ node.key }}</span>
          </td>
          <td data-col="kind">
            <AppBadge tone="neutral">{{ node.kindLabel }}</AppBadge>
          </td>
          <td data-col="health">
            <AppStatus :tone="toneOf(node)" :label="node.healthLabel" />
          </td>
          <td data-col="source" class="text-content-muted text-chrome font-mono">
            {{ node.source }}
          </td>
          <td data-col="seen" class="text-content-muted text-chrome tabular-nums">
            {{ when(node.lastSeenAt) }}
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="nodes.links" :total="nodes.total" />
    </div>

    <AppDrawer
      v-model:open="drawerOpen"
      :title="peek?.node.label ?? '…'"
      :subtitle="peek?.node.kindLabel"
      :href="peek?.node.subject?.href"
      :loading="loadingPeek && peek === null"
    >
      <div v-if="peek" class="flex flex-col gap-5">
        <!-- Impact first. It is the question the graph exists to answer, and
             putting it under three lists would bury it. -->
        <section class="flex flex-col gap-2">
          <h3 class="text-content-subtle text-label uppercase">
            {{ t('infrastructure.explorer.drawer.impact') }}
          </h3>
          <p class="text-body">
            <span class="font-semibold tabular-nums">{{ peek.impact.services }}</span>
            {{ t('infrastructure.explorer.drawer.services') }},
            <span class="font-semibold tabular-nums">{{ peek.impact.customers }}</span>
            {{ t('infrastructure.explorer.drawer.customers') }}
          </p>
          <p
            v-for="row in peek.impact.recurring"
            :key="row.currency"
            class="text-body font-semibold tabular-nums"
          >
            {{ row.amount }}
          </p>
          <p v-if="peek.impact.truncated" class="text-content-muted text-chrome">
            {{
              t('infrastructure.explorer.drawer.truncated').replace(
                ':depth',
                String(peek.impact.depth),
              )
            }}
          </p>
        </section>

        <section v-if="peek.above.length > 0" class="flex flex-col gap-2">
          <h3 class="text-content-subtle text-label uppercase">
            {{ t('infrastructure.explorer.drawer.sits_on') }}
          </h3>
          <ul class="flex flex-col gap-1">
            <li v-for="row in peek.above" :key="row.node.id" class="text-body flex gap-2">
              <span class="text-content-subtle text-chrome">{{ row.relationLabel }}</span>
              <span>{{ row.node.label }}</span>
              <span class="text-content-subtle text-chrome">{{ row.node.kindLabel }}</span>
            </li>
          </ul>
        </section>

        <section v-if="peek.below.length > 0" class="flex flex-col gap-2">
          <h3 class="text-content-subtle text-label uppercase">
            {{ t('infrastructure.explorer.drawer.contains') }}
          </h3>
          <ul class="flex flex-col gap-1">
            <li
              v-for="row in peek.below"
              :key="row.node.id"
              class="text-body flex gap-2"
              :style="{ paddingLeft: `${(row.depth - 1) * 0.75}rem` }"
            >
              <AppStatus :tone="toneOf(row.node)" :label="row.node.healthLabel" compact />
              <span>{{ row.node.label }}</span>
              <span class="text-content-subtle text-chrome">{{ row.node.kindLabel }}</span>
            </li>
          </ul>
        </section>

        <section class="flex flex-col gap-2">
          <h3 class="text-content-subtle text-label uppercase">
            {{ t('infrastructure.explorer.drawer.metrics') }}
          </h3>
          <p v-if="peek.metrics.length === 0" class="text-content-muted text-body">
            {{ t('infrastructure.explorer.drawer.no_metrics') }}
          </p>
          <dl v-else class="grid grid-cols-2 gap-x-4 gap-y-1">
            <template v-for="metric in peek.metrics" :key="metric.id">
              <dt class="text-content-muted text-body">{{ metric.metricLabel }}</dt>
              <dd class="text-body text-right font-medium tabular-nums">
                {{ reading(metric) }}
                <span v-if="metric.stale" class="text-warning text-chrome">
                  · {{ t('infrastructure.telemetry.stale') }}
                </span>
              </dd>
            </template>
          </dl>
        </section>

        <section class="flex flex-col gap-2">
          <h3 class="text-content-subtle text-label uppercase">
            {{ t('infrastructure.explorer.drawer.history') }}
          </h3>
          <p v-if="peek.history.length === 0" class="text-content-muted text-body">
            {{ t('infrastructure.explorer.drawer.no_history') }}
          </p>
          <ul v-else class="flex flex-col gap-1">
            <li
              v-for="(row, index) in peek.history"
              :key="index"
              class="text-body flex flex-wrap gap-2"
            >
              <span class="text-content-subtle text-chrome">{{ row.relationLabel }}</span>
              <span>{{ row.other.label }}</span>
              <span class="text-content-muted text-chrome tabular-nums">
                {{ when(row.observedAt) }}
                <template v-if="row.endedAt"> → {{ when(row.endedAt) }}</template>
              </span>
            </li>
          </ul>
        </section>

        <Link
          v-if="peek.node.subject"
          :href="peek.node.subject.href"
          class="text-brand text-body hover:underline"
        >
          {{ t('infrastructure.explorer.drawer.open_subject') }}
        </Link>
      </div>
    </AppDrawer>
  </AdminLayout>
</template>
