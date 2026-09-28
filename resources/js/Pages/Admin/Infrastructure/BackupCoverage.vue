<script setup lang="ts">
/**
 * What is protected, what is stale and what nothing is backing up (§12).
 *
 * **It opens on the unprotected list, not on the inventory.** Everything a
 * backup vendor can tell you is already on their own console; what nothing
 * else can say is which of the things this installation sold has nobody
 * looking after it.
 *
 * **Stale and unprotected are kept apart deliberately.** Something trying and
 * not succeeding is a different problem from nothing trying at all, and
 * collapsing them would make a source that is merely down look like every
 * customer having lost their backups.
 *
 * All three figures filter, which is why all three are `AppStat`. A count
 * that pressed and did nothing would be a control that lies.
 *
 * Nothing here runs a backup or a restore: core does not take backups, and a
 * button that claimed otherwise would be the worst kind of lie this product
 * could tell.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppStat from '../../../Components/AppStat.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone, statusTone } from '../../../status'

type Tab = 'unprotected' | 'stale' | 'protected'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface ServiceRow {
  id: string
  name: string
  domain: string | null
  status: string
  statusLabel: string
  customer: string | null
  customerId: string | null
}

interface ProtectionRow {
  id: string
  name: string
  source: string
  repository: string | null
  outcome: string
  outcomeLabel: string
  outcomeTone: string
  lastRunAt: string | null
  lastGoodAt: string | null
  lastGoodDays: number | null
  restorePoints: number | null
  serviceId: string | null
  service: string | null
  customer: string | null
  customerId: string | null
}

const props = defineProps<{
  tab: Tab
  staleAfter: number
  summary: { protected: number; stale: number; unprotected: number }
  rows: {
    data: (ServiceRow | ProtectionRow)[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
}>()

const { t } = useTranslations()

const services = computed(() =>
  props.tab === 'unprotected' ? (props.rows.data as ServiceRow[]) : [],
)

const protections = computed(() =>
  props.tab === 'unprotected' ? [] : (props.rows.data as ProtectionRow[]),
)

const SERVICE_COLUMNS: TableColumn[] = [
  { key: 'service', label: t('infrastructure.backups.columns.service') },
  { key: 'customer', label: t('infrastructure.backups.columns.customer') },
  { key: 'status', label: t('infrastructure.backups.columns.status') },
]

const PROTECTION_COLUMNS: TableColumn[] = [
  { key: 'resource', label: t('infrastructure.backups.columns.resource') },
  { key: 'customer', label: t('infrastructure.backups.columns.customer') },
  { key: 'outcome', label: t('infrastructure.backups.columns.outcome') },
  { key: 'last_good', label: t('infrastructure.backups.columns.last_good') },
  {
    key: 'restore_points',
    label: t('infrastructure.backups.columns.restore_points'),
    numeric: true,
    optional: true,
  },
  { key: 'source', label: t('infrastructure.backups.columns.source'), optional: true },
]

const heading = computed(() =>
  props.tab === 'unprotected'
    ? t('infrastructure.backups.unprotected_tab')
    : props.tab === 'stale'
      ? t('infrastructure.backups.stale_tab')
      : t('infrastructure.backups.protected_tab'),
)

function show(tab: Tab): void {
  router.get(
    '/admin/infrastructure/backups',
    { tab, stale_after: props.staleAfter },
    { preserveState: true, replace: true },
  )
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('infrastructure.backups.title')" />

  <AdminLayout :heading="t('ui.nav.backups')" :description="t('infrastructure.backups.intro')">
    <div class="flex flex-col gap-8">
      <!--
        A zero is neutral, never red. Nothing unprotected is the good answer,
        and a screen that drew it in danger would teach an operator to stop
        reading the colour.
      -->
      <div class="flex flex-wrap gap-3">
        <AppStat
          :label="t('infrastructure.backups.unprotected')"
          :value="summary.unprotected"
          :tone="summary.unprotected > 0 ? 'danger' : 'neutral'"
          :active="tab === 'unprotected'"
          @select="show('unprotected')"
        />
        <AppStat
          :label="t('infrastructure.backups.stale')"
          :value="summary.stale"
          :tone="summary.stale > 0 ? 'warning' : 'neutral'"
          :active="tab === 'stale'"
          @select="show('stale')"
        />
        <AppStat
          :label="t('infrastructure.backups.protected')"
          :value="summary.protected"
          :tone="summary.protected > 0 ? 'success' : 'neutral'"
          :active="tab === 'protected'"
          @select="show('protected')"
        />
      </div>

      <section class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
          <h2 class="text-title font-semibold">{{ heading }}</h2>
          <p v-if="tab !== 'unprotected'" class="text-content-muted text-chrome">
            {{ t('infrastructure.backups.stale_after', { days: String(staleAfter) }) }}
          </p>
        </div>

        <EmptyState
          v-if="rows.data.length === 0"
          icon="ok"
          :title="
            tab === 'unprotected'
              ? t('infrastructure.backups.empty_unprotected')
              : tab === 'stale'
                ? t('infrastructure.backups.empty_stale')
                : t('infrastructure.backups.empty_protected')
          "
          :description="
            tab === 'unprotected'
              ? t('infrastructure.backups.empty_unprotected_detail')
              : tab === 'stale'
                ? t('infrastructure.backups.empty_stale_detail')
                : t('infrastructure.backups.empty_protected_detail')
          "
          boxed
        />

        <AppTable
          v-else-if="tab === 'unprotected'"
          name="backup-unprotected"
          :columns="SERVICE_COLUMNS"
        >
          <AppTableRow v-for="row in services" :key="row.id">
            <td data-col="service">
              <Link
                :href="`/admin/services/${row.id}`"
                class="text-brand font-medium hover:underline"
              >
                {{ row.name }}
              </Link>
              <span v-if="row.domain" class="text-content-muted text-chrome block font-mono">
                {{ row.domain }}
              </span>
            </td>
            <td data-col="customer">
              <Link
                v-if="row.customerId"
                :href="`/admin/customers/${row.customerId}`"
                class="text-brand hover:underline"
              >
                {{ row.customer }}
              </Link>
            </td>
            <td data-col="status">
              <AppStatus :tone="statusTone(row.status)" :label="row.statusLabel" />
            </td>
          </AppTableRow>
        </AppTable>

        <AppTable v-else name="backup-protections" :columns="PROTECTION_COLUMNS">
          <AppTableRow v-for="row in protections" :key="row.id">
            <td data-col="resource">
              <span class="font-medium">{{ row.name }}</span>
              <Link
                v-if="row.serviceId"
                :href="`/admin/services/${row.serviceId}`"
                class="text-content-muted text-chrome block hover:underline"
              >
                {{ row.service }}
              </Link>
              <!--
                A protection that matches no service is either a job for a
                customer who left or a name spelled differently here. Both are
                findings, so the row says so rather than leaving a blank.
              -->
              <span v-else class="text-content-subtle text-chrome block">
                {{ t('infrastructure.backups.unmatched') }}
              </span>
            </td>
            <td data-col="customer">
              <Link
                v-if="row.customerId"
                :href="`/admin/customers/${row.customerId}`"
                class="text-brand hover:underline"
              >
                {{ row.customer }}
              </Link>
            </td>
            <td data-col="outcome">
              <AppStatus :tone="asTone(row.outcomeTone)" :label="row.outcomeLabel" />
              <span v-if="row.lastRunAt" class="text-content-subtle text-chrome block tabular-nums">
                {{ day(row.lastRunAt) }}
              </span>
            </td>
            <td data-col="last_good" class="text-content-muted text-chrome tabular-nums">
              <template v-if="row.lastGoodAt && row.lastGoodDays !== null">
                {{ day(row.lastGoodAt) }}
                <span class="text-content-subtle block">
                  <!--
                    "0 days old" beside a date reads as a figure that failed
                    to load rather than as a backup that ran this morning.
                  -->
                  {{
                    row.lastGoodDays === 0
                      ? t('infrastructure.backups.today')
                      : t('infrastructure.backups.days_old', { days: String(row.lastGoodDays) })
                  }}
                </span>
              </template>
              <template v-else>
                <!--
                  Nothing has ever succeeded. Said in words rather than with
                  an age, because a number invented here is a number somebody
                  acts on.
                -->
                <span class="text-content font-medium">
                  {{ t('infrastructure.backups.never') }}
                </span>
                <span class="text-content-subtle block max-w-[40ch]">
                  {{ t('infrastructure.backups.never_detail') }}
                </span>
              </template>
            </td>
            <td data-col="restore_points" class="numeric">
              {{ row.restorePoints ?? '' }}
            </td>
            <td data-col="source" class="text-content-muted font-mono">
              {{ row.source }}
            </td>
          </AppTableRow>
        </AppTable>

        <AppPagination :links="rows.links" :total="rows.total" />
      </section>
    </div>
  </AdminLayout>
</template>
