<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface RoleOption {
  id: string
  name: string
  slug: string
  isSystem: boolean
}

const props = defineProps<{
  member: {
    id: string
    name: string
    email: string
    status: string
    roleIds: string[]
    twoFactor: boolean
  } | null
  roles: RoleOption[]
  statuses: { value: string; label: string }[]
}>()

const isEditing = computed(() => props.member !== null)

const form = useForm({
  name: props.member?.name ?? '',
  email: props.member?.email ?? '',
  status: props.member?.status ?? 'active',
  role_ids: props.member?.roleIds ?? ([] as string[]),
})

function toggleRole(id: string, checked: boolean): void {
  form.role_ids = checked ? [...form.role_ids, id] : form.role_ids.filter((value) => value !== id)
}

function submit(): void {
  if (props.member) {
    form.put(`/admin/staff/${props.member.id}`)
    return
  }

  form.post('/admin/staff')
}

function disableTwoFactor(): void {
  if (!props.member) return
  router.delete(`/admin/staff/${props.member.id}/two-factor`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="isEditing ? 'Edit staff member' : 'Add staff member'" />

  <AdminLayout
    :heading="isEditing ? 'Edit staff member' : 'Add staff member'"
    :description="
      isEditing
        ? 'Changing the status to anything but active ends their current sessions.'
        : 'The account is activated through a password reset link, so no password is shared.'
    "
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppCard title="Account">
        <div class="grid max-w-xl gap-5">
          <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />
          <AppInput
            v-model="form.email"
            label="Email address"
            type="email"
            :error="form.errors.email"
            required
          />
          <AppSelect
            v-model="form.status"
            label="Status"
            :options="statuses"
            :error="form.errors.status"
          />
        </div>
      </AppCard>

      <AppCard title="Roles" description="Capabilities come from roles, never from the account.">
        <AppAlert v-if="roles.length === 0" tone="info">
          No staff roles exist yet. Create one first.
        </AppAlert>

        <div v-else class="grid gap-3 sm:grid-cols-2">
          <AppCheckbox
            v-for="role in roles"
            :key="role.id"
            :model-value="form.role_ids.includes(role.id)"
            :label="role.name"
            :description="role.isSystem ? 'System role' : undefined"
            @update:model-value="(checked: boolean) => toggleRole(role.id, checked)"
          />
        </div>
      </AppCard>

      <AppCard
        v-if="isEditing && member?.twoFactor"
        title="Two-factor authentication"
        description="Turn this off only when the person has lost their authenticator. The action is audited."
      >
        <AppButton variant="danger" type="button" @click="disableTwoFactor">
          Turn off their two-factor
        </AppButton>
      </AppCard>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save changes' : 'Create staff member' }}
        </AppButton>
        <AppButton href="/admin/staff" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
