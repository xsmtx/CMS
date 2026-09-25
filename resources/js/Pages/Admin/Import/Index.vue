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
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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

const { t } = useTranslations()

const RUN_COLUMNS: TableColumn[] = [
  { key: 'when', label: t('ui.import.when') },
  { key: 'mode', label: t('ui.import.mode') },
  { key: 'state', label: t('ui.import.state') },
  { key: 'imported', label: t('ui.import.imported'), numeric: true },
  { key: 'skipped', label: t('ui.import.skipped'), numeric: true },
  { key: 'failed', label: t('ui.import.not_imported'), numeric: true },
  { key: 'who', label: t('ui.import.who'), optional: true },
  { key: 'report', label: '' },
]

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
  <Head :title="t('ui.import.title')" />

  <AdminLayout :heading="t('ui.import.title')">
    <template #header>
      <PageHeader :title="t('ui.import.title')" :description="t('ui.import.intro')" />
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="source"
        :title="t('ui.import.from', { source: source.label })"
        :description="t('ui.import.from_intro', { connection: source.connection })"
      >
        <!-- All of them at once. Finding them one attempt at a time is how a
             migration takes a week. -->
        <AppAlert v-if="source.problems.length > 0" tone="warning">
          <p class="font-medium">{{ t('ui.import.not_ready') }}</p>
          <ul class="mt-1.5 list-inside list-disc">
            <li v-for="problem in source.problems" :key="problem">{{ problem }}</li>
          </ul>
        </AppAlert>

        <div v-else class="flex flex-col gap-4">
          <div>
            <p class="text-content-subtle text-label mb-2 uppercase">
              {{ t('ui.import.what') }}
            </p>

            <ul class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-4">
              <li v-for="domain in domains" :key="domain.value">
                <label
                  class="hover:bg-surface-hover flex items-center gap-2.5 rounded-sm px-2 py-1.5 transition-colors duration-(--duration-fast)"
                >
                  <input
                    v-model="form.domains"
                    type="checkbox"
                    :value="domain.value"
                    class="border-line-strong accent-brand size-3.5 rounded-sm border"
                  />
                  <span class="text-body flex-1">{{ domain.label }}</span>
                  <span class="text-content-subtle text-label tabular-nums">
                    {{ source.counts?.[domain.value] ?? 0 }}
                  </span>
                </label>
              </li>
            </ul>

            <p v-if="form.errors.domains" class="text-danger text-chrome mt-2" role="alert">
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
              {{ t('ui.import.dry_run') }}
            </AppButton>
            <AppButton variant="secondary" :loading="form.processing" @click="submit('live')">
              {{ t('ui.import.live') }}
            </AppButton>
          </div>

          <p class="text-content-subtle text-chrome leading-relaxed">
            {{ t('ui.import.repeatable') }}
          </p>
        </div>
      </DetailSection>

      <EmptyState
        v-else
        icon="database"
        :title="t('ui.import.no_source')"
        :description="t('ui.import.no_source_detail', { name: 'IMPORT_WHMCS_CONNECTION' })"
      />

      <DetailSection
        :title="t('ui.import.runs')"
        :description="t('ui.import.runs_intro')"
        :divided="runs.length === 0"
      >
        <AppTable v-if="runs.length > 0" name="import-runs" :columns="RUN_COLUMNS">
          <AppTableRow v-for="run in runs" :key="run.id">
            <td data-col="when" class="text-content-muted whitespace-nowrap">
              {{ formatDateTime(run.createdAt) }}
            </td>
            <td data-col="mode">{{ run.modeLabel }}</td>
            <td data-col="state">
              <AppStatus :tone="toneOf(run.status)" :label="run.statusLabel" />
            </td>
            <td data-col="imported" class="numeric">{{ run.created }}</td>
            <td data-col="skipped" class="numeric">{{ run.skipped }}</td>
            <!-- The number an operator opens the report for. -->
            <td data-col="failed" class="numeric" :class="run.failed > 0 ? 'text-danger' : ''">
              {{ run.failed }}
            </td>
            <td data-col="who" class="text-content-muted">{{ run.startedBy ?? '—' }}</td>
            <td data-col="report" class="text-right">
              <Link
                :href="`/admin/import/${run.id}`"
                class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
              >
                {{ t('ui.import.report') }}
              </Link>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          variant="plain"
          icon="history"
          :title="t('ui.import.no_runs')"
          :description="t('ui.import.no_runs_detail')"
        />
      </DetailSection>

      <AppAlert v-if="ready" tone="info">
        {{ t('ui.import.note') }}
      </AppAlert>
    </div>
  </AdminLayout>
</template>
