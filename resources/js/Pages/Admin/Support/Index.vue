<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface TicketRow {
  id: string
  number: string
  subject: string
  status: string
  statusLabel: string
  priority: string
  priorityLabel: string
  customer: string | null
  department: string | null
  assignee: string | null
  openedAt: string | null
  lastReplyAt: string | null
  dueAt: string | null
  minutesUntilDue: number | null
  hasBreached: boolean
}

const props = defineProps<{
  tickets: { data: TicketRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null; mine: boolean; breaching: boolean }
  statuses: { value: string; label: string }[]
  counts: { awaiting: number; breaching: number; mine: number }
}>()

const active = computed(() => props.filters.status)

function filterBy(params: Record<string, string | number>): void {
  router.get('/admin/support', params, { preserveState: true, replace: true })
}

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'closed') return 'neutral'
  if (status === 'open' || status === 'customer_reply') return 'warning'
  if (status === 'answered') return 'success'
  return 'neutral'
}

/**
 * A null due date is "not measured", never "overdue". A department that
 * nobody has given an SLA is a real configuration.
 */
function due(ticket: TicketRow): string {
  if (ticket.dueAt === null) return '—'
  if (ticket.hasBreached) return 'Overdue'
  if (ticket.minutesUntilDue === null) return 'Answered'

  const hours = Math.floor(ticket.minutesUntilDue / 60)

  return hours >= 1 ? `${hours}h` : `${ticket.minutesUntilDue}m`
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Tickets" />

  <AdminLayout heading="Tickets" description="Everything waiting for somebody.">
    <div class="mb-6 flex flex-wrap gap-6">
      <button type="button" class="text-left" @click="filterBy({})">
        <p class="text-content-muted text-xs">Waiting for us</p>
        <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ counts.awaiting }}</p>
      </button>
      <button type="button" class="text-left" @click="filterBy({ breaching: 1 })">
        <p class="text-content-muted text-xs">Overdue</p>
        <p
          class="mt-0.5 text-xl font-semibold tabular-nums"
          :class="counts.breaching > 0 ? 'text-danger' : ''"
        >
          {{ counts.breaching }}
        </p>
      </button>
      <button type="button" class="text-left" @click="filterBy({ mine: 1 })">
        <p class="text-content-muted text-xs">Mine</p>
        <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ counts.mine }}</p>
      </button>
    </div>

    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        v-for="status in statuses"
        :key="status.value"
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          active === status.value
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
        "
        @click="filterBy({ status: status.value })"
      >
        {{ status.label }}
      </button>
    </div>

    <AppTable
      v-if="tickets.data.length > 0"
      :headers="['Ticket', 'Customer', 'Department', 'Status', 'Assigned', 'Due']"
    >
      <tr v-for="ticket in tickets.data" :key="ticket.id">
        <td class="px-5 py-3.5">
          <Link
            :href="`/admin/support/${ticket.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ ticket.subject }}
          </Link>
          <span class="text-content-muted block text-xs">
            {{ ticket.number }} · {{ ticket.priorityLabel }}
          </span>
        </td>
        <td class="px-5 py-3.5">{{ ticket.customer ?? '—' }}</td>
        <td class="text-content-muted px-5 py-3.5">{{ ticket.department ?? '—' }}</td>
        <td class="px-5 py-3.5">
          <AppBadge :tone="tone(ticket.status)">{{ ticket.statusLabel }}</AppBadge>
        </td>
        <td class="text-content-muted px-5 py-3.5">{{ ticket.assignee ?? 'Unassigned' }}</td>
        <td class="px-5 py-3.5 whitespace-nowrap" :class="ticket.hasBreached ? 'text-danger' : ''">
          {{ due(ticket) }}
          <span class="text-content-subtle block text-xs">{{
            formatDate(ticket.lastReplyAt)
          }}</span>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing waiting"
      description="Tickets customers open appear here, sorted by what is closest to its deadline."
    />

    <p v-if="tickets.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ tickets.currentPage }} of {{ tickets.lastPage }} — {{ tickets.total }} tickets
    </p>
  </AdminLayout>
</template>
