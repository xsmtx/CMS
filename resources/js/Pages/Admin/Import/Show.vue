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
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
  <Head title="Import report" />

  <AdminLayout
    heading="Import report"
    :description="`${run.modeLabel} from ${run.source}, ${formatDateTime(run.createdAt)}.`"
  >
    <div class="flex flex-col gap-5">
      <div class="flex flex-wrap items-center gap-3">
        <AppStatus :tone="tone" :label="run.statusLabel" />
        <span v-if="run.startedBy" class="text-content-muted text-chrome">
          started by {{ run.startedBy }}
        </span>
        <Link
          href="/admin/import"
          class="text-content-muted hover:text-content text-chrome ml-auto underline-offset-4 hover:underline"
        >
          Back to import
        </Link>
      </div>

      <!-- A run that could not proceed at all, which is a different thing from a
           run with failures in it. -->
      <AppAlert v-if="run.error" tone="danger">{{ run.error }}</AppAlert>

      <AppAlert v-else-if="run.mode === 'dry_run'" tone="info">
        This was a dry run: nothing was written and nothing was mapped. The numbers below are what a
        live run would do.
      </AppAlert>

      <AppCard
        title="By domain"
        description="What the previous system held, and what happened to it."
      >
        <AppTable
          :headers="['Domain', 'In the source', 'Imported', 'Already there', 'Not imported']"
          :numeric="[1, 2, 3, 4]"
        >
          <tr v-for="row in rows" :key="row.domain">
            <td class="px-4 py-2.5">{{ row.domain }}</td>
            <!-- The denominator. Without it the report cannot say whether
                 anything was missed. -->
            <td class="numeric text-content-muted px-4 py-2.5">{{ row.expected }}</td>
            <td class="numeric px-4 py-2.5">{{ row.created }}</td>
            <td class="numeric text-content-muted px-4 py-2.5">{{ row.skipped }}</td>
            <td class="numeric px-4 py-2.5" :class="row.failed > 0 ? 'text-danger' : ''">
              {{ row.failed }}
            </td>
          </tr>
        </AppTable>
      </AppCard>

      <AppCard
        v-if="failures.length > 0"
        title="What did not come across"
        description="Each of these is a row somebody has to decide about. Nothing was lost silently."
      >
        <AppTable :headers="['Domain', 'In the source', 'What it was', 'Why not']">
          <tr v-for="failure in failures" :key="failure.id">
            <td class="text-content-muted px-4 py-2.5">{{ failure.domain }}</td>
            <td class="px-4 py-2.5 font-mono text-xs">{{ failure.externalId }}</td>
            <!-- A name somebody recognises. "Client 4182" is not a customer they
                 can telephone about. -->
            <td class="px-4 py-2.5">{{ failure.label ?? '—' }}</td>
            <td class="text-danger px-4 py-2.5">{{ failure.message ?? '—' }}</td>
          </tr>
        </AppTable>

        <p class="text-content-subtle text-label mt-3">
          Fixing the cause and running the import again brings only these across: everything that
          already came over is skipped.
        </p>
      </AppCard>
    </div>
  </AdminLayout>
</template>
