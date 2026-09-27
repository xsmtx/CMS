<script setup lang="ts">
/**
 * The certificate fleet (§8).
 *
 * **Soonest first, always.** A fleet is read in the order things will break,
 * and a list sorted by name is a list somebody has to search rather than
 * read.
 *
 * **Nothing here issues a certificate**, and the empty state says why rather
 * than offering a button that would not work: core discovers what is
 * deployed, and obtaining one is a provisioning module's job with an account
 * key behind it.
 *
 * The chain column has three states rather than two. A null is "nobody
 * looked" — an adapter reading a file off disk cannot say what a client would
 * be served — and drawing that as a tick would be the platform asserting
 * something it does not know.
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

interface CertificateRow {
  id: string
  commonName: string
  names: string[]
  issuer: string
  source: string
  notAfter: string
  days: number
  state: string
  stateLabel: string
  stateTone: string
  chainOk: boolean | null
  customer: string | null
  customerId: string | null
  retired: boolean
}

const props = defineProps<{
  certificates: {
    data: CertificateRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  counts: { expired: number; soon: number; healthy: number }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'name', label: t('security.certificates.columns.name') },
  { key: 'expiry', label: t('security.certificates.columns.expiry') },
  { key: 'customer', label: t('security.certificates.columns.customer') },
  { key: 'chain', label: t('security.certificates.columns.chain') },
  { key: 'issuer', label: t('security.certificates.columns.issuer'), optional: true },
  { key: 'source', label: t('security.certificates.columns.source'), optional: true },
]

const metrics = [
  {
    key: 'expired',
    label: t('security.certificates.expired'),
    value: String(props.counts.expired),
    tone: 'critical' as const,
  },
  {
    key: 'soon',
    label: t('security.certificates.expiring_soon'),
    value: String(props.counts.soon),
    tone: 'warning' as const,
  },
  {
    key: 'healthy',
    label: t('security.certificates.healthy'),
    value: String(props.counts.healthy),
  },
]

function toggleAll(): void {
  router.get(
    '/admin/security/certificates',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}

/** Three states, because a null means nobody looked. */
function chain(row: CertificateRow): { tone: string; label: string } {
  if (row.chainOk === null) {
    return { tone: 'unknown', label: t('security.certificates.chain_unknown') }
  }

  return row.chainOk
    ? { tone: 'healthy', label: t('security.certificates.chain_ok') }
    : { tone: 'warning', label: t('security.certificates.chain_broken') }
}
</script>

<template>
  <Head :title="t('security.certificates.title')" />

  <AdminLayout :heading="t('ui.nav.certificates')" :description="t('security.certificates.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{
          filters.all ? t('security.certificates.show_live') : t('security.certificates.show_all')
        }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <MetricStrip v-if="certificates.data.length > 0" :items="metrics" />

      <EmptyState
        v-if="certificates.data.length === 0"
        icon="security"
        :title="t('security.certificates.empty')"
        :description="t('security.certificates.empty_detail')"
        boxed
      />

      <AppTable v-else name="certificates" :columns="COLUMNS">
        <AppTableRow v-for="row in certificates.data" :key="row.id">
          <td data-col="name">
            <span class="font-medium">{{ row.commonName }}</span>
            <!--
              How many other names it covers. The list itself would be forty
              subdomains on a wildcard estate and is not what anybody is
              scanning this column for.
            -->
            <span v-if="row.names.length > 1" class="text-content-subtle text-chrome block">
              +{{ row.names.length - 1 }}
            </span>
          </td>
          <td data-col="expiry">
            <AppStatus :tone="asTone(row.stateTone)" :label="row.stateLabel" />
            <span class="text-content-subtle text-chrome block tabular-nums">
              {{ day(row.notAfter) }}
              <template v-if="row.retired"> · {{ t('security.certificates.retired') }}</template>
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
            <span v-else class="text-content-subtle">
              {{ t('security.certificates.unattributed') }}
            </span>
          </td>
          <td data-col="chain">
            <AppStatus :tone="asTone(chain(row).tone)" :label="chain(row).label" />
          </td>
          <td data-col="issuer" class="text-content-muted">{{ row.issuer }}</td>
          <td data-col="source" class="text-content-muted font-mono">{{ row.source }}</td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="certificates.links" :total="certificates.total" />
    </div>
  </AdminLayout>
</template>
