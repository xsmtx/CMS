<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../../Components/AppBadge.vue'
import AppButton from '../../../../Components/AppButton.vue'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

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

function remove(addon: AddonRow): void {
  router.delete(`/admin/catalog/products/${props.product.id}/addons/${addon.id}`, {
    preserveScroll: true,
  })
}
</script>

<template>
  <Head :title="`Addons — ${product.name}`" />

  <AdminLayout
    :heading="`Addons — ${product.name}`"
    description="Separate lines a customer can add or drop later, each with its own price."
  >
    <div v-if="canManage && addons.length > 0" class="mb-5 flex justify-end">
      <AppButton :href="`/admin/catalog/products/${product.id}/addons/create`" variant="primary">
        New addon
      </AppButton>
    </div>

    <AppTable v-if="addons.length > 0" :headers="['Addon', 'Status', 'Prices', '']">
      <tr v-for="addon in addons" :key="addon.id">
        <td class="px-4 py-2.5">
          <p class="font-medium">{{ addon.name }}</p>
          <p class="text-content-muted font-mono text-xs">{{ addon.slug }}</p>
        </td>
        <td class="px-4 py-2.5">
          <AppBadge>{{ statusLabel(addon.status) }}</AppBadge>
        </td>
        <td
          class="px-4 py-2.5 tabular-nums"
          :class="addon.priceCount === 0 ? 'text-danger' : 'text-content-muted'"
        >
          {{ addon.priceCount === 0 ? 'None' : addon.priceCount }}
        </td>
        <td class="px-4 py-2.5 text-right whitespace-nowrap">
          <Link
            :href="`/admin/catalog/products/${product.id}/addons/${addon.id}/edit`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="canManage"
            type="button"
            class="text-danger ml-3 text-xs underline underline-offset-4"
            @click="remove(addon)"
          >
            Delete
          </button>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No addons yet"
      description="An addon is bought alongside the plan and billed on its own line: a dedicated IP, extra backups, a licence."
    >
      <AppButton
        v-if="canManage"
        :href="`/admin/catalog/products/${product.id}/addons/create`"
        variant="primary"
      >
        New addon
      </AppButton>
    </EmptyState>

    <div class="mt-6">
      <AppButton :href="`/admin/catalog/products/${product.id}/edit`" variant="ghost">
        Back to product
      </AppButton>
    </div>
  </AdminLayout>
</template>
