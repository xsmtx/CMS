<script setup lang="ts">
/**
 * The worked example for §7's table craft.
 *
 * Every piece of it is here for a reason an operator would recognise:
 *
 * - **Columns.** Nine columns is more than fits on a laptop, and which three
 *   matter depends on the job. Last capture attempt is what a billing clerk
 *   chasing failed payments reads; nobody else ever looks at it, so it is
 *   off until asked for.
 * - **Selection and bulk actions.** Issuing forty drafts one at a time is
 *   forty page loads. Cancelling is a *reason* away, because the audit record
 *   is the only thing that can answer "what happened to this invoice" later.
 * - **Row actions on hover.** Always-visible actions turn a list into a wall
 *   of buttons and the eye stops reading the data.
 * - **A skeleton, not a spinner.** Filtering re-fetches, and a table that
 *   collapsed to a spinner and back would move the page twice per keystroke.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppSelectionBar from '../../../Components/AppSelectionBar.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTableSkeleton from '../../../Components/AppTableSkeleton.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface InvoiceRow {
  id: string
  number: string
  customer: string | null
  status: string
  statusLabel: string
  total: string
  paid: string
  balance: string
  balanceMinor: number
  issuedOn: string | null
  dueOn: string | null
  isPastDue: boolean
  customerId: string | null
  lastCaptureAt: string | null
  lastCaptureOutcome: string | null
  paymentMethod: string | null
}

interface BulkAction {
  value: string
  label: string
  needsReason: boolean
}

const props = defineProps<{
  invoices: { data: InvoiceRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null }
  statuses: { value: string; label: string }[]
  bulkActions: BulkAction[]
  owed: { currency: string; amount: string; count: number }[]
}>()

const { t } = useTranslations()

const active = computed(() => props.filters.status)

/**
 * The keys are the contract with the cells: a `<td data-col="due">` is how a
 * table whose rows are hand-written markup can still have a column hidden.
 *
 * Invoice # and Client name are not optional. A table whose first column can
 * be hidden is rows of numbers belonging to nothing.
 */
const COLUMNS: TableColumn[] = [
  { key: 'number', label: 'Invoice #' },
  { key: 'client', label: 'Client name' },
  { key: 'issued', label: 'Invoice date', optional: true },
  { key: 'due', label: 'Due date' },
  { key: 'capture', label: 'Last capture attempt', optional: true, offByDefault: true },
  { key: 'total', label: 'Total', numeric: true },
  { key: 'method', label: 'Payment method', optional: true, offByDefault: true },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '' },
]

const selected = ref<string[]>([])

const pending = ref<BulkAction | null>(null)
const busy = ref(false)

const rowIds = computed(() => props.invoices.data.map((invoice) => invoice.id))

/** The list is being fetched again — a filter was pressed, or a page. */
const loading = ref(false)

function filterBy(status: string | null): void {
  loading.value = true
  selected.value = []

  router.get('/admin/invoices', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
    onFinish: () => {
      loading.value = false
    },
  })
}

const confirmTitle = computed(() =>
  pending.value === null
    ? ''
    : t(`billing.invoices.bulk.confirm_${pending.value.value}_title`, {
        count: String(selected.value.length),
      }),
)

const confirmBody = computed(() =>
  pending.value === null ? '' : t(`billing.invoices.bulk.confirm_${pending.value.value}_body`),
)

function apply(reason: string | null): void {
  const action = pending.value

  if (action === null) return

  busy.value = true

  router.post(
    '/admin/invoices/bulk',
    { action: action.value, ids: selected.value, reason },
    {
      preserveScroll: true,
      onFinish: () => {
        busy.value = false
        pending.value = null
        // Cleared on the way out, not on the way in: a selection dropped
        // before the request answered would leave an operator unable to
        // retry the rows that failed.
        selected.value = []
      },
    },
  )
}

function formatDateTime(value: string | null): string {
  return value === null ? 'Never' : new Date(value).toLocaleString()
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head title="Invoices" />

  <AdminLayout
    heading="Invoices"
    description="Once issued, an invoice never changes. Corrections are credit notes."
  >
    <!-- Outstanding is grouped by currency, never summed: adding euros to
         lira is the mistake this platform refuses everywhere else. -->
    <div v-if="owed.length > 0" class="mb-6 flex flex-wrap gap-6">
      <div v-for="row in owed" :key="row.currency">
        <p class="text-content-muted text-xs">Outstanding ({{ row.currency }})</p>
        <p class="text-xl font-semibold tabular-nums">{{ row.amount }}</p>
        <p class="text-content-subtle text-xs">{{ row.count }} invoice(s)</p>
      </div>
    </div>

    <AppTableSkeleton v-if="loading" :columns="COLUMNS" selectable :rows="8" />

    <AppTable
      v-else-if="invoices.data.length > 0"
      v-model:selected="selected"
      name="invoices"
      :columns="COLUMNS"
      selectable
      noun="invoice"
      :row-ids="rowIds"
    >
      <!-- The filters go on the table's own strip, beside the columns
           control: both narrow what is in front of you, and a filter row
           somewhere else is a filter an operator does not connect to the
           list it changed. -->
      <template #toolbar>
        <div class="flex flex-wrap gap-1.5">
          <button
            type="button"
            class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
            :class="
              active === null
                ? 'bg-surface-secondary text-content'
                : 'text-content-muted hover:text-content'
            "
            @click="filterBy(null)"
          >
            All
          </button>
          <button
            v-for="status in statuses"
            :key="status.value"
            type="button"
            class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
            :class="
              active === status.value
                ? 'bg-surface-secondary text-content'
                : 'text-content-muted hover:text-content'
            "
            @click="filterBy(status.value)"
          >
            {{ status.label }}
          </button>
        </div>
      </template>

      <template #bulk="{ count, clear }">
        <AppSelectionBar :count="count" noun="invoice" @clear="clear">
          <AppButton
            v-for="action in bulkActions"
            :key="action.value"
            size="sm"
            :variant="action.needsReason ? 'secondary' : 'primary'"
            @click="pending = action"
          >
            {{ action.label }}
          </AppButton>
        </AppSelectionBar>
      </template>

      <AppTableRow
        v-for="invoice in invoices.data"
        :id="invoice.id"
        :key="invoice.id"
        :label="`invoice ${invoice.number}`"
      >
        <td data-col="number" class="px-4 py-2.5">
          <!-- §7: an id is easy to copy. An operator on the phone to a
               customer reads this number out or pastes it into a ticket. -->
          <AppCopy :value="invoice.number" noun="invoice number" />
        </td>
        <td data-col="client" class="px-4 py-2.5">
          <Link
            v-if="invoice.customerId"
            :href="`/admin/customers/${invoice.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ invoice.customer ?? '—' }}
          </Link>
          <span v-else>—</span>
        </td>
        <td data-col="issued" class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(invoice.issuedOn) }}
        </td>
        <td
          data-col="due"
          class="px-4 py-2.5 whitespace-nowrap"
          :class="invoice.isPastDue ? 'text-danger' : 'text-content-muted'"
        >
          {{ formatDate(invoice.dueOn) }}
        </td>
        <td data-col="capture" class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(invoice.lastCaptureAt) }}
          <span v-if="invoice.lastCaptureOutcome" class="block text-xs">
            {{ invoice.lastCaptureOutcome }}
          </span>
        </td>
        <td data-col="total" class="numeric px-4 py-2.5">
          {{ invoice.total }}
          <span
            v-if="invoice.balanceMinor > 0"
            class="text-content-muted block text-xs tabular-nums"
          >
            {{ invoice.balance }} owed
          </span>
        </td>
        <td data-col="method" class="text-content-muted px-4 py-2.5">
          {{ invoice.paymentMethod ?? '—' }}
        </td>
        <td data-col="status" class="px-4 py-2.5">
          <AppBadge>{{ invoice.statusLabel }}</AppBadge>
        </td>
        <td data-col="actions" class="px-4 py-2.5 text-right">
          <!-- `row-actions`: shown on hover, on focus, and on a touch screen
               that has no hover. A control nobody can reveal does not
               exist. -->
          <Link
            :href="`/admin/invoices/${invoice.id}`"
            class="row-actions text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Open
          </Link>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      title="No invoices yet"
      description="An invoice is raised from an order, or by hand. Once issued it keeps its own copy of the customer's details and every amount."
    />

    <p v-if="invoices.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ invoices.currentPage }} of {{ invoices.lastPage }} — {{ invoices.total }} invoices
    </p>

    <!--
      One dialog for both actions. Which level it is comes from the action:
      issuing is consequential and asks once, cancelling is high-risk and
      asks why — and the "why" is the thing the audit record keeps.
    -->
    <AppConfirm
      :open="pending !== null"
      :level="pending?.needsReason ? 'high-risk' : 'consequential'"
      :title="confirmTitle"
      :description="confirmBody"
      :confirm-label="pending?.label"
      :busy="busy"
      @update:open="pending = null"
      @confirm="apply"
    />
  </AdminLayout>
</template>
