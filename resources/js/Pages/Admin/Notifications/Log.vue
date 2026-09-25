<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface DeliveryRow {
  id: string
  event: string
  channel: string
  status: string
  statusLabel: string
  recipient: string | null
  address: string | null
  subject: string | null
  error: string | null
  createdAt: string
}

const props = defineProps<{
  deliveries: {
    data: DeliveryRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: { event: string | null }
  events: { value: string; label: string }[]
}>()

const active = computed(() => props.filters.event)

function filterBy(event: string | null): void {
  router.get('/admin/notifications/log', event === null ? {} : { event }, {
    preserveState: true,
    replace: true,
  })
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Delivery log" />

  <AdminLayout
    heading="Delivery log"
    description="Everything this platform has sent, and what became of it."
  >
    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          active === null
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterBy(null)"
      >
        All
      </button>
      <button
        v-for="event in events"
        :key="event.value"
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          active === event.value
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterBy(event.value)"
      >
        {{ event.label }}
      </button>
    </div>

    <AppTable
      v-if="deliveries.data.length > 0"
      :headers="['Message', 'Recipient', 'Channel', 'Status', 'Sent']"
    >
      <tr v-for="delivery in deliveries.data" :key="delivery.id">
        <td class="px-4 py-2.5">
          <span class="font-medium">{{ delivery.event }}</span>
          <span v-if="delivery.subject" class="text-content-muted text-chrome block">
            {{ delivery.subject }}
          </span>
        </td>
        <td class="px-4 py-2.5">
          {{ delivery.recipient ?? '—' }}
          <span v-if="delivery.address" class="text-content-muted text-chrome block">
            {{ delivery.address }}
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5">{{ delivery.channel }}</td>
        <td class="px-4 py-2.5">
          <AppStatus :tone="statusTone(delivery.status)" :label="delivery.statusLabel" />
          <!-- "We did not send it because they asked us not to" and "we
               tried and it bounced" are different answers. -->
          <span v-if="delivery.error" class="text-content-muted text-chrome block max-w-[40ch]">
            {{ delivery.error }}
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(delivery.createdAt) }}
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing sent yet"
      description="Every message this platform sends is recorded here, including the ones it decided not to send."
    />

    <AppPagination :links="deliveries.links" :total="deliveries.total" />
  </AdminLayout>
</template>
