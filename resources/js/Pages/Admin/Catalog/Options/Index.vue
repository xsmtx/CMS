<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../../Components/AppBadge.vue'
import AppButton from '../../../../Components/AppButton.vue'
import AppConfirm from '../../../../Components/AppConfirm.vue'
import AppTableRow from '../../../../Components/AppTableRow.vue'
import PageHeader from '../../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../../Components/tableContext'
import { useTranslations } from '../../../../composables/useTranslations'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

interface OptionGroupRow {
  id: string
  name: string
  key: string
  type: string
  typeLabel: string
  isRequired: boolean
  position: number
  choices: number
}

const props = defineProps<{
  product: { id: string; name: string }
  groups: OptionGroupRow[]
  canManage: boolean
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'group', label: t('ui.catalog.options.column') },
  { key: 'type', label: t('ui.catalog.type') },
  { key: 'choices', label: t('ui.catalog.options.choices'), numeric: true },
  { key: 'actions', label: '' },
]

const removing = ref<OptionGroupRow | null>(null)

function remove(): void {
  const group = removing.value

  if (group === null) return

  router.delete(`/admin/catalog/products/${props.product.id}/options/${group.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}
</script>

<template>
  <Head :title="t('ui.catalog.options.head', { product: product.name })" />

  <AdminLayout :heading="t('ui.catalog.options.title', { product: product.name })">
    <template #header>
      <PageHeader
        :title="t('ui.catalog.options.title', { product: product.name })"
        :description="t('ui.catalog.options.intro')"
      >
        <template #actions>
          <AppButton :href="`/admin/catalog/products/${product.id}/edit`" variant="ghost">
            {{ t('ui.catalog.back_to_product') }}
          </AppButton>
          <AppButton
            v-if="canManage && groups.length > 0"
            :href="`/admin/catalog/products/${product.id}/options/create`"
            variant="primary"
            icon="add"
          >
            {{ t('ui.catalog.options.new') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <AppTable v-if="groups.length > 0" name="catalog-options" :columns="COLUMNS">
      <AppTableRow v-for="group in groups" :key="group.id">
        <td data-col="group">
          <p class="flex flex-wrap items-center gap-x-2 font-medium">
            <span>{{ group.name }}</span>
            <AppBadge v-if="group.isRequired">{{ t('ui.catalog.options.required') }}</AppBadge>
          </p>
          <p class="text-content-muted text-chrome font-mono">{{ group.key }}</p>
        </td>
        <td data-col="type" class="text-content-muted">{{ group.typeLabel }}</td>
        <td data-col="choices" class="numeric text-content-muted tabular-nums">
          {{ group.choices }}
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <span class="row-actions inline-flex gap-1">
            <AppButton
              size="sm"
              variant="ghost"
              :href="`/admin/catalog/products/${product.id}/options/${group.id}/edit`"
            >
              {{ t('ui.catalog.edit') }}
            </AppButton>
            <AppButton v-if="canManage" size="sm" variant="danger-subtle" @click="removing = group">
              {{ t('ui.catalog.delete') }}
            </AppButton>
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="catalog"
      :title="t('ui.catalog.options.empty')"
      :description="t('ui.catalog.options.empty_detail')"
    >
      <AppButton
        v-if="canManage"
        :href="`/admin/catalog/products/${product.id}/options/create`"
        variant="primary"
        icon="add"
      >
        {{ t('ui.catalog.options.new') }}
      </AppButton>
    </EmptyState>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('ui.catalog.options.delete_title', { name: removing?.name ?? '' })"
      :description="t('ui.catalog.options.delete_detail')"
      :confirm-label="t('ui.catalog.options.delete_confirm')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
