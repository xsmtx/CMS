<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface StaffRow {
  id: string
  name: string
  email: string
  status: string
  statusLabel: string
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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'name', label: t('identity.staff.name'), sticky: true },
  { key: 'roles', label: t('identity.staff.roles') },
  { key: 'status', label: t('identity.staff.status') },
  { key: 'two_factor', label: t('identity.staff.two_factor') },
  { key: 'login', label: t('identity.staff.last_login') },
  { key: 'actions', label: '' },
]

const search = ref(props.filters.search)

let timeout: ReturnType<typeof setTimeout> | undefined

watch(search, (value) => {
  // Debounced so typing does not fire a request per keystroke.
  clearTimeout(timeout)
  timeout = setTimeout(() => {
    router.get('/admin/staff', { search: value }, { preserveState: true, replace: true })
  }, 300)
})

function formatTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : t('identity.staff.never')
}
</script>

<template>
  <Head :title="t('identity.staff.title')" />

  <AdminLayout :heading="t('identity.staff.title')" :description="t('identity.staff.subtitle')">
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
      :title="t('identity.staff.empty')"
      :description="t('identity.staff.empty_description')"
    />

    <template v-else>
      <AppTable name="admin-staff" :columns="COLUMNS">
        <AppTableRow v-for="member in staff.data" :key="member.id">
          <td data-col="name">
            <p class="font-medium">{{ member.name }}</p>
            <p class="text-content-muted text-chrome">{{ member.email }}</p>
          </td>
          <td data-col="roles" class="text-content-muted">
            {{ member.roles.length > 0 ? member.roles.join(', ') : t('identity.staff.no_roles') }}
          </td>
          <td data-col="status">
            <AppStatus :tone="statusTone(member.status)" :label="member.statusLabel" />
          </td>
          <td data-col="two_factor">
            <!-- On or off is a state, so it carries a shape rather than a
                 colour alone. -->
            <AppStatus
              :tone="member.twoFactor ? 'healthy' : 'neutral'"
              :label="member.twoFactor ? t('identity.staff.on') : t('identity.staff.off')"
            />
          </td>
          <td data-col="login" class="text-content-muted text-chrome">
            {{ formatTime(member.lastLoginAt) }}
          </td>
          <td data-col="actions" class="text-right">
            <Link
              :href="`/admin/staff/${member.id}/edit`"
              class="text-content-muted hover:text-content text-chrome underline underline-offset-4"
            >
              {{ t('identity.staff.edit') }}
            </Link>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="staff.links" :total="staff.total" />
    </template>
  </AdminLayout>
</template>
