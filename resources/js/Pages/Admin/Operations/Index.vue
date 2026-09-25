<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppPagination from '../../../Components/AppPagination.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

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
  operations: {
    data: OperationRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: { state: string | null; type: string | null }
  states: { value: string; label: string }[]
  types: { value: string; label: string }[]
  attention: number
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('automation.operations.subject'), sticky: true },
  { key: 'type', label: t('automation.operations.type') },
  { key: 'error', label: t('automation.operations.error') },
  { key: 'attempt', label: t('automation.operations.attempt'), numeric: true },
  { key: 'started', label: t('automation.operations.started') },
  { key: 'actions', label: '' },
]

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

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('automation.operations.title')" />

  <AdminLayout
    :heading="t('automation.operations.title')"
    :description="t('automation.operations.subtitle')"
  >
    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          filters.state === null
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterByState(null)"
      >
        {{ t('automation.operations.needs_attention') }}
        <span v-if="attention > 0" class="text-danger ml-1 tabular-nums">{{ attention }}</span>
      </button>
      <button
        v-for="state in states"
        :key="state.value"
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          filters.state === state.value
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="filterByState(state.value)"
      >
        {{ state.label }}
      </button>
    </div>

    <AppTable v-if="operations.data.length > 0" name="admin-operations" :columns="COLUMNS">
      <AppTableRow v-for="operation in operations.data" :key="operation.id">
        <td data-col="subject">
          <Link
            v-if="operation.subjectHref"
            :href="operation.subjectHref"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ operation.subject ?? '—' }}
          </Link>
          <span v-else class="font-medium">{{ operation.subject ?? '—' }}</span>
        </td>
        <td data-col="type">
          {{ operation.typeLabel }}
          <AppStatus
            :tone="statusTone(operation.state)"
            class="ml-2"
            :label="operation.stateLabel"
          />
        </td>
        <td data-col="error">
          <!-- Already redacted on the way in. Shown because an operator
               cannot act on "something went wrong". -->
          <span v-if="operation.error" class="text-content-muted text-chrome block max-w-[46ch]">
            {{ operation.error }}
          </span>
          <span v-else class="text-content-muted text-chrome">—</span>
        </td>
        <td data-col="attempt" class="text-content-muted whitespace-nowrap tabular-nums">
          {{ operation.attempt }} / {{ operation.maxAttempts }}
          <span v-if="operation.nextAttemptAt" class="text-chrome block">
            {{
              t('automation.operations.next_attempt', {
                when: formatDateTime(operation.nextAttemptAt),
              })
            }}
          </span>
        </td>
        <td data-col="started" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(operation.startedAt ?? operation.createdAt) }}
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <AppButton
            v-if="can.manage && operation.canRetry"
            size="sm"
            variant="ghost"
            @click="retry(operation)"
          >
            {{ t('automation.operations.retry') }}
          </AppButton>
          <AppButton
            v-if="can.manage && operation.needsAttention"
            size="sm"
            variant="ghost"
            @click="resolve(operation)"
          >
            {{ t('automation.operations.resolve') }}
          </AppButton>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="automation"
      :title="t('automation.operations.empty')"
      :description="t('automation.operations.empty_description')"
    />

    <AppPagination :links="operations.links" :total="operations.total" />
  </AdminLayout>
</template>
