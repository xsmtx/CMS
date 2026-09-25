<script setup lang="ts">
/**
 * What customers see on the storefront and in their portal.
 *
 * Writing and editing happen in place rather than on a page of their own:
 * an operator announcing a maintenance window is usually looking at the
 * last one they wrote while they do it.
 *
 * Deleting asks first. An announcement is a piece of writing with no draft
 * history behind it, so the only copy is the one on the screen.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface AnnouncementRow {
  id: string
  title: string
  body: string
  visibility: string
  visibilityLabel: string
  publishedAt: string | null
  expiresAt: string | null
  isPinned: boolean
  isScheduled: boolean
}

defineProps<{
  announcements: AnnouncementRow[]
  visibilities: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const editing = ref<string | null>(null)
const composing = ref(false)
const removing = ref<AnnouncementRow | null>(null)

const form = useForm({
  title: '',
  body: '',
  visibility: 'public',
  published_at: '',
  expires_at: '',
  is_pinned: false,
})

// The datetime-local control speaks 'YYYY-MM-DDTHH:mm' and nothing else; an
// ISO string with a zone in it silently leaves the field blank.
function forInput(value: string | null): string {
  return value === null ? '' : value.slice(0, 16)
}

function compose(): void {
  form.reset()
  form.clearErrors()
  editing.value = null
  composing.value = true
}

function edit(announcement: AnnouncementRow): void {
  form.clearErrors()
  form.title = announcement.title
  form.body = announcement.body
  form.visibility = announcement.visibility
  form.published_at = forInput(announcement.publishedAt)
  form.expires_at = forInput(announcement.expiresAt)
  form.is_pinned = announcement.isPinned
  composing.value = false
  editing.value = announcement.id
}

function cancel(): void {
  composing.value = false
  editing.value = null
}

function save(): void {
  const done = { onSuccess: cancel }

  if (editing.value === null) {
    form.post('/admin/content/announcements', done)

    return
  }

  form.put(`/admin/content/announcements/${editing.value}`, done)
}

function remove(): void {
  const announcement = removing.value

  if (announcement === null) return

  router.delete(`/admin/content/announcements/${announcement.id}`, {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('support.announcements.title')" />

  <AdminLayout
    :heading="t('support.announcements.title')"
    :description="t('support.announcements.admin_subtitle')"
  >
    <template #actions>
      <AppButton variant="primary" icon="add" @click="compose">
        {{ t('support.announcements.compose') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="composing || editing !== null"
        :title="
          editing === null
            ? t('support.announcements.compose')
            : t('support.announcements.edit_title')
        "
      >
        <div class="flex max-w-3xl flex-col gap-4">
          <AppInput
            v-model="form.title"
            :label="t('support.announcements.headline')"
            :error="form.errors.title"
          />

          <AppTextarea
            v-model="form.body"
            :label="t('support.announcements.body')"
            :hint="t('support.announcements.body_hint')"
            :rows="10"
            :error="form.errors.body"
          />

          <div class="grid gap-4 sm:grid-cols-3">
            <AppSelect
              v-model="form.visibility"
              :label="t('support.announcements.visibility')"
              :options="visibilities"
              :error="form.errors.visibility"
            />
            <AppInput
              v-model="form.published_at"
              :label="t('support.announcements.published_at')"
              type="datetime-local"
              :hint="t('support.announcements.published_at_hint')"
              :error="form.errors.published_at"
            />
            <AppInput
              v-model="form.expires_at"
              :label="t('support.announcements.expires_at')"
              type="datetime-local"
              :error="form.errors.expires_at"
            />
          </div>

          <AppCheckbox v-model="form.is_pinned" :label="t('support.announcements.pinned')" />

          <div class="flex gap-2">
            <AppButton variant="primary" :loading="form.processing" @click="save">
              {{ t('ui.common.save') }}
            </AppButton>
            <AppButton variant="ghost" @click="cancel">{{ t('ui.confirm.cancel') }}</AppButton>
          </div>
        </div>
      </DetailSection>

      <ul v-if="announcements.length > 0" class="divide-line border-line divide-y border-y">
        <li v-for="announcement in announcements" :key="announcement.id" class="py-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-body flex flex-wrap items-center gap-2 font-medium">
                {{ announcement.title }}
                <AppBadge v-if="announcement.isPinned" tone="brand">
                  {{ t('support.announcements.is_pinned') }}
                </AppBadge>
                <!-- Scheduled and published look identical in a list of rows
                     unless one of them says so. -->
                <AppBadge v-if="announcement.isScheduled" tone="warning">
                  {{ t('support.announcements.scheduled') }}
                </AppBadge>
              </p>
              <p class="text-content-muted text-chrome mt-0.5">
                {{ announcement.visibilityLabel }} ·
                {{ formatDateTime(announcement.publishedAt) }}
              </p>
            </div>

            <div class="flex gap-1">
              <AppButton size="sm" variant="ghost" @click="edit(announcement)">
                {{ t('ui.common.edit') }}
              </AppButton>
              <AppButton size="sm" variant="danger-subtle" @click="removing = announcement">
                {{ t('ui.confirm.delete') }}
              </AppButton>
            </div>
          </div>
        </li>
      </ul>

      <EmptyState
        v-else
        icon="support"
        :title="t('support.announcements.empty')"
        :description="t('support.announcements.admin_empty_description')"
      />
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('support.announcements.delete_title')"
      :description="t('support.announcements.delete_detail', { title: removing?.title ?? '' })"
      :confirm-label="t('ui.confirm.delete')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
