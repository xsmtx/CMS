<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppMenu from '../../../Components/AppMenu.vue'
import AppTable from '../../../Components/AppTable.vue'
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
  return value === null ? 'Never' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Manage users" />

  <AdminLayout
    heading="Manage users"
    description="Everyone who can sign into the customer area. This is the list you open when somebody cannot get in."
  >
    <form class="mb-5 max-w-lg" @submit.prevent="submitSearch">
      <div class="flex items-end gap-2">
        <div class="flex-1">
          <AppInput
            v-model="search"
            label="User name or email address"
            hint="A full name works. % anchors: Zeyn% or %nep."
          />
        </div>
        <AppButton type="submit" variant="primary">Search</AppButton>
      </div>
    </form>

    <!-- Changing somebody else's password is the most abusable thing a
         support desk can do, so it asks for a reason and says where that
         reason goes. -->
    <div
      v-if="changing"
      class="border-line bg-surface-raised mb-6 rounded-[var(--radius-lg)] border p-4"
    >
      <p class="text-sm font-semibold">
        Change the password for {{ changing.firstName }} {{ changing.lastName }}
      </p>
      <p class="text-content-muted mt-1 text-xs">{{ changing.email }}</p>

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

      <p class="text-content-muted mt-3 text-xs leading-relaxed">
        Every session this person has will end, and they will have to sign in again.
      </p>

      <div class="mt-4 flex gap-2">
        <AppButton variant="primary" :loading="passwordForm.processing" @click="savePassword">
          Change it
        </AppButton>
        <AppButton variant="ghost" @click="changing = null">Cancel</AppButton>
      </div>
    </div>

    <AppTable
      v-if="users.data.length > 0"
      :headers="['ID', 'First name', 'Last name', 'Email address', 'Two factor', 'Last login', '']"
    >
      <tr v-for="user in users.data" :key="user.id">
        <td class="text-content-subtle px-5 py-3.5 font-mono text-xs">{{ user.id.slice(-8) }}</td>
        <td class="px-5 py-3.5">{{ user.firstName }}</td>
        <td class="px-5 py-3.5">{{ user.lastName }}</td>
        <td class="px-5 py-3.5">
          <span class="font-medium">{{ user.email }}</span>
          <Link
            v-if="user.customer"
            :href="`/admin/customers/${user.customerId}`"
            class="text-content-muted block text-xs underline-offset-4 hover:underline"
          >
            {{ user.customer }}
          </Link>
        </td>
        <td class="px-5 py-3.5">
          <AppBadge :tone="user.twoFactor ? 'success' : 'neutral'">
            {{ user.twoFactor ? 'Enabled' : 'Disabled' }}
          </AppBadge>
        </td>
        <td class="text-content-muted px-5 py-3.5 whitespace-nowrap">
          {{ formatDateTime(user.lastLoginAt) }}
        </td>
        <td class="px-5 py-3.5 text-right">
          <!-- The panel is teleported out of the table: an `absolute` one
               is clipped by the table's own horizontal scroll. -->
          <AppMenu v-if="can.manage" v-slot="{ close }" label="Manage user">
            <button
              type="button"
              class="pressable hover:bg-surface-sunken block w-full rounded-[var(--radius-sm)] px-2 py-1.5 text-left text-sm"
              role="menuitem"
              @click="sendReset(user, close)"
            >
              Send password reset email
            </button>
            <button
              type="button"
              class="pressable hover:bg-surface-sunken block w-full rounded-[var(--radius-sm)] px-2 py-1.5 text-left text-sm"
              role="menuitem"
              @click="startChange(user, close)"
            >
              Change password
            </button>
          </AppMenu>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No users match"
      description="Only contacts with portal access appear here — a person on a customer's record who was never given a login is not a user."
    />

    <p v-if="users.last_page > 1" class="text-content-muted mt-4 text-xs">
      Page {{ users.current_page }} of {{ users.last_page }} — {{ users.total }}
    </p>
  </AdminLayout>
</template>
