<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../../Components/AppBadge.vue'
import AppButton from '../../../../Components/AppButton.vue'
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

function remove(group: OptionGroupRow): void {
  router.delete(`/admin/catalog/products/${props.product.id}/options/${group.id}`, {
    preserveScroll: true,
  })
}
</script>

<template>
  <Head :title="`Options — ${product.name}`" />

  <AdminLayout
    :heading="`Configurable options — ${product.name}`"
    description="Choices that change what the product is. Each choice is priced as a difference from the product price, so a cheaper choice is a negative number."
  >
    <div v-if="canManage" class="mb-5 flex justify-end">
      <AppButton :href="`/admin/catalog/products/${product.id}/options/create`" variant="primary">
        New option group
      </AppButton>
    </div>

    <AppTable v-if="groups.length > 0" :headers="['Group', 'Type', 'Choices', '']">
      <tr v-for="group in groups" :key="group.id">
        <td class="px-4 py-3">
          <p class="font-medium">
            {{ group.name }}
            <AppBadge v-if="group.isRequired" class="ml-2">Required</AppBadge>
          </p>
          <p class="text-content-muted font-mono text-xs">{{ group.key }}</p>
        </td>
        <td class="text-content-muted px-4 py-3">{{ group.typeLabel }}</td>
        <td class="text-content-muted px-4 py-3 tabular-nums">{{ group.choices }}</td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <Link
            :href="`/admin/catalog/products/${product.id}/options/${group.id}/edit`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="canManage"
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
      title="No configurable options yet"
      description="An option group is a question at checkout: control panel, backup frequency, extra IPs. Each answer can raise or lower the price."
    >
      <AppButton
        v-if="canManage"
        :href="`/admin/catalog/products/${product.id}/options/create`"
        variant="primary"
      >
        New option group
      </AppButton>
    </EmptyState>

    <div class="mt-6">
      <AppButton :href="`/admin/catalog/products/${product.id}/edit`" variant="ghost">
        Back to product
      </AppButton>
    </div>
  </AdminLayout>
</template>
