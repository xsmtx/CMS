<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../../Components/AppBadge.vue'
import AppButton from '../../../../Components/AppButton.vue'
import AppTable from '../../../../Components/AppTable.vue'
import EmptyState from '../../../../Components/EmptyState.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

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

function remove(group: GroupRow): void {
  router.delete(`/admin/catalog/groups/${group.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Product groups" />

  <AdminLayout
    heading="Product groups"
    description="How products are arranged on the storefront. A group holding products cannot be deleted; retire it to take it off the menu."
  >
    <div class="mb-5 flex justify-end">
      <AppButton href="/admin/catalog/groups/create" variant="primary">New group</AppButton>
    </div>

    <AppTable v-if="groups.length > 0" :headers="['Group', 'Status', 'Products', '']">
      <tr v-for="group in groups" :key="group.id">
        <td class="px-4 py-3">
          <p class="font-medium">{{ group.name }}</p>
          <p class="text-content-muted font-mono text-xs">{{ group.slug }}</p>
        </td>
        <td class="px-4 py-3">
          <AppBadge>{{ label(statuses, group.status) }}</AppBadge>
        </td>
        <td class="text-content-muted px-4 py-3 tabular-nums">{{ group.productCount }}</td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <Link
            :href="`/admin/catalog/groups/${group.id}/edit`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="group.productCount === 0"
            type="button"
            class="text-danger ml-3 text-xs underline underline-offset-4"
            @click="remove(group)"
          >
            Delete
          </button>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No product groups yet"
      description="A group is a heading on the storefront: Shared Hosting, VPS, Domains. Products are filed under one."
    >
      <AppButton href="/admin/catalog/groups/create" variant="primary">New group</AppButton>
    </EmptyState>
  </AdminLayout>
</template>
