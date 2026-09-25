<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface PromotionRow {
  id: string
  code: string
  name: string
  type: string
  value: string | null
  scope: string
  isActive: boolean
  usageLimit: number | null
  usageCount: number
  redemptions: number
  endsAt: string | null
}

defineProps<{ promotions: PromotionRow[]; canManage: boolean }>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'code', label: t('catalog.promotions.code'), sticky: true },
  { key: 'value', label: t('catalog.promotions.value'), numeric: true },
  { key: 'scope', label: t('catalog.promotions.scope') },
  { key: 'usage', label: t('catalog.promotions.usage'), numeric: true },
  { key: 'ends', label: t('catalog.promotions.ends') },
  { key: 'actions', label: '' },
]

function usage(promotion: PromotionRow): string {
  return promotion.usageLimit === null
    ? `${promotion.usageCount} used`
    : `${promotion.usageCount} of ${promotion.usageLimit} used`
}

function remove(promotion: PromotionRow): void {
  router.delete(`/admin/promotions/${promotion.id}`, { preserveScroll: true })
}

function formatDate(value: string | null): string {
  return value === null ? 'No end date' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('catalog.promotions.title')" />

  <AdminLayout
    :heading="t('catalog.promotions.title')"
    :description="t('catalog.promotions.subtitle')"
  >
    <div v-if="canManage && promotions.length > 0" class="mb-5 flex justify-end">
      <AppButton href="/admin/promotions/create" variant="primary">{{
        t('catalog.promotions.add')
      }}</AppButton>
    </div>

    <AppTable v-if="promotions.length > 0" name="admin-promotions" :columns="COLUMNS">
      <AppTableRow v-for="promotion in promotions" :key="promotion.id">
        <td data-col="code">
          <p class="text-body font-mono font-medium">
            {{ promotion.code }}
            <AppBadge v-if="!promotion.isActive" class="ml-2">{{
              t('catalog.promotions.inactive')
            }}</AppBadge>
          </p>
          <p class="text-content-muted text-chrome">{{ promotion.name }}</p>
        </td>
        <td data-col="value" class="tabular-nums">{{ promotion.value ?? '—' }}</td>
        <td data-col="scope" class="text-content-muted text-chrome">{{ promotion.scope }}</td>
        <td data-col="usage" class="text-content-muted text-chrome tabular-nums">
          {{ usage(promotion) }}
        </td>
        <td data-col="ends" class="text-content-muted text-chrome">
          {{ formatDate(promotion.endsAt) }}
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <Link
            :href="`/admin/promotions/${promotion.id}/edit`"
            class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="canManage && promotion.redemptions === 0"
            type="button"
            class="text-danger text-chrome ml-3 underline underline-offset-4"
            @click="remove(promotion)"
          >
            Delete
          </button>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      :title="t('catalog.promotions.empty')"
      :description="t('catalog.promotions.empty_description')"
    >
      <AppButton v-if="canManage" href="/admin/promotions/create" variant="primary">
        New promotion
      </AppButton>
    </EmptyState>
  </AdminLayout>
</template>
