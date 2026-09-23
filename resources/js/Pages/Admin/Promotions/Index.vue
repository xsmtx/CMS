<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
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
  <Head title="Promotions" />

  <AdminLayout
    heading="Promotions"
    description="Discount codes and the terms they carry. A code that has been redeemed is deactivated rather than deleted, so the record of what customers paid stays readable."
  >
    <div v-if="canManage && promotions.length > 0" class="mb-5 flex justify-end">
      <AppButton href="/admin/promotions/create" variant="primary">New promotion</AppButton>
    </div>

    <AppTable
      v-if="promotions.length > 0"
      :headers="['Code', 'Value', 'Scope', 'Usage', 'Ends', '']"
    >
      <tr v-for="promotion in promotions" :key="promotion.id">
        <td class="px-5 py-3.5">
          <p class="font-mono text-sm font-medium">
            {{ promotion.code }}
            <AppBadge v-if="!promotion.isActive" class="ml-2">Inactive</AppBadge>
          </p>
          <p class="text-content-muted text-xs">{{ promotion.name }}</p>
        </td>
        <td class="px-5 py-3.5 tabular-nums">{{ promotion.value ?? '—' }}</td>
        <td class="text-content-muted px-5 py-3.5 text-xs">{{ promotion.scope }}</td>
        <td class="text-content-muted px-5 py-3.5 text-xs tabular-nums">{{ usage(promotion) }}</td>
        <td class="text-content-muted px-5 py-3.5 text-xs">{{ formatDate(promotion.endsAt) }}</td>
        <td class="px-5 py-3.5 text-right whitespace-nowrap">
          <Link
            :href="`/admin/promotions/${promotion.id}/edit`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="canManage && promotion.redemptions === 0"
            type="button"
            class="text-danger ml-3 text-xs underline underline-offset-4"
            @click="remove(promotion)"
          >
            Delete
          </button>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No promotions yet"
      description="A promotion is a code a customer types at checkout. It can take a percentage or a fixed amount off, for one payment or for every renewal."
    >
      <AppButton v-if="canManage" href="/admin/promotions/create" variant="primary">
        New promotion
      </AppButton>
    </EmptyState>
  </AdminLayout>
</template>
