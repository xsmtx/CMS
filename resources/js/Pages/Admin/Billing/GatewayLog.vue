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

import AppBadge from '../../../Components/AppBadge.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
  events: { data: EventRow[]; currentPage: number; lastPage: number; total: number }
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

function tone(outcome: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (outcome === 'processed') return 'success'
  if (outcome === 'failed') return 'danger'
  if (outcome === 'ignored' || outcome === 'duplicate') return 'warning'
  return 'neutral'
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
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap">
          {{ formatDateTime(row.receivedAt) }}
        </td>
        <td class="px-5 py-3.5">{{ row.gateway }}</td>
        <td class="text-content-subtle px-5 py-3.5 font-mono text-xs">{{ row.eventId }}</td>
        <td class="px-5 py-3.5">{{ row.type }}</td>
        <td class="px-5 py-3.5">
          <AppBadge :tone="tone(row.outcome)">{{ row.outcome }}</AppBadge>
          <span v-if="row.error" class="text-danger mt-1 block max-w-[40ch] text-xs">
            {{ row.error }}
          </span>
        </td>
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap">
          {{ formatDateTime(row.processedAt) }}
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing has arrived"
      description="A row appears here the moment a gateway calls this installation's webhook."
    />

    <p v-if="events.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ events.currentPage }} of {{ events.lastPage }} — {{ events.total }} events
    </p>
  </AdminLayout>
</template>
