<script setup lang="ts">
/**
 * What the provider sold through its resellers.
 *
 * One row per reseller, never a total across them: the provider's boundary is
 * the whole tree, so a grand total is a number that answers nothing anybody
 * asked. And money is grouped by currency — a reseller selling in lira and
 * euros has two figures, and there is no rate here to make them one.
 *
 * The other half of "reseller reports" is the dashboard: a reseller signing in
 * already sees their own numbers, because every query in this panel is narrowed
 * by the boundary.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface MoneyRow {
  currency: string
  amount: string
  minor: number
}

interface ReportRow {
  id: string
  name: string
  slug: string
  customers: number
  orders: number
  services: number
  recurring: MoneyRow[]
  invoiced: MoneyRow[]
  balances: MoneyRow[]
}

const props = defineProps<{
  period: { from: string; to: string }
  rows: ReportRow[]
}>()

const COLUMNS: TableColumn[] = [
  { key: 'reseller', label: 'Reseller' },
  { key: 'customers', label: 'Customers', numeric: true },
  { key: 'orders', label: 'Orders', numeric: true },
  { key: 'services', label: 'Active services', numeric: true },
  { key: 'recurring', label: 'Recurring / month', numeric: true },
  { key: 'invoiced', label: 'Invoiced', numeric: true },
  { key: 'balance', label: 'Balance', numeric: true, optional: true },
]

const period = reactive({ from: props.period.from, to: props.period.to })

function apply(): void {
  router.get('/admin/reports/resellers', { ...period }, { preserveState: true, replace: true })
}
</script>

<template>
  <Head title="Reseller performance" />

  <AdminLayout
    heading="Reseller performance"
    description="What the platform sold through each reseller. One row each, and never a total across them."
  >
    <AppTable v-if="rows.length > 0" name="reseller-report" :columns="COLUMNS" noun="reseller">
      <template #toolbar>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
          <AppInput v-model="period.from" label="From" type="date" />
          <AppInput v-model="period.to" label="To" type="date" />
          <AppButton type="submit" size="sm">Apply</AppButton>
        </form>
      </template>

      <AppTableRow v-for="row in rows" :key="row.id">
        <td data-col="reseller" class="px-4 py-2.5">
          <Link
            :href="`/admin/resellers/${row.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ row.name }}
          </Link>
        </td>
        <td data-col="customers" class="numeric px-4 py-2.5">{{ row.customers }}</td>
        <td data-col="orders" class="numeric px-4 py-2.5">{{ row.orders }}</td>
        <td data-col="services" class="numeric px-4 py-2.5">{{ row.services }}</td>

        <!-- A list rather than a figure: two currencies is two answers, and
             adding them is the mistake this platform refuses everywhere. -->
        <td data-col="recurring" class="numeric px-4 py-2.5">
          <span v-if="row.recurring.length === 0" class="text-content-subtle">—</span>
          <span v-for="money in row.recurring" :key="money.currency" class="block tabular-nums">
            {{ money.amount }}
          </span>
        </td>
        <td data-col="invoiced" class="numeric px-4 py-2.5">
          <span v-if="row.invoiced.length === 0" class="text-content-subtle">—</span>
          <span v-for="money in row.invoiced" :key="money.currency" class="block tabular-nums">
            {{ money.amount }}
          </span>
        </td>
        <td data-col="balance" class="numeric px-4 py-2.5">
          <span v-if="row.balances.length === 0" class="text-content-subtle">—</span>
          <span
            v-for="money in row.balances"
            :key="money.currency"
            class="block tabular-nums"
            :class="money.minor < 0 ? 'text-danger' : ''"
          >
            {{ money.amount }}
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="organizations"
      title="No resellers to report on"
      description="Create a reseller and the numbers they sell appear here, one row each."
    />
  </AdminLayout>
</template>
