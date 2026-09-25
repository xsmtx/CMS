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
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

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
  <Head :title="t('organizations.resellers.title')" />

  <AdminLayout
    :heading="t('organizations.resellers.title')"
    :description="t('organizations.resellers.subtitle')"
  >
    <template #actions>
      <AppButton href="/admin/resellers/create" variant="primary">{{
        t('organizations.resellers.add')
      }}</AppButton>
    </template>

    <AppTable
      v-if="resellers.length > 0"
      name="resellers"
      :columns="COLUMNS"
      :noun="t('organizations.resellers.noun')"
    >
      <AppTableRow v-for="reseller in resellers" :key="reseller.id">
        <td data-col="name">
          <Link
            :href="`/admin/resellers/${reseller.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ reseller.name }}
          </Link>
          <span class="text-content-subtle text-chrome block font-mono">{{ reseller.slug }}</span>
        </td>
        <td data-col="state">
          <AppStatus
            :tone="reseller.isActive ? 'healthy' : 'unknown'"
            :label="
              reseller.isActive
                ? t('organizations.resellers.active')
                : t('organizations.resellers.suspended')
            "
            compact
          />
        </td>
        <td data-col="customers" class="numeric">{{ reseller.customers }}</td>
        <td data-col="products" class="numeric">
          <span v-if="reseller.products > 0">{{ reseller.products }}</span>
          <!-- Absence is a refusal here, so zero is not a quiet default: it
               is a reseller who cannot sell anything, which is nearly always
               somebody who has not finished setting them up. -->
          <AppStatus v-else tone="warning" :label="t('organizations.resellers.nothing')" compact />
        </td>
        <td data-col="balance" class="numeric">
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
        <td data-col="created" class="text-content-muted whitespace-nowrap">
          {{ formatDate(reseller.createdAt) }}
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="organizations"
      :title="t('organizations.resellers.empty')"
      :description="t('organizations.resellers.empty_description')"
    />
  </AdminLayout>
</template>
