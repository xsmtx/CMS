<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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

function tone(status: number): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status >= 500) return 'danger'
  if (status >= 400) return 'warning'
  if (status >= 200 && status < 300) return 'success'

  return 'neutral'
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
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          filters.refused
            ? 'text-content-muted hover:bg-surface-sunken'
            : 'bg-surface-sunken text-content font-medium'
        "
        @click="filterBy(false)"
      >
        All
      </button>
      <button
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          filters.refused
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
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
        <td class="px-5 py-3.5">
          <span class="text-content-subtle font-mono text-xs">{{ item.method }}</span>
          <span class="ml-2 font-medium">/{{ item.path }}</span>
        </td>
        <td class="text-content-muted px-5 py-3.5">{{ item.token ?? '—' }}</td>
        <td class="px-5 py-3.5">
          <AppBadge :tone="tone(item.status)">{{ item.status }}</AppBadge>
          <span v-if="item.errorCode" class="text-content-muted block text-xs">
            {{ item.errorCode }}
          </span>
        </td>
        <td class="text-content-muted px-5 py-3.5 tabular-nums">{{ item.durationMs }}ms</td>
        <td class="text-content-muted px-5 py-3.5 font-mono text-xs">{{ item.ip ?? '—' }}</td>
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap">
          {{ formatDateTime(item.createdAt) }}
          <!-- The way from a line here to the rest of the story. -->
          <span v-if="item.correlationId" class="text-content-subtle block font-mono text-[11px]">
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

    <p v-if="requests.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ requests.currentPage }} of {{ requests.lastPage }} — {{ requests.total }}
    </p>
  </AdminLayout>
</template>
