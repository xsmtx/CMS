<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppCard from '../../../../Components/AppCard.vue'
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
  <Head :title="isEditing ? 'Edit group' : 'New group'" />

  <AdminLayout
    :heading="isEditing ? 'Edit group' : 'New group'"
    description="Groups are the headings the storefront is organised by."
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />

          <AppInput
            v-model="form.slug"
            label="Slug"
            :error="form.errors.slug"
            hint="Used in the storefront URL. Left empty, it is derived from the name."
          />

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.description"
              label="Description"
              :error="form.errors.description"
              hint="Shown under the heading on the storefront."
            />
          </div>

          <AppSelect
            v-model="form.status"
            label="Status"
            :options="statuses"
            :error="form.errors.status"
            hint="Hidden keeps the group off the menu but reachable by direct link."
          />

          <AppInput
            v-model="form.position"
            label="Position"
            type="number"
            :error="form.errors.position"
            hint="Lower numbers appear first."
          />
        </div>
      </AppCard>

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save group' : 'Create group' }}
        </AppButton>
        <AppButton href="/admin/catalog/groups" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
