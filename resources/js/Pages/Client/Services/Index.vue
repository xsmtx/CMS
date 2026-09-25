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

interface ServiceRow {
  id: string
  name: string
  status: string
  statusLabel: string
  isUsable: boolean
  domain: string | null
  recurring: string
  cycleLabel: string | null
  nextDueOn: string | null
}

defineProps<{ services: ServiceRow[] }>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'service', label: t('portal.columns.service'), sticky: true },
  { key: 'status', label: t('portal.columns.status') },
  { key: 'price', label: t('portal.columns.price'), numeric: true },
  { key: 'due', label: t('portal.columns.next_due') },
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
  <Head :title="t('provisioning.portal.title')" />

  <ClientLayout
    :heading="t('provisioning.portal.title')"
    :description="t('provisioning.portal.description')"
  >
    <AppTable v-if="services.length > 0" name="portal-services" :columns="COLUMNS">
      <AppTableRow v-for="service in services" :key="service.id">
        <td data-col="service">
          <Link
            :href="`/client/services/${service.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ service.name }}
          </Link>
          <p v-if="service.domain" class="text-content-muted text-chrome">{{ service.domain }}</p>
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(service.status)" :label="service.statusLabel" />
        </td>
        <td data-col="price" class="numeric tabular-nums">
          {{ service.recurring }}
          <span v-if="service.cycleLabel" class="text-content-muted text-chrome block">
            {{ service.cycleLabel }}
          </span>
        </td>
        <td data-col="due" class="text-content-muted">{{ formatDate(service.nextDueOn) }}</td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="services"
      :title="t('provisioning.portal.none')"
      :description="t('provisioning.portal.none_description')"
    />
  </ClientLayout>
</template>
