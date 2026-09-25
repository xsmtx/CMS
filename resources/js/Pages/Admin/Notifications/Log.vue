<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'message', label: t('notifications.admin.event'), sticky: true },
  { key: 'recipient', label: t('notifications.admin.recipient') },
  { key: 'channel', label: t('notifications.admin.channel') },
  { key: 'status', label: t('notifications.admin.status') },
  { key: 'sent', label: t('notifications.admin.sent_at') },
]

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
  <Head :title="t('notifications.admin.log_title')" />

  <AdminLayout
    :heading="t('notifications.admin.log_title')"
    :description="t('notifications.admin.log_subtitle')"
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

    <AppTable v-if="deliveries.data.length > 0" name="notification-log" :columns="COLUMNS">
      <AppTableRow v-for="delivery in deliveries.data" :key="delivery.id">
        <td data-col="message">
          <span class="font-medium">{{ delivery.event }}</span>
          <span v-if="delivery.subject" class="text-content-muted text-chrome block">
            {{ delivery.subject }}
          </span>
        </td>
        <td data-col="recipient">
          {{ delivery.recipient ?? '—' }}
          <span v-if="delivery.address" class="text-content-muted text-chrome block">
            {{ delivery.address }}
          </span>
        </td>
        <td data-col="channel" class="text-content-muted">{{ delivery.channel }}</td>
        <td data-col="status">
          <AppStatus :tone="statusTone(delivery.status)" :label="delivery.statusLabel" />
          <!-- "We did not send it because they asked us not to" and "we
               tried and it bounced" are different answers. -->
          <span v-if="delivery.error" class="text-content-muted text-chrome block max-w-[40ch]">
            {{ delivery.error }}
          </span>
        </td>
        <td data-col="sent" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(delivery.createdAt) }}
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="mail"
      :title="t('notifications.admin.log_empty')"
      :description="t('notifications.admin.log_empty_description')"
    />

    <AppPagination :links="deliveries.links" :total="deliveries.total" />
  </AdminLayout>
</template>
