<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface OperationRow {
  id: string
  type: string
  typeLabel: string
  state: string
  stateLabel: string
  subject: string | null
  attempt: number
  maxAttempts: number
  progress: number
  startedAt: string | null
  finishedAt: string | null
  nextAttemptAt: string | null
  resolvedAt: string | null
  needsAttention: boolean
  canRetry: boolean
  error: string | null
  createdAt: string | null
  subjectHref: string | null
}

defineProps<{
  operations: { data: OperationRow[]; currentPage: number; lastPage: number; total: number }
  filters: { state: string | null; type: string | null }
  states: { value: string; label: string }[]
  types: { value: string; label: string }[]
  attention: number
  can: { manage: boolean }
}>()

function filterByState(state: string | null): void {
  router.get('/admin/operations', state === null ? {} : { state }, {
    preserveState: true,
    replace: true,
  })
}

function retry(operation: OperationRow): void {
  router.post(`/admin/operations/${operation.id}/retry`, {}, { preserveScroll: true })
}

function resolve(operation: OperationRow): void {
  router.post(`/admin/operations/${operation.id}/resolve`, {}, { preserveScroll: true })
}

function tone(state: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (state === 'completed') return 'success'
  if (state === 'failed') return 'danger'
  if (state === 'manual_intervention') return 'danger'
  if (state === 'retrying') return 'warning'

  return 'neutral'
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Operations" />

  <AdminLayout
    heading="Operations"
    description="Long-running work, while it is running and after it has stopped."
  >
    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          filters.state === null
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
        "
        @click="filterByState(null)"
      >
        Needs attention
        <span v-if="attention > 0" class="text-danger ml-1 tabular-nums">{{ attention }}</span>
      </button>
      <button
        v-for="state in states"
        :key="state.value"
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          filters.state === state.value
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
        "
        @click="filterByState(state.value)"
      >
        {{ state.label }}
      </button>
    </div>

    <AppTable
      v-if="operations.data.length > 0"
      :headers="['Client / service', 'Module / action', 'Failure reason', 'Attempt', 'Started', '']"
    >
      <tr v-for="operation in operations.data" :key="operation.id">
        <td class="px-5 py-3.5">
          <Link
            v-if="operation.subjectHref"
            :href="operation.subjectHref"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ operation.subject ?? '—' }}
          </Link>
          <span v-else class="font-medium">{{ operation.subject ?? '—' }}</span>
        </td>
        <td class="px-5 py-3.5">
          {{ operation.typeLabel }}
          <AppBadge :tone="tone(operation.state)" class="ml-2">
            {{ operation.stateLabel }}
          </AppBadge>
        </td>
        <td class="px-5 py-3.5">
          <!-- Already redacted on the way in. Shown because an operator
               cannot act on "something went wrong". -->
          <span v-if="operation.error" class="text-content-muted block max-w-[46ch] text-xs">
            {{ operation.error }}
          </span>
          <span v-else class="text-content-muted text-xs">—</span>
        </td>
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap tabular-nums">
          {{ operation.attempt }} / {{ operation.maxAttempts }}
          <span v-if="operation.nextAttemptAt" class="block text-xs">
            next {{ formatDateTime(operation.nextAttemptAt) }}
          </span>
        </td>
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap">
          {{ formatDateTime(operation.startedAt ?? operation.createdAt) }}
        </td>
        <td class="px-5 py-3.5 text-right whitespace-nowrap">
          <AppButton
            v-if="can.manage && operation.canRetry"
            size="sm"
            variant="ghost"
            @click="retry(operation)"
          >
            Retry
          </AppButton>
          <AppButton
            v-if="can.manage && operation.needsAttention"
            size="sm"
            variant="ghost"
            @click="resolve(operation)"
          >
            Mark solved
          </AppButton>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing needs attention"
      description="Provisioning, registrations, transfers and renewals appear here as they happen."
    />

    <p v-if="operations.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ operations.currentPage }} of {{ operations.lastPage }} — {{ operations.total }}
    </p>
  </AdminLayout>
</template>
