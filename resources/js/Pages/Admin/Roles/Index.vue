<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'role', label: t('access.screen.role'), sticky: true },
  { key: 'scope', label: t('access.screen.scope') },
  { key: 'permissions', label: t('access.screen.permissions') },
  { key: 'actions', label: '' },
]

function remove(role: RoleRow): void {
  router.delete(`/admin/roles/${role.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t('access.screen.title')" />

  <AdminLayout :heading="t('access.screen.title')" :description="t('access.screen.subtitle')">
    <div class="mb-5 flex justify-end">
      <AppButton href="/admin/roles/create" variant="primary">{{
        t('access.screen.add')
      }}</AppButton>
    </div>

    <AppTable name="admin-roles" :columns="COLUMNS">
      <AppTableRow v-for="role in roles" :key="role.id">
        <td data-col="role">
          <p class="font-medium">
            {{ role.name }}
            <AppBadge v-if="role.isSystem" class="ml-2">{{ t('access.screen.system') }}</AppBadge>
          </p>
          <p class="text-content-muted text-chrome font-mono">{{ role.slug }}</p>
        </td>
        <td data-col="scope" class="text-content-muted">{{ role.scope }}</td>
        <td data-col="permissions" class="text-content-muted">
          <span v-if="role.isSuperAdmin">
            All, by bypass
            <span class="text-content-subtle text-chrome block">
              Grants are not listed; the role skips the check entirely.
            </span>
          </span>
          <span v-else>{{ role.permissionCount }}</span>
        </td>
        <td data-col="actions" class="text-right whitespace-nowrap">
          <Link
            :href="`/admin/roles/${role.id}/edit`"
            class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
          >
            Edit
          </Link>
          <button
            v-if="!role.isSystem"
            type="button"
            class="text-danger text-chrome ml-3 underline underline-offset-4"
            @click="remove(role)"
          >
            Delete
          </button>
        </td>
      </AppTableRow>
    </AppTable>
  </AdminLayout>
</template>
