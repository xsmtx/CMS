<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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

const { t } = useTranslations()

const isEditing = computed(() => props.member !== null)

const heading = computed(() => (isEditing.value ? t('ui.staff_form.edit') : t('ui.staff_form.add')))

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

/**
 * Turning off somebody else's second factor weakens their account, so it
 * is asked about first rather than done on one press.
 */
const confirmingTwoFactor = ref(false)

function disableTwoFactor(): void {
  if (!props.member) return
  router.delete(`/admin/staff/${props.member.id}/two-factor`, {
    preserveScroll: true,
    onFinish: () => (confirmingTwoFactor.value = false),
  })
}
</script>

<template>
  <Head :title="heading" />

  <AdminLayout :heading="heading">
    <template #header>
      <PageHeader
        :title="heading"
        :description="isEditing ? t('ui.staff_form.intro_edit') : t('ui.staff_form.intro_new')"
      />
    </template>

    <form class="flex max-w-4xl flex-col gap-8" @submit.prevent="submit">
      <DetailSection :title="t('ui.staff_form.account')">
        <div class="grid max-w-xl gap-5">
          <AppInput
            v-model="form.name"
            :label="t('ui.staff_form.name')"
            :error="form.errors.name"
            required
          />
          <AppInput
            v-model="form.email"
            :label="t('ui.client_new.email')"
            type="email"
            :error="form.errors.email"
            required
          />
          <AppSelect
            v-model="form.status"
            :label="t('ui.client_new.status')"
            :options="statuses"
            :error="form.errors.status"
          />
        </div>
      </DetailSection>

      <DetailSection
        :title="t('ui.staff_form.roles')"
        :description="t('ui.staff_form.roles_intro')"
      >
        <AppAlert v-if="roles.length === 0" tone="info">
          {{ t('ui.staff_form.no_roles') }}
        </AppAlert>

        <div v-else class="grid gap-3 sm:grid-cols-2">
          <AppCheckbox
            v-for="role in roles"
            :key="role.id"
            :model-value="form.role_ids.includes(role.id)"
            :label="role.name"
            :description="role.isSystem ? t('ui.staff_form.system_role') : undefined"
            @update:model-value="(checked: boolean) => toggleRole(role.id, checked)"
          />
        </div>
      </DetailSection>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? t('ui.client_form.save') : t('ui.staff_form.create') }}
        </AppButton>
        <AppButton href="/admin/staff" variant="ghost">{{ t('ui.confirm.cancel') }}</AppButton>
      </div>
    </form>

    <!--
      Weakening somebody else's account belongs at the foot of the page and
      not in the run of the form, where it sat between Roles and Save.
    -->
    <DangerZone v-if="isEditing && member?.twoFactor">
      <DangerZoneRow
        :title="t('ui.staff_form.two_factor_title')"
        :description="t('ui.staff_form.two_factor_detail')"
      >
        <AppButton variant="danger-subtle" type="button" @click="confirmingTwoFactor = true">
          {{ t('ui.staff_form.two_factor_button') }}
        </AppButton>
      </DangerZoneRow>
    </DangerZone>

    <AppConfirm
      v-model:open="confirmingTwoFactor"
      level="consequential"
      :title="t('ui.staff_form.two_factor_confirm_title', { name: member?.name ?? '' })"
      :description="t('ui.staff_form.two_factor_confirm_detail')"
      :confirm-label="t('ui.staff_form.two_factor_button')"
      @confirm="disableTwoFactor"
    />
  </AdminLayout>
</template>
