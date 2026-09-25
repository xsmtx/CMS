<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppConfirm from '../../../../Components/AppConfirm.vue'
import AppTableRow from '../../../../Components/AppTableRow.vue'
import PageHeader from '../../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../../Components/tableContext'
import { useTranslations } from '../../../../composables/useTranslations'
import AppStatus from '../../../../Components/AppStatus.vue'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../../status'

interface AddonRow {
  id: string
  name: string
  slug: string
  status: string
  position: number
  priceCount: number
}

const props = defineProps<{
  product: { id: string; name: string }
  addons: AddonRow[]
  statuses: { value: string; label: string }[]
  canManage: boolean
}>()

function statusLabel(value: string): string {
  return props.statuses.find((status) => status.value === value)?.label ?? value
}

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'addon', label: t('ui.catalog.addons.column') },
  { key: 'status', label: t('ui.catalog.status') },
  { key: 'prices', label: t('ui.catalog.prices'), numeric: true },
  { key: 'actions', label: '' },
]

const removing = ref<AddonRow | null>(null)

function remove(): void {
  const addon = removing.value

  if (addon === null) return

  router.delete(`/admin/catalog/products/${props.product.id}/addons/${addon.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}
</script>

<template>
  <Head :title="t('ui.catalog.addons.title', { product: product.name })" />

  <AdminLayout :heading="t('ui.catalog.addons.title', { product: product.name })">
    <template #header>
      <PageHeader
        :title="t('ui.catalog.addons.title', { product: product.name })"
        :description="t('ui.catalog.addons.intro')"
      >
        <template #actions>
          <AppButton :href="`/admin/catalog/products/${product.id}/edit`" variant="ghost">
            {{ t('ui.catalog.back_to_product') }}
          </AppButton>
          <AppButton
            v-if="canManage && addons.length > 0"
            :href="`/admin/catalog/products/${product.id}/addons/create`"
            variant="primary"
            icon="add"
          >
            {{ t('ui.catalog.addons.new') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <AppTable v-if="addons.length > 0" name="catalog-addons" :columns="COLUMNS">
      <AppTableRow v-for="addon in addons" :key="addon.id">
        <td data-col="addon">
          <p class="font-medium">{{ addon.name }}</p>
          <p class="text-content-muted text-chrome font-mono">{{ addon.slug }}</p>
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(addon.status)" :label="statusLabel(addon.status)" />
        </td>
        <td
          data-col="prices"
          class="numeric tabular-nums"
          :class="addon.priceCount === 0 ? 'text-danger' : 'text-content-muted'"
        >
          {{ addon.priceCount === 0 ? t('ui.catalog.none') : addon.priceCount }}
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <span class="row-actions inline-flex gap-1">
            <AppButton
              size="sm"
              variant="ghost"
              :href="`/admin/catalog/products/${product.id}/addons/${addon.id}/edit`"
            >
              {{ t('ui.catalog.edit') }}
            </AppButton>
            <AppButton v-if="canManage" size="sm" variant="danger-subtle" @click="removing = addon">
              {{ t('ui.catalog.delete') }}
            </AppButton>
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="catalog"
      :title="t('ui.catalog.addons.empty')"
      :description="t('ui.catalog.addons.empty_detail')"
    >
      <AppButton
        v-if="canManage"
        :href="`/admin/catalog/products/${product.id}/addons/create`"
        variant="primary"
        icon="add"
      >
        {{ t('ui.catalog.addons.new') }}
      </AppButton>
    </EmptyState>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('ui.catalog.addons.delete_title', { name: removing?.name ?? '' })"
      :description="t('ui.catalog.addons.delete_detail')"
      :confirm-label="t('ui.catalog.addons.delete_confirm')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
