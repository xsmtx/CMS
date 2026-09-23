<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { onBeforeUnmount, onMounted, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
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
const openMenu = ref<string | null>(null)
const changing = ref<UserRow | null>(null)

const passwordForm = useForm({ password: '', password_confirmation: '', reason: '' })

function submitSearch(): void {
  router.get('/admin/customer-users', search.value === '' ? {} : { search: search.value }, {
    preserveState: true,
    replace: true,
  })
}

function sendReset(user: UserRow): void {
  openMenu.value = null
  router.post(`/admin/customer-users/${user.id}/reset`, {}, { preserveScroll: true })
}

function startChange(user: UserRow): void {
  openMenu.value = null
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

// Click rather than hover, and closed by anything that is not the menu —
// a row action that stays open over the next row is how an operator resets
// the wrong person's password.
function onDocumentClick(event: MouseEvent): void {
  const target = event.target

  if (target instanceof Element && target.closest('[data-user-menu]') === null) {
    openMenu.value = null
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    openMenu.value = null
    changing.value = null
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})

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
          <AppInput v-model="search" label="User name or email address" />
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
        <td class="text-content-subtle px-4 py-3 font-mono text-xs">{{ user.id.slice(-8) }}</td>
        <td class="px-4 py-3">{{ user.firstName }}</td>
        <td class="px-4 py-3">{{ user.lastName }}</td>
        <td class="px-4 py-3">
          <span class="font-medium">{{ user.email }}</span>
          <Link
            v-if="user.customer"
            :href="`/admin/customers/${user.customerId}`"
            class="text-content-muted block text-xs underline-offset-4 hover:underline"
          >
            {{ user.customer }}
          </Link>
        </td>
        <td class="px-4 py-3">
          <AppBadge :tone="user.twoFactor ? 'success' : 'neutral'">
            {{ user.twoFactor ? 'Enabled' : 'Disabled' }}
          </AppBadge>
        </td>
        <td class="text-content-muted px-4 py-3 whitespace-nowrap">
          {{ formatDateTime(user.lastLoginAt) }}
        </td>
        <td class="relative px-4 py-3 text-right" data-user-menu>
          <button
            v-if="can.manage"
            type="button"
            class="pressable text-content-muted hover:text-content inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2 py-1 text-sm"
            :aria-expanded="openMenu === user.id"
            @click="openMenu = openMenu === user.id ? null : user.id"
          >
            Manage user
            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
              <path
                d="M3 4.5 6 7.5 9 4.5"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </button>

          <div
            v-if="openMenu === user.id"
            class="border-line bg-surface-raised absolute top-full right-4 z-10 mt-1 min-w-[15rem] rounded-[var(--radius-lg)] border p-1 text-left shadow-(--shadow-panel)"
          >
            <button
              type="button"
              class="pressable hover:bg-surface-sunken block w-full rounded-[var(--radius-sm)] px-2 py-1.5 text-left text-sm"
              @click="sendReset(user)"
            >
              Send password reset email
            </button>
            <button
              type="button"
              class="pressable hover:bg-surface-sunken block w-full rounded-[var(--radius-sm)] px-2 py-1.5 text-left text-sm"
              @click="startChange(user)"
            >
              Change password
            </button>
          </div>
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
