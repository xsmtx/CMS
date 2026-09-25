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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'currency', label: t('ui.catalog.currencies.column') },
  { key: 'decimals', label: t('ui.catalog.currencies.decimals'), numeric: true },
  { key: 'rate', label: t('ui.catalog.currencies.rate'), numeric: true },
  { key: 'actions', label: '' },
]

const removing = ref<CurrencyRow | null>(null)

function remove(): void {
  const currency = removing.value

  if (currency === null) return

  router.delete(`/admin/catalog/currencies/${currency.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}
</script>

<template>
  <Head :title="t('ui.catalog.currencies.title')" />

  <AdminLayout :heading="t('ui.catalog.currencies.title')">
    <template #header>
      <PageHeader
        :title="t('ui.catalog.currencies.title')"
        :description="t('ui.catalog.currencies.intro')"
      >
        <template v-if="canManage && currencies.length > 0" #actions>
          <AppButton href="/admin/catalog/currencies/create" variant="primary" icon="add">
            {{ t('ui.catalog.currencies.new') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <AppTable v-if="currencies.length > 0" name="catalog-currencies" :columns="COLUMNS">
      <AppTableRow v-for="currency in currencies" :key="currency.id">
        <td data-col="currency">
          <p class="flex flex-wrap items-center gap-x-2 font-medium">
            <span>{{ currency.code }}</span>
            <AppBadge v-if="currency.isBase">{{ t('ui.catalog.currencies.base') }}</AppBadge>
            <span v-if="!currency.isActive" class="text-content-subtle text-chrome">
              {{ t('ui.catalog.currencies.inactive') }}
            </span>
          </p>
          <p class="text-content-muted text-chrome">{{ currency.name }}</p>
        </td>
        <td data-col="decimals" class="numeric text-content-muted tabular-nums">
          {{ currency.exponent }}
        </td>
        <td data-col="rate" class="numeric text-content-muted text-chrome font-mono tabular-nums">
          {{ currency.rate }}
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <span class="row-actions inline-flex gap-1">
            <AppButton
              size="sm"
              variant="ghost"
              :href="`/admin/catalog/currencies/${currency.id}/edit`"
            >
              {{ t('ui.catalog.edit') }}
            </AppButton>
            <AppButton
              v-if="canManage && !currency.isBase"
              size="sm"
              variant="danger-subtle"
              @click="removing = currency"
            >
              {{ t('ui.catalog.delete') }}
            </AppButton>
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="billing"
      :title="t('ui.catalog.currencies.empty')"
      :description="t('ui.catalog.currencies.empty_detail')"
    >
      <AppButton
        v-if="canManage"
        href="/admin/catalog/currencies/create"
        variant="primary"
        icon="add"
      >
        {{ t('ui.catalog.currencies.new') }}
      </AppButton>
    </EmptyState>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('ui.catalog.currencies.delete_title', { code: removing?.code ?? '' })"
      :description="t('ui.catalog.currencies.delete_detail')"
      :confirm-label="t('ui.catalog.currencies.delete_confirm')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
