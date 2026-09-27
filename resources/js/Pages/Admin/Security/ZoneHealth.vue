<script setup lang="ts">
/**
 * What is wrong with the DNS of the domains this installation holds (§8).
 *
 * **Warnings first, then information.** The two that are objectively broken —
 * two SPF records, `+all`, one nameserver — are what somebody should act on
 * today, and a list sorted by domain would bury them among the DMARC records
 * everybody is still rolling out.
 *
 * **Every row says what to do about it.** A findings list that names a
 * problem without naming the fix is a list an operator has to research before
 * they can act, which is how a list stops being read.
 *
 * Nothing here edits a record: core owns the checks because they are RFCs,
 * and changing a zone belongs behind the guarded workflow.
 */
import { Head, Link, router } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface FindingRow {
  id: string
  domain: string | null
  domainId: string | null
  check: string
  checkLabel: string
  detail: string
  severity: string
  severityLabel: string
  severityTone: string
  source: string
  firstSeenAt: string
  lastSeenAt: string
  clearedAt: string | null
  customer: string | null
  customerId: string | null
}

const props = defineProps<{
  findings: {
    data: FindingRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  counts: { warnings: number; information: number }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'finding', label: t('security.dns.columns.finding') },
  { key: 'domain', label: t('security.dns.columns.domain') },
  { key: 'severity', label: t('security.dns.columns.severity') },
  { key: 'customer', label: t('security.dns.columns.customer') },
  { key: 'since', label: t('security.dns.columns.since') },
  { key: 'source', label: t('security.dns.columns.source'), optional: true },
]

const metrics = [
  {
    key: 'warnings',
    label: t('security.dns.warnings'),
    value: String(props.counts.warnings),
    tone: 'warning' as const,
  },
  {
    key: 'information',
    label: t('security.dns.information'),
    value: String(props.counts.information),
  },
]

function toggleAll(): void {
  router.get(
    '/admin/security/dns',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('security.dns.title')" />

  <AdminLayout :heading="t('ui.nav.dns')" :description="t('security.dns.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('security.dns.show_open') : t('security.dns.show_all') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <MetricStrip v-if="findings.data.length > 0" :items="metrics" />

      <EmptyState
        v-if="findings.data.length === 0"
        icon="ok"
        :title="t('security.dns.empty')"
        :description="t('security.dns.empty_detail')"
        boxed
      />

      <AppTable v-else name="zone-findings" :columns="COLUMNS">
        <AppTableRow v-for="row in findings.data" :key="row.id">
          <td data-col="finding">
            <span class="font-medium">{{ row.checkLabel }}</span>
            <!--
              What to do about it, on the row. A findings list that names a
              problem without naming the fix is one an operator has to
              research before they can act.
            -->
            <span class="text-content-muted text-chrome block max-w-[70ch]">
              {{ row.detail }}
            </span>
          </td>
          <td data-col="domain">
            <Link
              v-if="row.domainId"
              :href="`/admin/domains/${row.domainId}`"
              class="text-brand font-mono hover:underline"
            >
              {{ row.domain }}
            </Link>
          </td>
          <td data-col="severity">
            <AppStatus :tone="asTone(row.severityTone)" :label="row.severityLabel" />
          </td>
          <td data-col="customer">
            <Link
              v-if="row.customerId"
              :href="`/admin/customers/${row.customerId}`"
              class="text-brand hover:underline"
            >
              {{ row.customer }}
            </Link>
            <span v-else class="text-content-subtle">{{ t('security.dns.unattributed') }}</span>
          </td>
          <td data-col="since" class="text-content-muted text-chrome tabular-nums">
            {{ day(row.firstSeenAt) }}
            <span v-if="row.clearedAt" class="text-content-subtle block">
              {{ t('security.dns.fixed_on', { date: day(row.clearedAt) }) }}
            </span>
          </td>
          <td data-col="source" class="text-content-muted font-mono">{{ row.source }}</td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="findings.links" :total="findings.total" />
    </div>
  </AdminLayout>
</template>
