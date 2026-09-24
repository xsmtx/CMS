<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
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
  <Head title="Automation" />

  <AdminLayout
    heading="Automation"
    description="What this platform does on its own, and what it did last time."
  >
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
          <AppStatus v-else tone="warning" label="Never run" />
        </div>

        <dl class="text-content-muted text-chrome mt-4 flex flex-wrap gap-x-6 gap-y-1">
          <div>
            <dt class="inline">Runs</dt>
            <dd class="text-content ml-1 inline">{{ cadence(task.intervalMinutes) }}</dd>
          </div>
          <div>
            <dt class="inline">Last</dt>
            <dd class="text-content ml-1 inline">
              {{ formatDateTime(task.lastRun?.startedAt ?? null) }}
            </dd>
          </div>
          <div>
            <dt class="inline">Result</dt>
            <dd class="text-content ml-1 inline">{{ summarise(task.lastRun) }}</dd>
          </div>
        </dl>

        <div v-if="can.run" class="mt-4">
          <AppButton size="sm" @click="run(task.value)">Run now</AppButton>
          <span class="text-content-subtle text-chrome ml-3 font-mono">{{ task.command }}</span>
        </div>
      </AppCard>
    </div>

    <h2 class="text-body mt-10 mb-3 font-semibold">Run history</h2>

    <AppTable
      v-if="runs.length > 0"
      :headers="['Task', 'Started', 'Took', 'Examined', 'Changed', 'Skipped', 'Failed', '']"
    >
      <tr v-for="item in runs" :key="item.id">
        <td class="px-4 py-2.5 font-medium">{{ item.taskLabel }}</td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(item.startedAt) }}
        </td>
        <td class="text-content-muted px-4 py-2.5 tabular-nums">
          {{ item.durationSeconds === null ? '—' : `${item.durationSeconds}s` }}
        </td>
        <td class="text-content-muted px-4 py-2.5 tabular-nums">{{ item.examined }}</td>
        <td class="px-4 py-2.5 tabular-nums">{{ item.changed }}</td>
        <td class="text-content-muted px-4 py-2.5 tabular-nums">{{ item.skipped }}</td>
        <td class="px-4 py-2.5 tabular-nums" :class="item.failed > 0 ? 'text-danger' : ''">
          {{ item.failed }}
        </td>
        <td class="px-4 py-2.5">
          <AppStatus :tone="statusTone(item.status)" :label="item.statusLabel" />
          <span v-if="item.error" class="text-content-muted text-chrome block max-w-[40ch]">
            {{ item.error }}
          </span>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing has run yet"
      description="Tasks run on a schedule. You can also run one now and watch what it does."
    />

    <p class="text-content-muted text-chrome mt-6">
      <Link href="/admin/automation/dunning" class="underline-offset-4 hover:underline">
        Unpaid invoice sequence
      </Link>
    </p>
  </AdminLayout>
</template>
