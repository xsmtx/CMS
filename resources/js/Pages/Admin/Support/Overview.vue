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
import AppCard from '../../../Components/AppCard.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStat from '../../../Components/AppStat.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
</script>

<template>
  <Head title="Support overview" />

  <AdminLayout
    heading="Support Overview"
    :description="`${formatDate(statistics.from)} to ${formatDate(statistics.to)}`"
  >
    <form class="mb-7 max-w-xs" @submit.prevent="apply">
      <AppSelect v-model="period" label="Period" :options="periods" @update:model-value="apply" />
    </form>

    <div class="mb-7 flex flex-wrap gap-3">
      <AppStat label="Opened" :value="statistics.opened" />
      <AppStat label="Resolved" :value="statistics.resolved" tone="success" />
      <AppStat label="Replies sent" :value="statistics.replies" />
      <AppStat label="Median first reply" :value="firstResponse" />
      <!-- Now, not in the period: "how many are waiting" has no meaning
           inside last month. -->
      <AppStat
        label="Awaiting us"
        :value="statistics.awaiting"
        :tone="statistics.awaiting > 0 ? 'warning' : 'neutral'"
      />
      <AppStat
        label="Past its promise"
        :value="statistics.breaching"
        :tone="statistics.breaching > 0 ? 'danger' : 'neutral'"
      />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
      <AppCard
        title="Opened per day"
        description="Every day in the period, including the quiet ones."
      >
        <AppBarChart title="Tickets opened" :rows="statistics.daily" unit="tickets" />
      </AppCard>

      <AppCard
        title="Where everything is"
        description="Every ticket on the installation, by status."
      >
        <AppBarChart title="By status" :rows="statistics.byStatus" horizontal unit="tickets" />
      </AppCard>

      <AppCard
        class="lg:col-span-2"
        title="Which queue"
        description="Opened in this period, by the department it landed in."
      >
        <AppBarChart
          title="By department"
          :rows="statistics.byDepartment"
          horizontal
          unit="tickets"
        />
      </AppCard>
    </div>
  </AdminLayout>
</template>
