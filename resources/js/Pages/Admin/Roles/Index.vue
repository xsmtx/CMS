<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface RoleRow {
  id: string
  name: string
  slug: string
  scope: string
  isSystem: boolean
  isSuperAdmin: boolean
  permissionCount: number
}

defineProps<{ roles: RoleRow[] }>()

function remove(role: RoleRow): void {
  router.delete(`/admin/roles/${role.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Roles" />

  <AdminLayout
    heading="Roles"
    description="Capabilities are granted through roles. Staff roles and customer roles are kept apart."
  >
    <div class="mb-5 flex justify-end">
      <AppButton href="/admin/roles/create" variant="primary">Add role</AppButton>
    </div>

    <AppTable :headers="['Role', 'Scope', 'Permissions', '']">
      <tr v-for="role in roles" :key="role.id">
        <td class="px-4 py-3">
          <p class="font-medium">
            {{ role.name }}
            <AppBadge v-if="role.isSystem" class="ml-2">System</AppBadge>
          </p>
          <p class="text-content-muted font-mono text-xs">{{ role.slug }}</p>
        </td>
        <td class="text-content-muted px-4 py-3">{{ role.scope }}</td>
        <td class="text-content-muted px-4 py-3">
          <span v-if="role.isSuperAdmin">
            All, by bypass
            <span class="text-content-subtle block text-xs">
              Grants are not listed; the role skips the check entirely.
            </span>
          </span>
          <span v-else>{{ role.permissionCount }}</span>
        </td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <Link
            :href="`/admin/roles/${role.id}/edit`"
            class="text-content-muted hover:text-content text-xs underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="!role.isSystem"
            type="button"
            class="text-danger ml-3 text-xs underline underline-offset-4"
            @click="remove(role)"
          >
            Delete
          </button>
        </td>
      </tr>
    </AppTable>
  </AdminLayout>
</template>
