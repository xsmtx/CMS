<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppTableRow from '../../../../Components/AppTableRow.vue'
import PageHeader from '../../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../../Components/tableContext'
import { useTranslations } from '../../../../composables/useTranslations'
import AppStatus from '../../../../Components/AppStatus.vue'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../../status'

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
const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'product', label: t('ui.catalog.products.column') },
  { key: 'type', label: t('ui.catalog.type') },
  { key: 'status', label: t('ui.catalog.status') },
  { key: 'prices', label: t('ui.catalog.prices'), numeric: true },
  { key: 'actions', label: '' },
]

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
  <Head :title="t('ui.catalog.products.title')" />

  <AdminLayout :heading="t('ui.catalog.products.title')">
    <template #header>
      <PageHeader
        :title="t('ui.catalog.products.title')"
        :description="t('ui.catalog.products.intro')"
      >
        <template v-if="sections.length > 0" #actions>
          <AppButton href="/admin/catalog/products/create" variant="primary" icon="add">
            {{ t('ui.catalog.products.new') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div v-if="sections.length > 0" class="flex flex-col gap-8">
      <!-- One table per group, because the group is how an operator thinks
           about the catalogue and a single list of everything is not. -->
      <section v-for="section in sections" :key="section.name">
        <h2 class="text-content-subtle text-label mb-2 uppercase">{{ section.name }}</h2>

        <AppTable :name="`catalog-products-${section.name}`" :columns="COLUMNS">
          <AppTableRow v-for="product in section.products" :key="product.id">
            <td data-col="product">
              <p class="font-medium">{{ product.name }}</p>
              <p class="text-content-muted text-chrome font-mono">{{ product.slug }}</p>
            </td>
            <td data-col="type" class="text-content-muted">{{ product.typeLabel }}</td>
            <td data-col="status">
              <span class="flex flex-wrap items-center gap-x-2">
                <AppStatus
                  :tone="statusTone(product.status)"
                  :label="statusLabel(product.status)"
                />
                <span v-if="product.stock === 0" class="text-danger text-chrome">
                  {{ t('ui.catalog.products.sold_out') }}
                </span>
              </span>
            </td>
            <td
              data-col="prices"
              class="numeric tabular-nums"
              :class="product.priceCount === 0 ? 'text-danger' : 'text-content-muted'"
            >
              {{ product.priceCount === 0 ? t('ui.catalog.none') : product.priceCount }}
            </td>
            <td data-col="actions" class="text-right whitespace-nowrap">
              <span class="row-actions inline-flex gap-1">
                <AppButton
                  size="sm"
                  variant="ghost"
                  :href="`/admin/catalog/products/${product.id}/edit`"
                >
                  {{ t('ui.catalog.edit') }}
                </AppButton>
                <AppButton
                  size="sm"
                  variant="ghost"
                  :href="`/admin/catalog/products/${product.id}/pricing`"
                >
                  {{ t('ui.catalog.pricing') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </section>
    </div>

    <EmptyState
      v-else
      icon="catalog"
      :title="t('ui.catalog.products.empty')"
      :description="t('ui.catalog.products.empty_detail')"
    >
      <AppButton href="/admin/catalog/products/create" variant="primary" icon="add">
        {{ t('ui.catalog.products.new') }}
      </AppButton>
    </EmptyState>
  </AdminLayout>
</template>
