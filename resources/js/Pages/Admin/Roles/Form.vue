<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface PermissionOption {
  slug: string
  label: string
  description: string | null
  scope: string
  highRisk: boolean
  module: string | null
}

interface PermissionGroup {
  key: string
  label: string
  permissions: PermissionOption[]
}

const props = defineProps<{
  role: {
    id: string
    name: string
    slug: string
    scope: string
    description: string | null
    isSystem: boolean
    isSuperAdmin: boolean
    permissionSlugs: string[]
  } | null
  permissionGroups: PermissionGroup[]
  scopes: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const isEditing = computed(() => props.role !== null)

const form = useForm({
  name: props.role?.name ?? '',
  slug: props.role?.slug ?? '',
  scope: props.role?.scope ?? 'staff',
  description: props.role?.description ?? '',
  permission_slugs: props.role?.permissionSlugs ?? ([] as string[]),
})

// Only permissions matching the selected scope can be granted, and the
// server enforces the same rule regardless of what the form sends.
const visibleGroups = computed(() =>
  props.permissionGroups
    .map((group) => ({
      ...group,
      permissions: group.permissions.filter((permission) => permission.scope === form.scope),
    }))
    .filter((group) => group.permissions.length > 0),
)

function toggle(slug: string, checked: boolean): void {
  form.permission_slugs = checked
    ? [...form.permission_slugs, slug]
    : form.permission_slugs.filter((value) => value !== slug)
}

function submit(): void {
  if (props.role) {
    form.put(`/admin/roles/${props.role.id}`)
    return
  }

  form.post('/admin/roles')
}
</script>

<template>
  <Head :title="isEditing ? t('access.screen.edit') : t('access.screen.add')" />

  <AdminLayout
    :heading="isEditing ? t('access.screen.edit') : t('access.screen.add')"
    :description="t('access.screen.form_intro')"
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <DetailSection :title="t('access.screen.role')">
        <div class="grid max-w-xl gap-5">
          <AppInput
            v-model="form.name"
            :label="t('access.screen.name')"
            :error="form.errors.name"
            required
          />
          <AppInput
            v-model="form.slug"
            :label="t('access.screen.slug')"
            :hint="t('access.screen.slug_hint')"
            :error="form.errors.slug"
            :disabled="role?.isSystem"
            required
          />
          <AppSelect
            v-model="form.scope"
            :label="t('access.screen.scope')"
            :options="scopes"
            :hint="t('access.screen.scope_hint')"
            :error="form.errors.scope"
            :disabled="role?.isSystem"
          />
          <AppTextarea
            v-model="form.description"
            :label="t('access.screen.description')"
            :rows="3"
          />
        </div>
      </DetailSection>

      <AppAlert v-if="role?.isSuperAdmin" tone="info">
        This role bypasses permission checks entirely, so it has no grants to edit. Its use on a
        high-risk capability is recorded in the audit trail.
      </AppAlert>

      <DetailSection v-else :title="t('access.screen.permissions')">
        <div class="flex flex-col gap-6">
          <section v-for="group in visibleGroups" :key="group.key">
            <h3 class="text-content-muted text-chrome mb-3 font-medium">{{ group.label }}</h3>
            <div class="grid gap-3 sm:grid-cols-2">
              <div v-for="permission in group.permissions" :key="permission.slug">
                <!-- The name, then what it lets somebody do. The slug is
                     what the code checks and is kept where somebody
                     debugging can find it, not where somebody deciding
                     has to read it. -->
                <AppCheckbox
                  :model-value="form.permission_slugs.includes(permission.slug)"
                  :label="permission.label"
                  :description="permission.description ?? undefined"
                  @update:model-value="(checked: boolean) => toggle(permission.slug, checked)"
                />
                <div class="mt-1 ml-7 flex flex-wrap items-center gap-2">
                  <AppBadge v-if="permission.highRisk" tone="warning">{{
                    t('access.screen.high_risk')
                  }}</AppBadge>
                  <AppBadge v-if="permission.module" tone="neutral">
                    {{ permission.module }}
                  </AppBadge>
                  <code class="text-content-subtle text-label">{{ permission.slug }}</code>
                </div>
              </div>
            </div>
          </section>
        </div>
      </DetailSection>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save changes' : 'Create role' }}
        </AppButton>
        <AppButton href="/admin/roles" variant="ghost">{{ t('ui.confirm.cancel') }}</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
