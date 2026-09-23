<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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

const props = defineProps<{
  invoices: { data: InvoiceRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null }
  statuses: { value: string; label: string }[]
  owed: { currency: string; amount: string; count: number }[]
}>()

const active = computed(() => props.filters.status)

function filterBy(status: string | null): void {
  router.get('/admin/invoices', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
  })
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

    <div class="mb-5 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
        :class="
          active === null
            ? 'bg-surface-sunken text-content'
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
            ? 'bg-surface-sunken text-content'
            : 'text-content-muted hover:text-content'
        "
        @click="filterBy(status.value)"
      >
        {{ status.label }}
      </button>
    </div>

    <AppTable
      v-if="invoices.data.length > 0"
      :headers="[
        'Invoice #',
        'Client name',
        'Invoice date',
        'Due date',
        'Last capture attempt',
        'Total',
        'Payment method',
        'Status',
        '',
      ]"
    >
      <tr v-for="invoice in invoices.data" :key="invoice.id">
        <td class="px-4 py-2.5 font-mono text-xs">{{ invoice.number }}</td>
        <td class="px-4 py-2.5">
          <Link
            v-if="invoice.customerId"
            :href="`/admin/customers/${invoice.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ invoice.customer ?? '—' }}
          </Link>
          <span v-else>—</span>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(invoice.issuedOn) }}
        </td>
        <td
          class="px-4 py-2.5 whitespace-nowrap"
          :class="invoice.isPastDue ? 'text-danger' : 'text-content-muted'"
        >
          {{ formatDate(invoice.dueOn) }}
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDateTime(invoice.lastCaptureAt) }}
          <span v-if="invoice.lastCaptureOutcome" class="block text-xs">
            {{ invoice.lastCaptureOutcome }}
          </span>
        </td>
        <td class="px-4 py-2.5 tabular-nums">
          {{ invoice.total }}
          <span
            v-if="invoice.balanceMinor > 0"
            class="text-content-muted block text-xs tabular-nums"
          >
            {{ invoice.balance }} owed
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5">{{ invoice.paymentMethod ?? '—' }}</td>
        <td class="px-4 py-2.5">
          <AppBadge>{{ invoice.statusLabel }}</AppBadge>
        </td>
        <td class="px-4 py-2.5 text-right">
          <Link
            :href="`/admin/invoices/${invoice.id}`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Open
          </Link>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No invoices yet"
      description="An invoice is raised from an order, or by hand. Once issued it keeps its own copy of the customer's details and every amount."
    />

    <p v-if="invoices.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ invoices.currentPage }} of {{ invoices.lastPage }} — {{ invoices.total }} invoices
    </p>
  </AdminLayout>
</template>
