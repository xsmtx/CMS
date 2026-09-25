<script setup lang="ts">
/**
 * One run's report.
 *
 * **The failures are the report.** Successes are a number; the rows that did not
 * come across are work, and an operator needs each of them by name with a reason
 * they can act on. A report that showed only totals would tell them how many
 * customers they lost and nothing about which.
 *
 * The per-domain table shows the source's own count beside what happened, so
 * "4,182 of 4,190" is readable. Without the denominator a report cannot say
 * whether anything was missed.
 */
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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
  expected: Record<string, number>
  totals: Record<string, Record<string, number>>
  error: string | null
}

const props = defineProps<{
  run: Run
  failures: {
    id: string
    domain: string
    externalId: string
    label: string | null
    message: string | null
  }[]
}>()

/** One row per domain that was asked for, whether or not it produced anything. */
const rows = computed(() =>
  props.run.domains.map((domain) => ({
    domain,
    expected: props.run.expected[domain] ?? 0,
    created: props.run.totals[domain]?.created ?? 0,
    skipped: props.run.totals[domain]?.skipped ?? 0,
    failed: props.run.totals[domain]?.failed ?? 0,
  })),
)

const { t } = useTranslations()

const DOMAIN_COLUMNS: TableColumn[] = [
  { key: 'domain', label: t('ui.import.domain') },
  { key: 'expected', label: t('ui.import.in_source'), numeric: true },
  { key: 'created', label: t('ui.import.imported'), numeric: true },
  { key: 'skipped', label: t('ui.import.already_there'), numeric: true },
  { key: 'failed', label: t('ui.import.not_imported'), numeric: true },
]

const FAILURE_COLUMNS: TableColumn[] = [
  { key: 'domain', label: t('ui.import.domain') },
  { key: 'external', label: t('ui.import.in_source') },
  { key: 'label', label: t('ui.import.what_it_was') },
  { key: 'why', label: t('ui.import.why_not') },
]

const tone = computed<StatusTone>(() => {
  if (props.run.status === 'failed') return 'critical'
  if (props.run.status === 'completed') return props.run.failed > 0 ? 'warning' : 'healthy'
  if (props.run.status === 'running') return 'info'

  return 'unknown'
})

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('ui.import.report_title')" />

  <AdminLayout :heading="t('ui.import.report_title')">
    <template #header>
      <PageHeader :title="t('ui.import.report_title')">
        <template #status>
          <AppStatus :tone="tone" :label="run.statusLabel" />
        </template>

        <template #meta>
          <span>{{ run.modeLabel }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ run.source }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ formatDateTime(run.createdAt) }}</span>
          <template v-if="run.startedBy">
            <span aria-hidden="true">·</span>
            <span>{{ t('ui.import.started_by', { name: run.startedBy }) }}</span>
          </template>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <!-- A run that could not proceed at all, which is a different thing from a
           run with failures in it. -->
      <AppAlert v-if="run.error" tone="danger">{{ run.error }}</AppAlert>

      <AppAlert v-else-if="run.mode === 'dry_run'" tone="info">
        {{ t('ui.import.was_dry_run') }}
      </AppAlert>

      <DetailSection
        :title="t('ui.import.by_domain')"
        :description="t('ui.import.by_domain_intro')"
        :divided="false"
      >
        <AppTable name="import-domains" :columns="DOMAIN_COLUMNS">
          <AppTableRow v-for="row in rows" :key="row.domain">
            <td data-col="domain">{{ row.domain }}</td>
            <!-- The denominator. Without it the report cannot say whether
                 anything was missed. -->
            <td data-col="expected" class="numeric text-content-muted">{{ row.expected }}</td>
            <td data-col="created" class="numeric">{{ row.created }}</td>
            <td data-col="skipped" class="numeric text-content-muted">{{ row.skipped }}</td>
            <td data-col="failed" class="numeric" :class="row.failed > 0 ? 'text-danger' : ''">
              {{ row.failed }}
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <DetailSection
        v-if="failures.length > 0"
        :title="t('ui.import.failures')"
        :description="t('ui.import.failures_intro')"
        :divided="false"
      >
        <AppTable name="import-failures" :columns="FAILURE_COLUMNS">
          <AppTableRow v-for="failure in failures" :key="failure.id">
            <td data-col="domain" class="text-content-muted">{{ failure.domain }}</td>
            <td data-col="external" class="text-chrome font-mono">{{ failure.externalId }}</td>
            <!-- A name somebody recognises. "Client 4182" is not a customer they
                 can telephone about. -->
            <td data-col="label">{{ failure.label ?? '—' }}</td>
            <td data-col="why" class="text-danger">{{ failure.message ?? '—' }}</td>
          </AppTableRow>
        </AppTable>

        <p class="text-content-subtle text-chrome mt-3 leading-relaxed">
          {{ t('ui.import.rerun') }}
        </p>
      </DetailSection>
    </div>
  </AdminLayout>
</template>
