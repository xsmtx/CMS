<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import { useTranslations } from '../../../../composables/useTranslations'
import AppInput from '../../../../Components/AppInput.vue'
import AppSelect from '../../../../Components/AppSelect.vue'
import AppTextarea from '../../../../Components/AppTextarea.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

const props = defineProps<{
  group: {
    id: string
    name: string
    slug: string
    description: string | null
    status: string
    position: number
  } | null
  statuses: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const isEditing = computed(() => props.group !== null)

const form = useForm({
  name: props.group?.name ?? '',
  slug: props.group?.slug ?? '',
  description: props.group?.description ?? '',
  status: props.group?.status ?? 'active',
  position: String(props.group?.position ?? 0),
})

function submit(): void {
  form
    .transform((data) => ({ ...data, position: Number(data.position) }))
    [props.group ? 'put' : 'post'](
      props.group ? `/admin/catalog/groups/${props.group.id}` : '/admin/catalog/groups',
    )
}
</script>

<template>
  <Head :title="isEditing ? t('catalog.groups.edit') : t('catalog.groups.new')" />

  <AdminLayout
    :heading="isEditing ? t('catalog.groups.edit') : t('catalog.groups.new')"
    :description="t('catalog.groups.form_intro')"
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <div class="grid gap-5 sm:grid-cols-2">
        <AppInput
          v-model="form.name"
          :label="t('catalog.groups.name')"
          :error="form.errors.name"
          required
        />

        <AppInput
          v-model="form.slug"
          :label="t('catalog.groups.slug')"
          :error="form.errors.slug"
          :hint="t('catalog.groups.slug_hint')"
        />

        <div class="sm:col-span-2">
          <AppTextarea
            v-model="form.description"
            :label="t('catalog.groups.description')"
            :error="form.errors.description"
            :hint="t('catalog.groups.description_hint')"
          />
        </div>

        <AppSelect
          v-model="form.status"
          :label="t('catalog.groups.status')"
          :options="statuses"
          :error="form.errors.status"
          :hint="t('catalog.groups.status_hint')"
        />

        <AppInput
          v-model="form.position"
          :label="t('catalog.groups.position')"
          type="number"
          :error="form.errors.position"
          :hint="t('catalog.groups.position_hint')"
        />
      </div>

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? t('catalog.groups.save') : t('catalog.groups.submit_create') }}
        </AppButton>
        <AppButton href="/admin/catalog/groups" variant="ghost">{{
          t('ui.confirm.cancel')
        }}</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
