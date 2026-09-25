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

interface GroupRow {
  id: string
  name: string
  slug: string
  description: string | null
  status: string
  position: number
  productCount: number
}

defineProps<{ groups: GroupRow[]; statuses: { value: string; label: string }[] }>()

function label(statuses: { value: string; label: string }[], value: string): string {
  return statuses.find((status) => status.value === value)?.label ?? value
}

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'group', label: t('ui.catalog.groups.column') },
  { key: 'status', label: t('ui.catalog.status') },
  { key: 'products', label: t('ui.catalog.groups.products'), numeric: true },
  { key: 'actions', label: '' },
]

/**
 * Deleting used to happen on the first click, from a red underlined word.
 *
 * A group only offers it while nothing is filed under it, so this is a
 * heading on the storefront going and not a product — level 2, and the
 * sentence says which.
 */
const removing = ref<GroupRow | null>(null)

function remove(): void {
  const group = removing.value

  if (group === null) return

  router.delete(`/admin/catalog/groups/${group.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}
</script>

<template>
  <Head :title="t('ui.catalog.groups.title')" />

  <AdminLayout :heading="t('ui.catalog.groups.title')">
    <template #header>
      <PageHeader :title="t('ui.catalog.groups.title')" :description="t('ui.catalog.groups.intro')">
        <template v-if="groups.length > 0" #actions>
          <AppButton href="/admin/catalog/groups/create" variant="primary" icon="add">
            {{ t('ui.catalog.groups.new') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <AppTable v-if="groups.length > 0" name="catalog-groups" :columns="COLUMNS">
      <AppTableRow v-for="group in groups" :key="group.id">
        <td data-col="group">
          <p class="font-medium">{{ group.name }}</p>
          <p class="text-content-muted text-chrome font-mono">{{ group.slug }}</p>
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(group.status)" :label="label(statuses, group.status)" />
        </td>
        <td data-col="products" class="numeric text-content-muted tabular-nums">
          {{ group.productCount }}
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <span class="row-actions inline-flex gap-1">
            <AppButton size="sm" variant="ghost" :href="`/admin/catalog/groups/${group.id}/edit`">
              {{ t('ui.catalog.edit') }}
            </AppButton>
            <AppButton
              v-if="group.productCount === 0"
              size="sm"
              variant="danger-subtle"
              @click="removing = group"
            >
              {{ t('ui.catalog.delete') }}
            </AppButton>
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="catalog"
      :title="t('ui.catalog.groups.empty')"
      :description="t('ui.catalog.groups.empty_detail')"
    >
      <AppButton href="/admin/catalog/groups/create" variant="primary" icon="add">
        {{ t('ui.catalog.groups.new') }}
      </AppButton>
    </EmptyState>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('ui.catalog.groups.delete_title', { name: removing?.name ?? '' })"
      :description="t('ui.catalog.groups.delete_detail')"
      :confirm-label="t('ui.catalog.groups.delete_confirm')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
