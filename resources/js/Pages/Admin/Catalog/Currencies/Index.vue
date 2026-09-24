<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../../Components/AppBadge.vue'
import AppButton from '../../../../Components/AppButton.vue'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

interface CurrencyRow {
  id: string
  code: string
  name: string
  symbol: string | null
  exponent: number
  rate: string
  isBase: boolean
  isActive: boolean
}

defineProps<{ currencies: CurrencyRow[]; canManage: boolean }>()

function remove(currency: CurrencyRow): void {
  router.delete(`/admin/catalog/currencies/${currency.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Currencies" />

  <AdminLayout
    heading="Currencies"
    description="What this installation trades in. Rates are for reporting only — a customer always pays the price entered in their own currency."
  >
    <div v-if="canManage && currencies.length > 0" class="mb-5 flex justify-end">
      <AppButton href="/admin/catalog/currencies/create" variant="primary">Add currency</AppButton>
    </div>

    <AppTable v-if="currencies.length > 0" :headers="['Currency', 'Decimals', 'Rate', '']">
      <tr v-for="currency in currencies" :key="currency.id">
        <td class="px-4 py-2.5">
          <p class="font-medium">
            {{ currency.code }}
            <AppBadge v-if="currency.isBase" class="ml-2">Base</AppBadge>
            <span v-if="!currency.isActive" class="text-content-subtle text-chrome ml-2"
              >Inactive</span
            >
          </p>
          <p class="text-content-muted text-chrome">{{ currency.name }}</p>
        </td>
        <td class="text-content-muted px-4 py-2.5 tabular-nums">{{ currency.exponent }}</td>
        <td class="text-content-muted text-chrome px-4 py-2.5 font-mono tabular-nums">
          {{ currency.rate }}
        </td>
        <td class="px-4 py-2.5 text-right whitespace-nowrap">
          <Link
            :href="`/admin/catalog/currencies/${currency.id}/edit`"
            class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="canManage && !currency.isBase"
            type="button"
            class="text-danger text-chrome ml-3 underline underline-offset-4"
            @click="remove(currency)"
          >
            Delete
          </button>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No currencies yet"
      description="Add the currency you sell in first and mark it as the base. Everything else is quoted against it."
    >
      <AppButton v-if="canManage" href="/admin/catalog/currencies/create" variant="primary">
        Add currency
      </AppButton>
    </EmptyState>
  </AdminLayout>
</template>
