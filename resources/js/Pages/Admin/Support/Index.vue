<script setup lang="ts">
/**
 * The queue: everything waiting for somebody.
 *
 * **Status takes more than one value.** "Not answered and not closed" is
 * the question a desk asks all day and a single-select cannot express it,
 * so the filter is a set of toggles.
 *
 * Auto-refresh reloads only the rows, never the whole page: a desk leaves
 * this open on a second monitor, and a full reload would throw away a
 * half-typed filter every minute.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStat from '../../../Components/AppStat.vue'
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
  tags: string[]
}

interface Criteria {
  department: string
  priority: string
  assigned: string
  number: string
  text: string
  email: string
  client: string
  tag: string
}

type Option = { value: string; label: string }

const props = defineProps<{
  tickets: { data: TicketRow[]; currentPage: number; lastPage: number; total: number }
  filters: Partial<Criteria> & { status: string[]; breaching?: string | boolean }
  statuses: Option[]
  priorities: Option[]
  departments: Option[]
  staff: Option[]
  tags: Option[]
  counts: { awaiting: number; breaching: number; mine: number }
}>()

const EMPTY: Criteria = {
  department: '',
  priority: '',
  assigned: '',
  number: '',
  text: '',
  email: '',
  client: '',
  tag: '',
}

const form = ref<Criteria>({ ...EMPTY, ...props.filters })
const chosenStatuses = ref<string[]>([...(props.filters.status ?? [])])
const breaching = ref(Boolean(props.filters.breaching))
const open = ref((Object.keys(EMPTY) as (keyof Criteria)[]).some((key) => form.value[key] !== ''))

const hasFilters = computed(
  () =>
    chosenStatuses.value.length > 0 ||
    breaching.value ||
    (Object.keys(EMPTY) as (keyof Criteria)[]).some((key) => form.value[key] !== ''),
)

function query(): Record<string, string | string[]> {
  const params: Record<string, string | string[]> = {}

  for (const key of Object.keys(EMPTY) as (keyof Criteria)[]) {
    if (form.value[key] !== '') params[key] = form.value[key]
  }

  if (chosenStatuses.value.length > 0) params.status = chosenStatuses.value
  if (breaching.value) params.breaching = '1'

  return params
}

function apply(): void {
  router.get('/admin/support', query(), { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
  chosenStatuses.value = []
  breaching.value = false
  apply()
}

function toggleStatus(value: string): void {
  chosenStatuses.value = chosenStatuses.value.includes(value)
    ? chosenStatuses.value.filter((one) => one !== value)
    : [...chosenStatuses.value, value]

  apply()
}

function only(params: Record<string, string | string[]>): void {
  form.value = { ...EMPTY }
  chosenStatuses.value = []
  breaching.value = false

  router.get('/admin/support', params, { preserveState: true, replace: true })
}

/**
 * Auto-refresh, off by default and remembered per browser.
 *
 * Only the rows and the counts are reloaded. A desk leaves this open on a
 * second monitor, and a full reload every minute would throw away a
 * half-typed filter and scroll them back to the top.
 */
const REFRESH_KEY = 'support.autoRefresh'

const refreshSeconds = ref(readRefresh())
let timer: ReturnType<typeof setInterval> | undefined

function readRefresh(): number {
  try {
    return Number(window.localStorage.getItem(REFRESH_KEY) ?? 0)
  } catch {
    return 0
  }
}

function schedule(): void {
  if (timer) clearInterval(timer)
  if (refreshSeconds.value <= 0) return

  timer = setInterval(() => {
    router.reload({ only: ['tickets', 'counts'] })
  }, refreshSeconds.value * 1000)
}

watch(refreshSeconds, (value) => {
  try {
    window.localStorage.setItem(REFRESH_KEY, String(value))
  } catch {
    // A browser with storage blocked still gets the refresh, it just
    // forgets the choice. Losing a preference is not worth an error.
  }

  schedule()
})

schedule()

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

const REFRESH_OPTIONS: Option[] = [
  { value: '0', label: 'Off' },
  { value: '60', label: 'Every minute' },
  { value: '120', label: 'Every 2 minutes' },
  { value: '300', label: 'Every 5 minutes' },
  { value: '600', label: 'Every 10 minutes' },
  { value: '900', label: 'Every 15 minutes' },
]

const refreshChoice = computed({
  get: () => String(refreshSeconds.value),
  set: (value: string) => (refreshSeconds.value = Number(value)),
})

function withBlank(options: Option[], label = 'Any'): Option[] {
  return [{ value: '', label }, ...options]
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
    <div class="mb-7 flex flex-wrap gap-3">
      <AppStat label="Waiting for us" :value="counts.awaiting" @select="only({})" />
      <AppStat
        label="Past its promise"
        :value="counts.breaching"
        :tone="counts.breaching > 0 ? 'danger' : 'neutral'"
        :active="breaching"
        @select="only({ breaching: '1' })"
      />
      <AppStat label="Mine" :value="counts.mine" @select="only({ assigned: 'mine' })" />
    </div>

    <!-- More than one at a time: "not answered and not closed" is the
         question a desk asks all day. -->
    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        v-for="status in statuses"
        :key="status.value"
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          chosenStatuses.includes(status.value)
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
        "
        :aria-pressed="chosenStatuses.includes(status.value)"
        @click="toggleStatus(status.value)"
      >
        {{ status.label }}
      </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton size="sm" :aria-expanded="open" @click="open = !open">
        {{ open ? 'Hide search' : 'Search / filter' }}
      </AppButton>

      <label class="text-content-muted flex items-center gap-2 text-xs">
        Auto refresh
        <select
          v-model="refreshChoice"
          class="border-line bg-surface-raised text-content rounded-[var(--radius-sm)] border px-2 py-1 text-xs"
        >
          <option v-for="option in REFRESH_OPTIONS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <span v-if="hasFilters" class="text-content-muted text-xs">{{ tickets.total }} match</span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-raised grid gap-4 rounded-[var(--radius-lg)] border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput v-model="form.client" label="Client" />
        <AppSelect v-model="form.department" label="Department" :options="withBlank(departments)" />
        <AppSelect v-model="form.priority" label="Priority" :options="withBlank(priorities)" />
        <AppSelect
          v-model="form.assigned"
          label="Assigned to"
          :options="[
            { value: '', label: 'Anyone' },
            { value: 'mine', label: 'Me' },
            { value: 'unassigned', label: 'Nobody' },
            ...staff,
          ]"
        />
        <AppSelect v-model="form.tag" label="Tags" :options="withBlank(tags)" />
        <AppInput v-model="form.text" label="Subject or message" />
        <AppInput v-model="form.email" label="Email address" />
        <AppInput v-model="form.number" label="Ticket ID or #" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">Search</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">Clear</AppButton>
      </div>
    </form>

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
            <span v-for="tag in ticket.tags" :key="tag" class="text-accent">· {{ tag }}</span>
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
