<script setup lang="ts">
/**
 * Predefined replies: the answer a desk gives forty times a week.
 *
 * `used_count` is shown and never editable. It is the only honest way to
 * answer "which of these is worth keeping", and a number an operator could
 * type would answer nothing.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ReplyRow {
  id: string
  name: string
  body: string
  departmentId: string | null
  department: string | null
  usedCount: number
}

defineProps<{
  replies: ReplyRow[]
  departments: { value: string; label: string }[]
  can: { manage: boolean }
}>()

const editing = ref<string | null>(null)

const form = useForm({ name: '', body: '', department_id: '' })

function startNew(): void {
  editing.value = 'new'
  form.reset()
  form.clearErrors()
}

function startEdit(reply: ReplyRow): void {
  editing.value = reply.id
  form.name = reply.name
  form.body = reply.body
  form.department_id = reply.departmentId ?? ''
  form.clearErrors()
}

function submit(): void {
  const done = { onSuccess: () => (editing.value = null) }

  if (editing.value === 'new') {
    form.post('/admin/support/replies', done)
    return
  }

  form.put(`/admin/support/replies/${editing.value}`, done)
}

function remove(reply: ReplyRow): void {
  router.delete(`/admin/support/replies/${reply.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Predefined replies" />

  <AdminLayout
    heading="Predefined Replies"
    description="The answers a desk gives over and over. A reply tied to a department only appears in that queue."
  >
    <div v-if="can.manage && editing === null" class="mb-6">
      <AppButton variant="primary" @click="startNew">New reply</AppButton>
    </div>

    <AppCard
      v-if="editing !== null"
      class="mb-6"
      :title="editing === 'new' ? 'New reply' : 'Edit reply'"
    >
      <form class="grid max-w-2xl gap-5" @submit.prevent="submit">
        <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />
        <AppSelect
          v-model="form.department_id"
          label="Department"
          hint="Leave blank to offer it in every queue."
          :options="[{ value: '', label: 'Every department' }, ...departments]"
        />
        <AppTextarea v-model="form.body" label="Reply" :rows="8" :error="form.errors.body" />

        <div class="flex gap-2">
          <AppButton type="submit" variant="primary" :loading="form.processing">Save</AppButton>
          <AppButton type="button" variant="ghost" @click="editing = null">Cancel</AppButton>
        </div>
      </form>
    </AppCard>

    <div v-if="replies.length > 0" class="flex flex-col gap-4">
      <AppCard v-for="reply in replies" :key="reply.id">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-[0.9375rem] font-semibold tracking-tight">{{ reply.name }}</h2>
              <AppBadge>{{ reply.department ?? 'Every department' }}</AppBadge>
              <AppBadge tone="neutral">used {{ reply.usedCount }}×</AppBadge>
            </div>
            <p
              class="text-content-muted mt-2 max-w-[80ch] text-sm leading-relaxed whitespace-pre-line"
            >
              {{ reply.body }}
            </p>
          </div>

          <div v-if="can.manage" class="flex gap-2">
            <AppButton size="sm" @click="startEdit(reply)">Edit</AppButton>
            <AppButton size="sm" variant="danger" @click="remove(reply)">Delete</AppButton>
          </div>
        </div>
      </AppCard>
    </div>

    <EmptyState
      v-else
      title="No predefined replies"
      description="Write the answer once here and the ticket screen offers it on every reply box."
    />
  </AdminLayout>
</template>
