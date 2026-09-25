<script setup lang="ts">
/**
 * How the desk has been going, over a period somebody chooses.
 *
 * Its own screen rather than a panel on the queue: a queue is what to do
 * next and an overview is how it has been going, and mixing them makes an
 * operator scroll past a chart to reach their work.
 *
 * First response is the median. One ticket answered after a fortnight
 * because the customer went on holiday drags an average into a number that
 * describes nothing.
 */
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppBarChart from '../../../Components/AppBarChart.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface Slice {
  label: string
  value: number
}

const props = defineProps<{
  statistics: {
    period: string
    from: string
    to: string
    opened: number
    resolved: number
    replies: number
    awaiting: number
    breaching: number
    medianFirstResponseMinutes: number | null
    byStatus: Slice[]
    byDepartment: Slice[]
    daily: Slice[]
  }
  periods: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const period = ref(props.statistics.period)

function apply(): void {
  router.get(
    '/admin/support/overview',
    { period: period.value },
    {
      preserveState: true,
      replace: true,
    },
  )
}

/**
 * Minutes are how it is measured and not how anybody says it.
 */
const firstResponse = computed(() => {
  const minutes = props.statistics.medianFirstResponseMinutes

  if (minutes === null) return '—'
  if (minutes < 60) return `${minutes} min`
  if (minutes < 60 * 24) return `${Math.round(minutes / 60)} h`

  return `${Math.round(minutes / (60 * 24))} d`
})

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString()
}

/**
 * Six figures in one strip, not six boxes.
 *
 * `AppStat` is the pressable figure that filters a list; none of these filter
 * anything, so a row of them was a row of KPI cards by another name. Two are
 * *now* rather than in the period — how many are waiting has no meaning
 * inside last month — and they carry a tone because waiting and breaching are
 * states, while a count of replies sent is not.
 */
const figures = computed<Metric[]>(() => [
  { key: 'opened', label: t('ui.support_overview.opened'), value: props.statistics.opened },
  { key: 'resolved', label: t('ui.support_overview.resolved'), value: props.statistics.resolved },
  { key: 'replies', label: t('ui.support_overview.replies'), value: props.statistics.replies },
  {
    key: 'median',
    label: t('ui.support_overview.median'),
    value: firstResponse.value,
    hint: t('ui.support_overview.median_hint'),
  },
  {
    key: 'awaiting',
    label: t('ui.support_overview.awaiting'),
    value: props.statistics.awaiting,
    hint: t('ui.support_overview.now'),
    tone: props.statistics.awaiting > 0 ? 'warning' : undefined,
  },
  {
    key: 'breaching',
    label: t('ui.support_overview.breaching'),
    value: props.statistics.breaching,
    hint: t('ui.support_overview.now'),
    tone: props.statistics.breaching > 0 ? 'critical' : undefined,
  },
])
</script>

<template>
  <Head :title="t('ui.support_overview.title')" />

  <AdminLayout :heading="t('ui.support_overview.title')">
    <template #header>
      <PageHeader :title="t('ui.support_overview.title')">
        <template #meta>
          <span>
            {{
              t('ui.support_overview.range', {
                from: formatDate(statistics.from),
                to: formatDate(statistics.to),
              })
            }}
          </span>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <form class="max-w-xs" @submit.prevent="apply">
        <AppSelect
          v-model="period"
          :label="t('ui.support_overview.period')"
          :options="periods"
          @update:model-value="apply"
        />
      </form>

      <MetricStrip :items="figures" />

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-2">
        <DetailSection
          :title="t('ui.support_overview.per_day')"
          :description="t('ui.support_overview.per_day_intro')"
        >
          <AppBarChart
            :title="t('ui.support_overview.per_day')"
            :rows="statistics.daily"
            :unit="t('ui.support_overview.tickets')"
            hide-title
          />
        </DetailSection>

        <DetailSection
          :title="t('ui.support_overview.where')"
          :description="t('ui.support_overview.where_intro')"
        >
          <AppBarChart
            :title="t('ui.support_overview.where')"
            :rows="statistics.byStatus"
            horizontal
            :unit="t('ui.support_overview.tickets')"
            hide-title
          />
        </DetailSection>

        <DetailSection
          class="lg:col-span-2"
          :title="t('ui.support_overview.which_queue')"
          :description="t('ui.support_overview.which_queue_intro')"
        >
          <AppBarChart
            :title="t('ui.support_overview.which_queue')"
            :rows="statistics.byDepartment"
            horizontal
            :unit="t('ui.support_overview.tickets')"
            hide-title
          />
        </DetailSection>
      </div>
    </div>
  </AdminLayout>
</template>
