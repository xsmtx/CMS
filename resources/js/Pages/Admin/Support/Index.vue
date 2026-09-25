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

import AppPagination from '../../../Components/AppPagination.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStat from '../../../Components/AppStat.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

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
  tickets: {
    data: TicketRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: Partial<Criteria> & { status: string[]; breaching?: string | boolean }
  statuses: Option[]
  priorities: Option[]
  departments: Option[]
  staff: Option[]
  tags: Option[]
  counts: { awaiting: number; breaching: number; mine: number }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'ticket', label: t('support.tickets.ticket'), sticky: true },
  { key: 'customer', label: t('support.tickets.customer') },
  { key: 'department', label: t('support.tickets.department') },
  { key: 'status', label: t('support.tickets.status') },
  { key: 'assigned', label: t('support.tickets.assigned') },
  { key: 'due', label: t('support.tickets.due') },
]

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

/**
 * A null due date is "not measured", never "overdue". A department that
 * nobody has given an SLA is a real configuration.
 */
function due(ticket: TicketRow): string {
  if (ticket.dueAt === null) return '—'
  if (ticket.hasBreached) return t('support.tickets.overdue')
  if (ticket.minutesUntilDue === null) return t('support.tickets.answered')

  const hours = Math.floor(ticket.minutesUntilDue / 60)

  return hours >= 1 ? `${hours}h` : `${ticket.minutesUntilDue}m`
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('support.tickets.title')" />

  <AdminLayout :heading="t('support.tickets.title')" :description="t('support.tickets.subtitle')">
    <div class="mb-7 flex flex-wrap gap-3">
      <AppStat :label="t('support.tickets.awaiting')" :value="counts.awaiting" @select="only({})" />
      <AppStat
        :label="t('support.tickets.breaching')"
        :value="counts.breaching"
        :tone="counts.breaching > 0 ? 'danger' : 'neutral'"
        :active="breaching"
        @select="only({ breaching: '1' })"
      />
      <AppStat
        :label="t('support.tickets.mine')"
        :value="counts.mine"
        @select="only({ assigned: 'mine' })"
      />
    </div>

    <!-- More than one at a time: "not answered and not closed" is the
         question a desk asks all day. -->
    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        v-for="status in statuses"
        :key="status.value"
        type="button"
        class="pressable text-chrome rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          chosenStatuses.includes(status.value)
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        :aria-pressed="chosenStatuses.includes(status.value)"
        @click="toggleStatus(status.value)"
      >
        {{ status.label }}
      </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton size="sm" :aria-expanded="open" @click="open = !open">
        {{ open ? t('support.tickets.hide_search') : t('support.tickets.search_filter') }}
      </AppButton>

      <label class="text-content-muted text-chrome flex items-center gap-2">
        {{ t('support.tickets.auto_refresh') }}
        <select
          v-model="refreshChoice"
          class="border-line bg-surface-primary text-content text-chrome rounded-sm border px-2 py-1"
        >
          <option v-for="option in REFRESH_OPTIONS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <span v-if="hasFilters" class="text-content-muted text-chrome">{{
        t('support.tickets.matches', { count: tickets.total })
      }}</span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput v-model="form.client" :label="t('support.tickets.client')" />
        <AppSelect
          v-model="form.department"
          :label="t('support.tickets.department')"
          :options="withBlank(departments)"
        />
        <AppSelect
          v-model="form.priority"
          :label="t('support.tickets.priority')"
          :options="withBlank(priorities)"
        />
        <AppSelect
          v-model="form.assigned"
          :label="t('support.tickets.assigned_to')"
          :options="[
            { value: '', label: t('support.tickets.anyone') },
            { value: 'mine', label: t('support.tickets.me') },
            { value: 'unassigned', label: t('support.tickets.nobody') },
            ...staff,
          ]"
        />
        <AppSelect
          v-model="form.tag"
          :label="t('support.tickets.tags')"
          :options="withBlank(tags)"
        />
        <AppInput v-model="form.text" :label="t('support.tickets.text_search')" />
        <AppInput v-model="form.email" :label="t('support.tickets.email')" />
        <AppInput v-model="form.number" :label="t('support.tickets.number_search')" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">{{ t('support.tickets.search') }}</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">{{
          t('support.tickets.clear')
        }}</AppButton>
      </div>
    </form>

    <AppTable v-if="tickets.data.length > 0" name="admin-tickets" :columns="COLUMNS">
      <AppTableRow v-for="ticket in tickets.data" :key="ticket.id">
        <td data-col="ticket">
          <Link
            :href="`/admin/support/${ticket.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ ticket.subject }}
          </Link>
          <span class="text-content-muted text-chrome block">
            {{ ticket.number }} · {{ ticket.priorityLabel }}
            <span v-for="tag in ticket.tags" :key="tag" class="text-brand">· {{ tag }}</span>
          </span>
        </td>
        <td data-col="customer">{{ ticket.customer ?? '—' }}</td>
        <td data-col="department" class="text-content-muted">{{ ticket.department ?? '—' }}</td>
        <td data-col="status">
          <AppStatus :tone="statusTone(ticket.status)" :label="ticket.statusLabel" />
        </td>
        <td data-col="assigned" class="text-content-muted">
          {{ ticket.assignee ?? t('support.tickets.unassigned') }}
        </td>
        <td
          data-col="due"
          class="px-4 py-2.5 whitespace-nowrap"
          :class="ticket.hasBreached ? 'text-danger' : ''"
        >
          {{ due(ticket) }}
          <span class="text-content-subtle text-chrome block">{{
            formatDate(ticket.lastReplyAt)
          }}</span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="ticket"
      :title="t('support.tickets.nothing_waiting')"
      :description="t('support.tickets.nothing_waiting_description')"
    />

    <AppPagination :links="tickets.links" :total="tickets.total" />
  </AdminLayout>
</template>
