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
  <Head title="Gateway log" />

  <AdminLayout
    heading="Gateway log"
    description="Every webhook this platform accepted, with the provider's own event id. Payload bodies are not shown."
  >
    <form v-if="gateways.length > 0" class="mb-6 max-w-xs" @submit.prevent="apply">
      <AppSelect
        v-model="gateway"
        label="Gateway"
        :options="[{ value: '', label: 'All' }, ...gateways]"
        @update:model-value="apply"
      />
    </form>

    <AppTable
      v-if="events.data.length > 0"
      :headers="['Received', 'Gateway', 'Event', 'Type', 'Outcome', 'Processed']"
    >
      <tr v-for="row in events.data" :key="row.id">
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(row.receivedAt) }}
        </td>
        <td class="px-4 py-2.5">{{ row.gateway }}</td>
        <td class="text-content-subtle text-chrome px-4 py-2.5 font-mono">{{ row.eventId }}</td>
        <td class="px-4 py-2.5">{{ row.type }}</td>
        <td class="px-4 py-2.5">
          <AppStatus :tone="statusTone(row.outcome)" :label="row.outcome" />
          <span v-if="row.error" class="text-danger text-chrome mt-1 block max-w-[40ch]">
            {{ row.error }}
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(row.processedAt) }}
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing has arrived"
      description="A row appears here the moment a gateway calls this installation's webhook."
    />

    <AppPagination :links="events.links" :total="events.total" />
  </AdminLayout>
</template>
