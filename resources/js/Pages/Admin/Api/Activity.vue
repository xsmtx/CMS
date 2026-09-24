<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'

import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
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
  requests: { data: RequestRow[]; currentPage: number; lastPage: number; total: number }
  filters: { refused: boolean }
}>()

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
  <Head title="API activity" />

  <AdminLayout
    heading="API activity"
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
        All
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
        Refused only
      </button>
    </div>

    <AppTable
      v-if="requests.data.length > 0"
      :headers="['Request', 'Token', 'Status', 'Took', 'From', 'When']"
    >
      <tr v-for="item in requests.data" :key="item.id">
        <td class="px-4 py-2.5">
          <span class="text-content-subtle text-chrome font-mono">{{ item.method }}</span>
          <span class="ml-2 font-medium">/{{ item.path }}</span>
        </td>
        <td class="text-content-muted px-4 py-2.5">{{ item.token ?? '—' }}</td>
        <td class="px-4 py-2.5">
          <AppStatus :tone="httpTone(item.status)" :label="String(item.status)" />
          <span v-if="item.errorCode" class="text-content-muted text-chrome block">
            {{ item.errorCode }}
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5 tabular-nums">{{ item.durationMs }}ms</td>
        <td class="text-content-muted text-chrome px-4 py-2.5 font-mono">{{ item.ip ?? '—' }}</td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(item.createdAt) }}
          <!-- The way from a line here to the rest of the story. -->
          <span v-if="item.correlationId" class="text-content-subtle text-label block font-mono">
            {{ item.correlationId }}
          </span>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing has called the API"
      description="Requests appear here as soon as an integration starts."
    />

    <p v-if="requests.lastPage > 1" class="text-content-muted text-chrome mt-4">
      Page {{ requests.currentPage }} of {{ requests.lastPage }} — {{ requests.total }}
    </p>
  </AdminLayout>
</template>
