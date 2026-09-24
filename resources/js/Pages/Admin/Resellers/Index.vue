<script setup lang="ts">
/**
 * The reseller programme.
 *
 * Three numbers per row, and they are the three questions somebody opens this
 * screen with: how many customers they have, how many products they may
 * actually sell, and what they hold with us.
 *
 * **Balances are per currency and never summed.** A reseller who paid in lira
 * and in euros has two, and adding them is the mistake this platform refuses
 * everywhere else — so the cell is a short list rather than a total.
 *
 * A reseller with nothing they may sell is worth pointing at: it is a real
 * and common state (absence is a refusal here) and it is almost always
 * somebody having created the reseller and not come back.
 */
import { Head, Link } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface Balance {
  currency: string
  amount: string
  minor: number
}

interface ResellerRow {
  id: string
  name: string
  slug: string
  isActive: boolean
  customers: number
  products: number
  balances: Balance[]
  createdAt: string
}

defineProps<{ resellers: ResellerRow[] }>()

const COLUMNS: TableColumn[] = [
  { key: 'name', label: 'Reseller' },
  { key: 'state', label: 'State' },
  { key: 'customers', label: 'Customers', numeric: true },
  { key: 'products', label: 'May sell', numeric: true },
  { key: 'balance', label: 'Balance', numeric: true },
  { key: 'created', label: 'Created', optional: true },
]

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head title="Resellers" />

  <AdminLayout
    heading="Resellers"
    description="Who sells your products under their own name. A reseller owns its customers and sees nothing else."
  >
    <template #actions>
      <AppButton href="/admin/resellers/create" variant="primary">Add reseller</AppButton>
    </template>

    <AppTable v-if="resellers.length > 0" name="resellers" :columns="COLUMNS" noun="reseller">
      <AppTableRow v-for="reseller in resellers" :key="reseller.id">
        <td data-col="name" class="px-4 py-2.5">
          <Link
            :href="`/admin/resellers/${reseller.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ reseller.name }}
          </Link>
          <span class="text-content-subtle text-chrome block font-mono">{{ reseller.slug }}</span>
        </td>
        <td data-col="state" class="px-4 py-2.5">
          <AppStatus
            :tone="reseller.isActive ? 'healthy' : 'unknown'"
            :label="reseller.isActive ? 'Active' : 'Suspended'"
            compact
          />
        </td>
        <td data-col="customers" class="numeric px-4 py-2.5">{{ reseller.customers }}</td>
        <td data-col="products" class="numeric px-4 py-2.5">
          <span v-if="reseller.products > 0">{{ reseller.products }}</span>
          <!-- Absence is a refusal here, so zero is not a quiet default: it
               is a reseller who cannot sell anything, which is nearly always
               somebody who has not finished setting them up. -->
          <AppStatus v-else tone="warning" label="Nothing" compact />
        </td>
        <td data-col="balance" class="numeric px-4 py-2.5">
          <span v-if="reseller.balances.length === 0" class="text-content-subtle">—</span>
          <span
            v-for="balance in reseller.balances"
            :key="balance.currency"
            class="block tabular-nums"
            :class="balance.minor < 0 ? 'text-danger' : ''"
          >
            {{ balance.amount }}
          </span>
        </td>
        <td data-col="created" class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(reseller.createdAt) }}
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="organizations"
      title="No resellers yet"
      description="A reseller sells your catalogue under their own brand, to their own customers, at their own prices. Creating one also creates the person who will run it."
    />
  </AdminLayout>
</template>
