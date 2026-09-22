<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../../Components/AppBadge.vue'
import AppButton from '../../../../Components/AppButton.vue'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

interface ProductRow {
  id: string
  name: string
  slug: string
  type: string
  typeLabel: string
  status: string
  group: string | null
  groupId: string
  stock: number | null
  priceCount: number
}

const props = defineProps<{
  products: ProductRow[]
  statuses: { value: string; label: string }[]
}>()

/** Grouped in the list the way they are grouped on the storefront. */
const sections = computed(() => {
  const byGroup = new Map<string, { name: string; products: ProductRow[] }>()

  for (const product of props.products) {
    const section = byGroup.get(product.groupId) ?? {
      name: product.group ?? 'Ungrouped',
      products: [],
    }
    section.products.push(product)
    byGroup.set(product.groupId, section)
  }

  return [...byGroup.values()]
})

function statusLabel(value: string): string {
  return props.statuses.find((status) => status.value === value)?.label ?? value
}
</script>

<template>
  <Head title="Products" />

  <AdminLayout
    heading="Products"
    description="What customers can buy. A product with no price in a currency is simply not sold in it."
  >
    <div v-if="sections.length > 0" class="mb-5 flex justify-end">
      <AppButton href="/admin/catalog/products/create" variant="primary">New product</AppButton>
    </div>

    <div v-if="sections.length > 0" class="flex flex-col gap-8">
      <section v-for="section in sections" :key="section.name">
        <h2 class="text-content-muted mb-2 text-xs font-medium">{{ section.name }}</h2>

        <AppTable :headers="['Product', 'Type', 'Status', 'Prices', '']">
          <tr v-for="product in section.products" :key="product.id">
            <td class="px-4 py-3">
              <p class="font-medium">{{ product.name }}</p>
              <p class="text-content-muted font-mono text-xs">{{ product.slug }}</p>
            </td>
            <td class="text-content-muted px-4 py-3">{{ product.typeLabel }}</td>
            <td class="px-4 py-3">
              <AppBadge>{{ statusLabel(product.status) }}</AppBadge>
              <span v-if="product.stock === 0" class="text-danger ml-2 text-xs">Sold out</span>
            </td>
            <td
              class="px-4 py-3 tabular-nums"
              :class="product.priceCount === 0 ? 'text-danger' : 'text-content-muted'"
            >
              {{ product.priceCount === 0 ? 'None' : product.priceCount }}
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <Link
                :href="`/admin/catalog/products/${product.id}/edit`"
                class="text-content-muted hover:text-content text-xs underline underline-offset-4"
              >
                Edit
              </Link>
              <Link
                :href="`/admin/catalog/products/${product.id}/pricing`"
                class="text-content-muted hover:text-content ml-3 text-xs underline underline-offset-4"
              >
                Pricing
              </Link>
            </td>
          </tr>
        </AppTable>
      </section>
    </div>

    <EmptyState
      v-else
      title="No products yet"
      description="A product is a thing a customer can order. It needs a group to sit in and at least one price before it can be sold."
    >
      <AppButton href="/admin/catalog/products/create" variant="primary">New product</AppButton>
    </EmptyState>
  </AdminLayout>
</template>
