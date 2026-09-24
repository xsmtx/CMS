<script setup lang="ts">
/**
 * Bringing a previous system across.
 *
 * The screen is the pipeline in order — what is connected, what is there, a dry
 * run, then the real thing — because that is the order an operator has to do it
 * in and a screen that let them skip a step would be a screen that let them skip
 * the dry run.
 *
 * **The dry run is the primary action and the live import is not.** They are the
 * same code path with one flag, so a dry run that reported cleanly is a real
 * promise about the live one; making it the obvious first press is the whole
 * value of having it.
 *
 * Problems with a connection are shown in full and all at once. Finding them one
 * attempt at a time is how a migration takes a week.
 */
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface Source {
  key: string
  label: string
  connection: string
  configured: boolean
  problems: string[]
  counts: Record<string, number> | null
}

interface Run {
  id: string
  source: string
  mode: string
  modeLabel: string
  status: string
  statusLabel: string
  domains: string[]
  created: number
  skipped: number
  failed: number
  startedBy: string | null
  startedAt: string | null
  finishedAt: string | null
  createdAt: string
}

const props = defineProps<{
  sources: Source[]
  domains: { value: string; label: string; requires: string[] }[]
  runs: Run[]
}>()

const source = computed(() => props.sources[0] ?? null)

const ready = computed(() => source.value !== null && source.value.problems.length === 0)

const form = useForm<{ source: string; mode: string; domains: string[] }>({
  source: props.sources[0]?.key ?? '',
  mode: 'dry_run',
  // Everything, in dependency order. An operator who wants a subset unticks;
  // one who wants all of it presses once.
  domains: props.domains.map((domain) => domain.value),
})

function submit(mode: 'dry_run' | 'live'): void {
  form.mode = mode
  form.post('/admin/import', { preserveScroll: true })
}

function toneOf(status: string): StatusTone {
  if (status === 'failed') return 'critical'
  if (status === 'completed') return 'healthy'
  if (status === 'running') return 'info'

  return 'unknown'
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Import" />

  <AdminLayout
    heading="Import"
    description="Bring a previous system across. Nothing is written until you ask for a live run."
  >
    <div class="flex flex-col gap-5">
      <AppCard
        v-if="source"
        :title="`From ${source.label}`"
        :description="`Read over the [${source.connection}] database connection. The importer issues nothing but select.`"
      >
        <!-- All of them at once. Finding them one attempt at a time is how a
             migration takes a week. -->
        <AppAlert v-if="source.problems.length > 0" tone="warning">
          <p class="font-medium">This source is not ready yet:</p>
          <ul class="mt-1.5 list-inside list-disc">
            <li v-for="problem in source.problems" :key="problem">{{ problem }}</li>
          </ul>
        </AppAlert>

        <div v-else class="flex flex-col gap-4">
          <div>
            <p class="text-content-subtle text-label mb-2 uppercase">What to bring across</p>

            <ul class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-4">
              <li v-for="domain in domains" :key="domain.value">
                <label
                  class="hover:bg-surface-hover flex items-center gap-2.5 rounded-sm px-2 py-1.5"
                >
                  <input
                    v-model="form.domains"
                    type="checkbox"
                    :value="domain.value"
                    class="border-line-strong accent-brand size-3.5 rounded-[3px] border"
                  />
                  <span class="text-body flex-1">{{ domain.label }}</span>
                  <span class="text-content-subtle text-label tabular-nums">
                    {{ source.counts?.[domain.value] ?? 0 }}
                  </span>
                </label>
              </li>
            </ul>

            <p v-if="form.errors.domains" class="text-danger text-chrome mt-2">
              {{ form.errors.domains }}
            </p>
          </div>

          <!--
            The dry run is the primary action. They are the same code path with
            one flag, so a clean dry run is a real promise about the live one —
            and making it the obvious first press is the whole value of having
            it.
          -->
          <div class="flex flex-wrap items-center gap-2">
            <AppButton variant="primary" :loading="form.processing" @click="submit('dry_run')">
              Dry run
            </AppButton>
            <AppButton variant="secondary" :loading="form.processing" @click="submit('live')">
              Import for real
            </AppButton>
          </div>

          <p class="text-content-subtle text-label">
            A dry run reads everything and writes nothing. A live run can be repeated safely: rows
            that already came across are skipped rather than duplicated.
          </p>
        </div>
      </AppCard>

      <EmptyState
        v-else
        icon="database"
        title="No import source is configured"
        description="Set IMPORT_WHMCS_CONNECTION to a read-only database connection holding the previous system."
      />

      <AppCard title="Runs" description="Every attempt, dry or live, with what it did.">
        <AppTable
          v-if="runs.length > 0"
          :headers="['When', 'Mode', 'State', 'Imported', 'Skipped', 'Not imported', 'Who', '']"
          :numeric="[3, 4, 5]"
        >
          <tr v-for="run in runs" :key="run.id">
            <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
              {{ formatDateTime(run.createdAt) }}
            </td>
            <td class="px-4 py-2.5">{{ run.modeLabel }}</td>
            <td class="px-4 py-2.5">
              <AppStatus :tone="toneOf(run.status)" :label="run.statusLabel" compact />
            </td>
            <td class="numeric px-4 py-2.5">{{ run.created }}</td>
            <td class="numeric px-4 py-2.5">{{ run.skipped }}</td>
            <!-- The number an operator opens the report for. -->
            <td class="numeric px-4 py-2.5" :class="run.failed > 0 ? 'text-danger' : ''">
              {{ run.failed }}
            </td>
            <td class="text-content-muted px-4 py-2.5">{{ run.startedBy ?? '—' }}</td>
            <td class="px-4 py-2.5 text-right">
              <Link
                :href="`/admin/import/${run.id}`"
                class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
              >
                Report
              </Link>
            </td>
          </tr>
        </AppTable>

        <p v-else class="text-content-muted text-body">Nothing has been imported yet.</p>
      </AppCard>

      <AppAlert v-if="ready" tone="info">
        An import is a copy of history. It writes rows and sends nothing: no customer is emailed, no
        service is provisioned, and no invoice is renumbered. Imported products arrive
        <strong>unpriced and hidden</strong>, because a legacy price of zero means free here — price
        them before you sell them.
      </AppAlert>
    </div>
  </AdminLayout>
</template>
