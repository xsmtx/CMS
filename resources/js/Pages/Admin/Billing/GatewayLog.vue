<script setup lang="ts">
/**
 * What the gateways told us, and whether we could act on it.
 *
 * The screen for "the customer says they paid and the invoice says
 * otherwise". The payload is deliberately not shown: a gateway body can
 * carry a name, an address and the last four of a card, and a log screen is
 * the easiest place in a product to leak all three to somebody who only
 * needed to know whether an event arrived.
 */
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface EventRow {
  id: string
  gateway: string
  eventId: string
  type: string
  outcome: string
  error: string | null
  reference: string | null
  receivedAt: string
  processedAt: string | null
}

const props = defineProps<{
  events: {
    data: EventRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: { gateway: string | null }
  gateways: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'received', label: t('billing.gateway_log.received') },
  { key: 'gateway', label: t('billing.gateway_log.gateway'), sticky: true },
  { key: 'event', label: t('billing.gateway_log.event') },
  { key: 'type', label: t('billing.gateway_log.type') },
  { key: 'outcome', label: t('billing.gateway_log.outcome') },
  { key: 'processed', label: t('billing.gateway_log.processed') },
]

const gateway = ref(props.filters.gateway ?? '')

function apply(): void {
  router.get('/admin/billing/gateway-log', gateway.value === '' ? {} : { gateway: gateway.value }, {
    preserveState: true,
    replace: true,
  })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('billing.gateway_log.title')" />

  <AdminLayout
    :heading="t('billing.gateway_log.title')"
    :description="t('billing.gateway_log.subtitle')"
  >
    <form v-if="gateways.length > 0" class="mb-6 max-w-xs" @submit.prevent="apply">
      <AppSelect
        v-model="gateway"
        label="Gateway"
        :options="[{ value: '', label: 'All' }, ...gateways]"
        @update:model-value="apply"
      />
    </form>

    <AppTable v-if="events.data.length > 0" name="gateway-log" :columns="COLUMNS">
      <AppTableRow v-for="row in events.data" :key="row.id">
        <td data-col="received" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(row.receivedAt) }}
        </td>
        <td data-col="gateway">{{ row.gateway }}</td>
        <td data-col="event" class="text-content-subtle text-chrome font-mono">
          {{ row.eventId }}
        </td>
        <td data-col="type">{{ row.type }}</td>
        <td data-col="outcome">
          <AppStatus :tone="statusTone(row.outcome)" :label="row.outcome" />
          <span v-if="row.error" class="text-danger text-chrome mt-1 block max-w-[40ch]">
            {{ row.error }}
          </span>
        </td>
        <td data-col="processed" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(row.processedAt) }}
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="billing"
      :title="t('billing.gateway_log.empty')"
      :description="t('billing.gateway_log.empty_description')"
    />

    <AppPagination :links="events.links" :total="events.total" />
  </AdminLayout>
</template>
