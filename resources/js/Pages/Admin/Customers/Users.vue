<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppMenu from '../../../Components/AppMenu.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface UserRow {
  id: string
  firstName: string
  lastName: string
  email: string
  customerId: string
  customer: string | null
  twoFactor: boolean
  lastLoginAt: string | null
}

const props = defineProps<{
  users: { data: UserRow[]; current_page: number; last_page: number; total: number }
  filters: { search: string }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'id', label: t('identity.users.id'), optional: true, offByDefault: true },
  { key: 'first', label: t('identity.users.first_name') },
  { key: 'last', label: t('identity.users.last_name') },
  { key: 'email', label: t('identity.users.email'), sticky: true },
  { key: 'two_factor', label: t('identity.users.two_factor') },
  { key: 'login', label: t('identity.users.last_login') },
  { key: 'actions', label: '' },
]

const search = ref(props.filters.search)
const changing = ref<UserRow | null>(null)

const passwordForm = useForm({ password: '', password_confirmation: '', reason: '' })

function submitSearch(): void {
  router.get('/admin/customer-users', search.value === '' ? {} : { search: search.value }, {
    preserveState: true,
    replace: true,
  })
}

function sendReset(user: UserRow, close: () => void): void {
  close()
  router.post(`/admin/customer-users/${user.id}/reset`, {}, { preserveScroll: true })
}

function startChange(user: UserRow, close: () => void): void {
  close()
  passwordForm.reset()
  passwordForm.clearErrors()
  changing.value = user
}

function savePassword(): void {
  if (changing.value === null) return

  passwordForm.put(`/admin/customer-users/${changing.value.id}/password`, {
    preserveScroll: true,
    onSuccess: () => (changing.value = null),
  })
}

function formatDateTime(value: string | null): string {
  return value === null ? t('identity.users.never') : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('identity.users.title')" />

  <AdminLayout :heading="t('identity.users.title')" :description="t('identity.users.description')">
    <form class="mb-5 max-w-lg" @submit.prevent="submitSearch">
      <div class="flex items-end gap-2">
        <div class="flex-1">
          <AppInput v-model="search" :label="t('identity.users.search')" />
        </div>
        <AppButton type="submit" variant="primary">{{
          t('identity.users.search_action')
        }}</AppButton>
      </div>
      <!-- Under the row rather than under the field: a hint inside the
           input's own column makes it taller than the button beside it,
           and `items-end` then aligns the button to the hint. -->
      <p class="text-content-muted text-chrome mt-2">
        {{ t('identity.users.search_hint') }}
      </p>
    </form>

    <!-- Changing somebody else's password is the most abusable thing a
         support desk can do, so it asks for a reason and says where that
         reason goes. -->
    <div v-if="changing" class="border-line bg-surface-primary mb-6 rounded-lg border p-4">
      <p class="text-body font-semibold">
        {{ t('identity.users.change_for', { name: `${changing.firstName} ${changing.lastName}` }) }}
      </p>
      <p class="text-content-muted text-chrome mt-1">{{ changing.email }}</p>

      <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <AppInput
          v-model="passwordForm.password"
          type="password"
          label="New password"
          autocomplete="new-password"
          :error="passwordForm.errors.password"
        />
        <AppInput
          v-model="passwordForm.password_confirmation"
          type="password"
          label="Confirm password"
          autocomplete="new-password"
        />
        <AppInput
          v-model="passwordForm.reason"
          label="Why"
          hint="Recorded against your name."
          :error="passwordForm.errors.reason"
        />
      </div>

      <p class="text-content-muted text-chrome mt-3 leading-relaxed">
        Every session this person has will end, and they will have to sign in again.
      </p>

      <div class="mt-4 flex gap-2">
        <AppButton variant="primary" :loading="passwordForm.processing" @click="savePassword">
          Change it
        </AppButton>
        <AppButton variant="ghost" @click="changing = null">{{ t('ui.confirm.cancel') }}</AppButton>
      </div>
    </div>

    <AppTable v-if="users.data.length > 0" name="portal-users" :columns="COLUMNS">
      <AppTableRow v-for="user in users.data" :key="user.id">
        <td data-col="id" class="text-content-subtle text-chrome font-mono">
          {{ user.id.slice(-8) }}
        </td>
        <td data-col="first">{{ user.firstName }}</td>
        <td data-col="last">{{ user.lastName }}</td>
        <td data-col="email">
          <span class="font-medium">{{ user.email }}</span>
          <Link
            v-if="user.customer"
            :href="`/admin/customers/${user.customerId}`"
            class="text-content-muted text-chrome block underline-offset-4 hover:underline"
          >
            {{ user.customer }}
          </Link>
        </td>
        <td data-col="two_factor" class="px-4 py-2.5">
          <AppBadge :tone="user.twoFactor ? 'success' : 'neutral'">
            {{ user.twoFactor ? t('identity.users.enabled') : t('identity.users.disabled') }}
          </AppBadge>
        </td>
        <td data-col="login" class="text-content-muted whitespace-nowrap">
          {{ formatDateTime(user.lastLoginAt) }}
        </td>
        <td data-col="actions" class="text-right">
          <!-- The panel is teleported out of the table: an `absolute` one
               is clipped by the table's own horizontal scroll. -->
          <AppMenu v-if="can.manage" v-slot="{ close }" label="Manage user">
            <button
              type="button"
              class="pressable hover:bg-surface-secondary text-body block w-full rounded-sm px-2 py-1.5 text-left"
              role="menuitem"
              @click="sendReset(user, close)"
            >
              Send password reset email
            </button>
            <button
              type="button"
              class="pressable hover:bg-surface-secondary text-body block w-full rounded-sm px-2 py-1.5 text-left"
              role="menuitem"
              @click="startChange(user, close)"
            >
              Change password
            </button>
          </AppMenu>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      :title="t('identity.users.none')"
      :description="t('identity.users.none_description')"
    />

    <p v-if="users.last_page > 1" class="text-content-muted text-chrome mt-4">
      Page {{ users.current_page }} of {{ users.last_page }} — {{ users.total }}
    </p>
  </AdminLayout>
</template>
