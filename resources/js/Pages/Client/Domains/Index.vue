<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

interface DomainRow {
  id: string
  name: string
  status: string
  statusLabel: string
  isUsable: boolean
  expiresOn: string | null
  daysUntilExpiry: number | null
  renewal: string
}

defineProps<{ domains: DomainRow[] }>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'domain', label: t('portal.columns.domain'), sticky: true },
  { key: 'status', label: t('portal.columns.status') },
  { key: 'expires', label: t('portal.columns.expires') },
  { key: 'renewal', label: t('portal.columns.renewal'), numeric: true },
]

/**
 * A date-only column arrives as `Y-m-d` and has to be localised here: it is
 * the one value a server cannot format, because only the browser knows where
 * the person reading it lives. The admin copies of these screens learned the
 * same lesson; the portal renders the same records through different pages.
 */
function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('domains.portal.title')" />

  <ClientLayout :heading="t('domains.portal.title')" :description="t('domains.portal.description')">
    <AppTable v-if="domains.length > 0" name="portal-domains" :columns="COLUMNS">
      <AppTableRow v-for="domain in domains" :key="domain.id">
        <td data-col="domain">
          <Link
            :href="`/client/domains/${domain.id}`"
            class="font-mono font-medium break-all underline-offset-4 hover:underline"
          >
            {{ domain.name }}
          </Link>
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
        </td>
        <td data-col="expires" class="text-content-muted">{{ formatDate(domain.expiresOn) }}</td>
        <td data-col="renewal" class="numeric tabular-nums">{{ domain.renewal }}</td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="domains"
      :title="t('domains.portal.none')"
      :description="t('domains.portal.none_description')"
    />
  </ClientLayout>
</template>
