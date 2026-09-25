<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface Run {
  id: string
  task: string
  taskLabel: string
  status: string
  statusLabel: string
  startedAt: string
  finishedAt: string | null
  durationSeconds: number | null
  examined: number
  changed: number
  skipped: number
  failed: number
  error: string | null
}

interface Task {
  value: string
  label: string
  description: string
  intervalMinutes: number
  command: string
  lastRun: Run | null
}

defineProps<{ tasks: Task[]; runs: Run[]; can: { run: boolean } }>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'task', label: t('automation.runs.task'), sticky: true },
  { key: 'started', label: t('automation.runs.started') },
  { key: 'took', label: t('automation.runs.duration'), numeric: true },
  { key: 'examined', label: t('automation.runs.examined'), numeric: true },
  { key: 'changed', label: t('automation.runs.changed'), numeric: true },
  { key: 'skipped', label: t('automation.runs.skipped'), numeric: true, optional: true },
  { key: 'failed', label: t('automation.runs.failed'), numeric: true },
  { key: 'actions', label: '' },
]

function run(task: string): void {
  router.post(`/admin/automation/${task}/run`, {}, { preserveScroll: true })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}

function cadence(minutes: number): string {
  return minutes >= 1440 ? 'Once a day' : `Every ${minutes} minutes`
}

// Three numbers on a row are noise. What an operator wants to know is
// whether it did anything, and if so how much.
function summarise(run: Run | null): string {
  if (run === null) return 'Never run'
  if (run.failed > 0) return `${run.changed} changed, ${run.failed} failed`
  if (run.changed === 0) return `Nothing to do (${run.examined} examined)`

  return `${run.changed} changed`
}
</script>

<template>
  <Head :title="t('automation.title')" />

  <AdminLayout :heading="t('automation.title')" :description="t('automation.description')">
    <div class="grid gap-4 md:grid-cols-2">
      <AppCard v-for="task in tasks" :key="task.value">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-body font-semibold">{{ task.label }}</h2>
            <p class="text-content-muted text-chrome mt-1 leading-relaxed">
              {{ task.description }}
            </p>
          </div>

          <AppStatus
            v-if="task.lastRun"
            :tone="statusTone(task.lastRun.status)"
            :label="task.lastRun.statusLabel"
          />
          <!-- Said plainly. A task that has never run is the failure an
               operator is least likely to notice on their own. -->
          <AppStatus v-else tone="warning" :label="t('automation.runs.never')" />
        </div>

        <dl class="text-content-muted text-chrome mt-4 flex flex-wrap gap-x-6 gap-y-1">
          <div>
            <dt class="inline">{{ t('automation.runs.cadence') }}</dt>
            <dd class="text-content ml-1 inline">{{ cadence(task.intervalMinutes) }}</dd>
          </div>
          <div>
            <dt class="inline">{{ t('automation.runs.last_run') }}</dt>
            <dd class="text-content ml-1 inline">
              {{ formatDateTime(task.lastRun?.startedAt ?? null) }}
            </dd>
          </div>
          <div>
            <dt class="inline">{{ t('automation.runs.result') }}</dt>
            <dd class="text-content ml-1 inline">{{ summarise(task.lastRun) }}</dd>
          </div>
        </dl>

        <div v-if="can.run" class="mt-4">
          <AppButton size="sm" @click="run(task.value)">{{
            t('automation.runs.run_now')
          }}</AppButton>
          <span class="text-content-subtle text-chrome ml-3 font-mono">{{ task.command }}</span>
        </div>
      </AppCard>
    </div>

    <h2 class="text-body mt-10 mb-3 font-semibold">{{ t('automation.runs.title') }}</h2>

    <AppTable v-if="runs.length > 0" name="automation-runs" :columns="COLUMNS">
      <AppTableRow v-for="item in runs" :key="item.id">
        <td data-col="task" class="font-medium">{{ item.taskLabel }}</td>
        <td data-col="started" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(item.startedAt) }}
        </td>
        <td data-col="took" class="text-content-muted tabular-nums">
          {{ item.durationSeconds === null ? '—' : `${item.durationSeconds}s` }}
        </td>
        <td data-col="examined" class="text-content-muted tabular-nums">{{ item.examined }}</td>
        <td data-col="changed" class="tabular-nums">{{ item.changed }}</td>
        <td data-col="skipped" class="text-content-muted tabular-nums">{{ item.skipped }}</td>
        <td
          data-col="failed"
          class="px-4 py-2.5 tabular-nums"
          :class="item.failed > 0 ? 'text-danger' : ''"
        >
          {{ item.failed }}
        </td>
        <td data-col="actions">
          <AppStatus :tone="statusTone(item.status)" :label="item.statusLabel" />
          <span v-if="item.error" class="text-content-muted text-chrome block max-w-[40ch]">
            {{ item.error }}
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="automation"
      :title="t('automation.runs.none')"
      :description="t('automation.runs.none_description')"
    />

    <p class="text-content-muted text-chrome mt-6">
      <Link href="/admin/automation/dunning" class="underline-offset-4 hover:underline">
        {{ t('automation.runs.dunning_link') }}
      </Link>
    </p>
  </AdminLayout>
</template>
