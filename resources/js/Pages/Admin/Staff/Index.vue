<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface StaffRow {
  id: string
  name: string
  email: string
  status: string
  twoFactor: boolean
  lastLoginAt: string | null
  roles: string[]
}

const props = defineProps<{
  staff: {
    data: StaffRow[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
  }
  filters: { search: string }
  can: { create: boolean }
}>()

const search = ref(props.filters.search)

let timeout: ReturnType<typeof setTimeout> | undefined

watch(search, (value) => {
  // Debounced so typing does not fire a request per keystroke.
  clearTimeout(timeout)
  timeout = setTimeout(() => {
    router.get('/admin/staff', { search: value }, { preserveState: true, replace: true })
  }, 300)
})

function statusTone(status: string): 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  return status === 'suspended' ? 'warning' : 'danger'
}

function formatTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : 'Never'
}
</script>

<template>
  <Head title="Staff" />

  <AdminLayout heading="Staff" description="People who can sign in to the admin area.">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
      <div class="w-full max-w-xs">
        <AppInput v-model="search" label="Search" placeholder="Name or email address" />
      </div>

      <AppButton v-if="can.create" href="/admin/staff/create" variant="primary">
        Add staff member
      </AppButton>
    </div>

    <EmptyState
      v-if="staff.data.length === 0"
      title="No staff match this search"
      description="Staff accounts are created here and activated through a password reset link, so no password is ever shared."
    />

    <template v-else>
      <AppTable :headers="['Name', 'Roles', 'Status', 'Two-factor', 'Last sign-in', '']">
        <tr v-for="member in staff.data" :key="member.id">
          <td class="px-4 py-3">
            <p class="font-medium">{{ member.name }}</p>
            <p class="text-content-muted text-xs">{{ member.email }}</p>
          </td>
          <td class="text-content-muted px-4 py-3">
            {{ member.roles.length > 0 ? member.roles.join(', ') : 'No roles' }}
          </td>
          <td class="px-4 py-3">
            <AppBadge :tone="statusTone(member.status)">{{ member.status }}</AppBadge>
          </td>
          <td class="px-4 py-3">
            <AppBadge :tone="member.twoFactor ? 'success' : 'neutral'">
              {{ member.twoFactor ? 'On' : 'Off' }}
            </AppBadge>
          </td>
          <td class="text-content-muted px-4 py-3 text-xs">{{ formatTime(member.lastLoginAt) }}</td>
          <td class="px-4 py-3 text-right">
            <Link
              :href="`/admin/staff/${member.id}/edit`"
              class="text-content-muted hover:text-content text-xs underline underline-offset-4"
            >
              Edit
            </Link>
          </td>
        </tr>
      </AppTable>

      <AppPagination :links="staff.links" :total="staff.total" />
    </template>
  </AdminLayout>
</template>
