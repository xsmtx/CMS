<script setup lang="ts">
/**
 * Which of this installation's addresses are on a blocklist (§13).
 *
 * **Attributed listings first.** An address a customer's service is sitting
 * on is somebody to tell today; an unattributed one is very often our own
 * outbound relay, which is ours to fix whenever.
 *
 * **Every row carries the way out.** An operator who has found the listing
 * still has to find the delisting form, and a screen that names a problem
 * without naming the way out is one they resent. The link is the blocklist's
 * own, so it opens in its own tab and is marked as leaving.
 *
 * Nothing here delists: that is a form with a human on the other end, usually
 * a captcha, and a button that claimed otherwise would lie.
 */
import { Head, Link, router } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppIcon from '../../../Components/AppIcon.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface ListingRow {
  id: string
  address: string
  list: string
  reason: string | null
  delistUrl: string | null
  source: string
  firstSeenAt: string
  lastSeenAt: string
  clearedAt: string | null
  customer: string | null
  customerId: string | null
  service: string | null
  serviceId: string | null
}

const props = defineProps<{
  listings: {
    data: ListingRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  counts: { listed: number; addresses: number; customers: number }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'address', label: t('security.reputation.columns.address') },
  { key: 'list', label: t('security.reputation.columns.list') },
  { key: 'state', label: t('security.reputation.columns.state') },
  { key: 'customer', label: t('security.reputation.columns.customer') },
  { key: 'since', label: t('security.reputation.columns.since') },
  { key: 'source', label: t('security.reputation.columns.source'), optional: true },
]

const metrics = [
  {
    key: 'listed',
    label: t('security.reputation.listed'),
    value: String(props.counts.listed),
    tone: 'warning' as const,
  },
  // Addresses rather than rows: one address on four lists is one problem to
  // solve, and counting it four times would read as four times worse.
  {
    key: 'addresses',
    label: t('security.reputation.addresses'),
    value: String(props.counts.addresses),
  },
  {
    key: 'customers',
    label: t('security.reputation.customers'),
    value: String(props.counts.customers),
  },
]

function toggleAll(): void {
  router.get(
    '/admin/security/reputation',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('security.reputation.title')" />

  <AdminLayout :heading="t('ui.nav.reputation')" :description="t('security.reputation.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('security.reputation.show_open') : t('security.reputation.show_all') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <MetricStrip v-if="listings.data.length > 0" :items="metrics" />

      <EmptyState
        v-if="listings.data.length === 0"
        icon="ok"
        :title="t('security.reputation.empty')"
        :description="t('security.reputation.empty_detail')"
        boxed
      />

      <AppTable v-else name="reputation-listings" :columns="COLUMNS">
        <AppTableRow v-for="row in listings.data" :key="row.id">
          <td data-col="address">
            <span class="font-mono font-medium">{{ row.address }}</span>
            <Link
              v-if="row.serviceId"
              :href="`/admin/services/${row.serviceId}`"
              class="text-content-muted text-chrome block hover:underline"
            >
              {{ row.service }}
            </Link>
          </td>
          <td data-col="list">
            <span class="font-mono">{{ row.list }}</span>
            <!--
              The blocklist's own words, untranslated on purpose: this is
              evidence in a conversation with somebody else, and a paraphrase
              is how a delisting request gets refused.
            -->
            <span v-if="row.reason" class="text-content-muted text-chrome block max-w-[70ch]">
              {{ row.reason }}
            </span>
            <a
              v-if="row.delistUrl"
              :href="row.delistUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="text-brand text-chrome mt-1 inline-flex items-center gap-1 hover:underline"
            >
              {{ t('security.reputation.delist') }}
              <AppIcon name="external" :size="12" />
            </a>
          </td>
          <td data-col="state">
            <AppStatus
              :tone="statusTone(row.clearedAt ? 'cleared' : 'raised')"
              :label="
                row.clearedAt
                  ? t('security.reputation.states.lifted')
                  : t('security.reputation.states.listed')
              "
            />
          </td>
          <td data-col="customer">
            <Link
              v-if="row.customerId"
              :href="`/admin/customers/${row.customerId}`"
              class="text-brand hover:underline"
            >
              {{ row.customer }}
            </Link>
            <span v-else class="text-content-subtle">
              {{ t('security.reputation.unattributed') }}
            </span>
          </td>
          <td data-col="since" class="text-content-muted text-chrome tabular-nums">
            {{ day(row.firstSeenAt) }}
            <span v-if="row.clearedAt" class="text-content-subtle block">
              {{ t('security.reputation.lifted_on', { date: day(row.clearedAt) }) }}
            </span>
          </td>
          <td data-col="source" class="text-content-muted font-mono">{{ row.source }}</td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="listings.links" :total="listings.total" />
    </div>
  </AdminLayout>
</template>
