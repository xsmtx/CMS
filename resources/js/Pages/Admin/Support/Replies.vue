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
import DetailSection from '../../../Components/DetailSection.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AppConfirm from '../../../Components/AppConfirm.vue'
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

const { t } = useTranslations()

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

/** Deleting a reply the whole support team uses is asked about first. */
const removing = ref<ReplyRow | null>(null)

function remove(): void {
  if (removing.value === null) return

  router.delete(`/admin/support/replies/${removing.value.id}`, {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  })
}
</script>

<template>
  <Head :title="t('support.replies.title')" />

  <AdminLayout :heading="t('support.replies.title')" :description="t('support.replies.subtitle')">
    <div v-if="can.manage && editing === null" class="mb-6">
      <AppButton variant="primary" @click="startNew">{{ t('support.replies.add') }}</AppButton>
    </div>

    <DetailSection
      v-if="editing !== null"
      class="mb-6"
      :title="editing === 'new' ? t('support.replies.add') : t('support.replies.edit')"
    >
      <form class="grid max-w-2xl gap-5" @submit.prevent="submit">
        <AppInput
          v-model="form.name"
          :label="t('support.replies.name')"
          :error="form.errors.name"
          required
        />
        <AppSelect
          v-model="form.department_id"
          :label="t('support.replies.department')"
          :hint="t('support.replies.department_hint')"
          :options="[{ value: '', label: t('support.replies.every_department') }, ...departments]"
        />
        <AppTextarea
          v-model="form.body"
          :label="t('support.replies.body')"
          :rows="8"
          :error="form.errors.body"
        />

        <div class="flex gap-2">
          <AppButton type="submit" variant="primary" :loading="form.processing">{{
            t('support.replies.save')
          }}</AppButton>
          <AppButton type="button" variant="ghost" @click="editing = null">{{
            t('ui.confirm.cancel')
          }}</AppButton>
        </div>
      </form>
    </DetailSection>

    <div v-if="replies.length > 0" class="flex flex-col gap-4">
      <div
        v-for="reply in replies"
        :key="reply.id"
        class="border-line bg-surface-primary rounded-lg border p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-title font-semibold tracking-tight">{{ reply.name }}</h2>
              <AppBadge>{{ reply.department ?? t('support.replies.every_department') }}</AppBadge>
              <AppBadge tone="neutral">{{
                t('support.replies.used', { count: reply.usedCount })
              }}</AppBadge>
            </div>
            <p
              class="text-content-muted text-body mt-2 max-w-[80ch] leading-relaxed whitespace-pre-line"
            >
              {{ reply.body }}
            </p>
          </div>

          <div v-if="can.manage" class="flex gap-2">
            <AppButton size="sm" @click="startEdit(reply)">{{
              t('support.replies.edit_action')
            }}</AppButton>
            <AppButton size="sm" variant="danger-subtle" @click="removing = reply">{{
              t('support.replies.delete')
            }}</AppButton>
          </div>
        </div>
      </div>
    </div>

    <EmptyState
      v-else
      icon="ticket"
      :title="t('support.replies.empty')"
      :description="t('support.replies.empty_description')"
    />
    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="`Delete the reply “${removing?.name ?? ''}”?`"
      description="Staff stop seeing it in the reply picker. Tickets it was already used in keep their text."
      confirm-label="Delete reply"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
