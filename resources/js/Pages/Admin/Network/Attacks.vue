<script setup lang="ts">
/**
 * Attacks, and what was behind the addresses.
 *
 * The column an operator's eye should land on is **Customer**, not the peak.
 * Every scrubbing vendor's own portal already shows gigabits; the thing only
 * this platform can put beside them is whose service was on that address at
 * the time, which is what turns an alert into a phone call to the right
 * person.
 *
 * An event with nobody behind it is drawn rather than hidden: an attack on an
 * address this installation does not recognise is a finding — a misconfigured
 * scrubber, a range nobody recorded, somebody else's address being reported
 * to us.
 *
 * There is no mitigation button. Asking somebody else's control plane to
 * divert a customer's traffic is a change with consequences, and it belongs
 * behind a workflow rather than behind a button.
 */
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
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

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface Vector {
  value: string
  label: string
}

interface EventRow {
  id: string
  target: string
  customer: string | null
  customerId: string | null
  service: string | null
  serviceId: string | null
  source: string
  startedAt: string
  endedAt: string | null
  durationSeconds: number | null
  peakGbps: string | null
  peakMpps: string | null
  vectors: Vector[]
  mitigation: string | null
}

interface Amount {
  currency: string
  amount: string
  minor: number
}

const props = defineProps<{
  events: {
    data: EventRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { running: boolean }
  impact: { services: number; customers: number; recurring: Amount[] }
}>()

const { t } = useTranslations()

const columns: TableColumn[] = [
  { key: 'target', label: t('network.ddos.columns.target') },
  { key: 'customer', label: t('network.ddos.columns.customer') },
  { key: 'started', label: t('network.ddos.columns.started') },
  { key: 'duration', label: t('network.ddos.columns.duration') },
  { key: 'peak', label: t('network.ddos.columns.peak'), numeric: true },
  { key: 'vectors', label: t('network.ddos.columns.vectors') },
  { key: 'mitigation', label: t('network.ddos.columns.mitigation'), optional: true },
]

function toggleRunning(): void {
  router.get(
    '/admin/network/attacks',
    { running: props.filters.running ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function when(value: string): string {
  return new Date(value).toLocaleString()
}

/**
 * A duration a person reads, rather than a count of seconds.
 *
 * Null while it is still going: a number that grows every time somebody
 * refreshes is a number two operators would quote differently in the same
 * minute.
 */
function lasted(seconds: number | null): string {
  if (seconds === null) return '—'
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`

  return `${(seconds / 3600).toFixed(1)}h`
}

/** Whichever peak the vendor reported, or both. */
function peak(row: EventRow): string {
  const parts: string[] = []

  if (row.peakGbps !== null) parts.push(`${Number(row.peakGbps)} Gbps`)
  if (row.peakMpps !== null) parts.push(`${Number(row.peakMpps)} Mpps`)

  return parts.length > 0 ? parts.join(' · ') : '—'
}
</script>

<template>
  <Head :title="t('network.ddos.title')" />

  <AdminLayout :heading="t('network.ddos.title')" :description="t('network.ddos.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleRunning">
        {{ filters.running ? t('network.ddos.filters.all') : t('network.ddos.filters.running') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        The figure no scrubbing vendor can produce. Money is a list here, never
        a number: a total across currencies means nothing and is exactly the
        figure somebody would quote.
      -->
      <MetricStrip
        v-if="events.data.length > 0"
        :items="[
          { key: 'services', label: t('network.ddos.services'), value: String(impact.services) },
          { key: 'customers', label: t('network.ddos.customers'), value: String(impact.customers) },
          { key: 'recurring', label: t('network.ddos.recurring'), value: '' },
        ]"
      >
        <template #recurring>
          <span v-if="impact.recurring.length === 0">—</span>
          <span v-else class="flex flex-wrap items-baseline gap-x-3">
            <span v-for="amount in impact.recurring" :key="amount.currency" class="tabular-nums">
              {{ amount.amount }}
            </span>
          </span>
        </template>
      </MetricStrip>

      <EmptyState
        v-if="events.data.length === 0"
        icon="security"
        :title="t('network.ddos.empty')"
        :description="t('network.ddos.empty_detail')"
        boxed
      />

      <AppTable v-else name="ddos-events" :columns="columns">
        <AppTableRow v-for="row in events.data" :key="row.id">
          <td data-col="target">
            <span class="font-mono font-medium">{{ row.target }}</span>
            <span class="text-content-subtle text-chrome block">{{ row.source }}</span>
          </td>
          <td data-col="customer">
            <Link
              v-if="row.customerId"
              :href="`/admin/customers/${row.customerId}`"
              class="text-brand font-medium hover:underline"
            >
              {{ row.customer }}
            </Link>
            <!--
              A finding, not a gap. Said in words rather than shown as a dash,
              because the dash would read as missing data.

              Not `compact`: that hides the label for a screen reader only,
              which is right where the word is already beside the mark and
              wrong where the mark is the whole cell — the design system's
              "status is never colour alone" applies to a glyph on its own
              too.
            -->
            <AppStatus v-else tone="warning" :label="t('network.ddos.unattributed')" />
            <span v-if="row.service" class="text-content-subtle text-chrome block">
              {{ row.service }}
            </span>
          </td>
          <td data-col="started" class="text-content-muted text-chrome tabular-nums">
            {{ when(row.startedAt) }}
          </td>
          <td data-col="duration">
            <AppStatus
              v-if="row.endedAt === null"
              tone="critical"
              :label="t('network.ddos.running')"
            />
            <span v-else class="text-content-muted tabular-nums">
              {{ lasted(row.durationSeconds) }}
            </span>
          </td>
          <td data-col="peak" class="numeric tabular-nums">{{ peak(row) }}</td>
          <td data-col="vectors">
            <span class="flex flex-wrap gap-1">
              <AppBadge v-for="vector in row.vectors" :key="vector.value" tone="neutral">
                {{ vector.label }}
              </AppBadge>
            </span>
          </td>
          <td data-col="mitigation" class="text-content-muted">{{ row.mitigation ?? '—' }}</td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="events.links" :total="events.total" />
    </div>
  </AdminLayout>
</template>
