<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'

import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { httpTone } from '../../../status'

interface RequestRow {
  id: string
  token: string | null
  method: string
  path: string
  route: string | null
  status: number
  errorCode: string | null
  durationMs: number
  ip: string | null
  correlationId: string | null
  createdAt: string | null
}

defineProps<{
  requests: {
    data: RequestRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: { refused: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'request', label: t('api.activity.route'), sticky: true },
  { key: 'token', label: t('api.activity.token') },
  { key: 'status', label: t('api.activity.status') },
  { key: 'took', label: t('api.activity.duration'), numeric: true },
  { key: 'from', label: t('api.activity.from'), optional: true },
  { key: 'when', label: t('api.activity.when') },
]

function filterBy(refused: boolean): void {
  router.get('/admin/api/activity', refused ? { refused: 1 } : {}, {
    preserveState: true,
    replace: true,
  })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('api.activity.title')" />

  <AdminLayout
    :heading="t('api.activity.title')"
    description="Every request this installation was asked for. Never the body of one."
  >
    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          filters.refused
            ? 'text-content-muted hover:bg-surface-secondary'
            : 'bg-surface-secondary text-content font-medium'
        "
        @click="filterBy(false)"
      >
        {{ t('api.activity.all') }}
      </button>
      <button
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          filters.refused
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterBy(true)"
      >
        {{ t('api.activity.refused_only') }}
      </button>
    </div>

    <AppTable v-if="requests.data.length > 0" name="api-activity" :columns="COLUMNS">
      <AppTableRow v-for="item in requests.data" :key="item.id">
        <td data-col="request">
          <span class="text-content-subtle text-chrome font-mono">{{ item.method }}</span>
          <span class="ml-2 font-medium">/{{ item.path }}</span>
        </td>
        <td data-col="token" class="text-content-muted">{{ item.token ?? '—' }}</td>
        <td data-col="status">
          <AppStatus :tone="httpTone(item.status)" :label="String(item.status)" />
          <span v-if="item.errorCode" class="text-content-muted text-chrome block">
            {{ item.errorCode }}
          </span>
        </td>
        <td data-col="took" class="text-content-muted tabular-nums">
          {{ t('api.activity.ms', { count: item.durationMs }) }}
        </td>
        <td data-col="from" class="text-content-muted text-chrome font-mono">
          {{ item.ip ?? '—' }}
        </td>
        <td data-col="when" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(item.createdAt) }}
          <!-- The way from a line here to the rest of the story. -->
          <span v-if="item.correlationId" class="text-content-subtle text-label block font-mono">
            {{ item.correlationId }}
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="connection"
      :title="t('api.activity.none')"
      :description="t('api.activity.none_description')"
    />

    <AppPagination :links="requests.links" :total="requests.total" />
  </AdminLayout>
</template>
