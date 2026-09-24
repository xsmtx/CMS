<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface AnnouncementRow {
  id: string
  title: string
  body: string
  visibility: string
  publishedAt: string | null
  expiresAt: string | null
  isPinned: boolean
  isScheduled: boolean
}

defineProps<{
  announcements: AnnouncementRow[]
  visibilities: { value: string; label: string }[]
}>()

const editing = ref<string | null>(null)
const composing = ref(false)

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

function remove(announcement: AnnouncementRow): void {
  router.delete(`/admin/content/announcements/${announcement.id}`, { preserveScroll: true })
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Announcements" />

  <AdminLayout
    heading="Announcements"
    description="What customers see on the storefront and in their portal."
  >
    <div class="mb-6">
      <AppButton variant="primary" @click="compose">Write an announcement</AppButton>
    </div>

    <AppCard v-if="composing || editing !== null" class="mb-6 max-w-3xl">
      <div class="flex flex-col gap-4">
        <AppInput v-model="form.title" label="Title" :error="form.errors.title" />

        <AppTextarea
          v-model="form.body"
          label="Body"
          hint="Markdown. Everything else is escaped before it is rendered."
          :rows="10"
          :error="form.errors.body"
        />

        <div class="grid gap-4 sm:grid-cols-3">
          <AppSelect
            v-model="form.visibility"
            label="Who can read it"
            :options="visibilities"
            :error="form.errors.visibility"
          />
          <AppInput
            v-model="form.published_at"
            label="Publish at"
            type="datetime-local"
            hint="Leave empty to publish now."
            :error="form.errors.published_at"
          />
          <AppInput
            v-model="form.expires_at"
            label="Stop showing at"
            type="datetime-local"
            :error="form.errors.expires_at"
          />
        </div>

        <AppCheckbox v-model="form.is_pinned" label="Pin to the top" />
      </div>

      <div class="mt-6 flex gap-2">
        <AppButton variant="primary" :loading="form.processing" @click="save">Save</AppButton>
        <AppButton variant="ghost" @click="cancel">Cancel</AppButton>
      </div>
    </AppCard>

    <ul v-if="announcements.length > 0" class="divide-line border-line divide-y border-y">
      <li v-for="announcement in announcements" :key="announcement.id" class="py-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-body font-semibold">
              {{ announcement.title }}
              <AppBadge v-if="announcement.isPinned" class="ml-2" tone="brand">Pinned</AppBadge>
              <!-- Scheduled and published look identical in a list of rows
                   unless one of them says so. -->
              <AppBadge v-if="announcement.isScheduled" class="ml-2" tone="warning">
                Scheduled
              </AppBadge>
            </p>
            <p class="text-content-muted text-chrome mt-1">
              {{ announcement.visibility }} · {{ formatDate(announcement.publishedAt) }}
            </p>
          </div>

          <div class="flex gap-2">
            <AppButton size="sm" variant="ghost" @click="edit(announcement)">Edit</AppButton>
            <AppButton size="sm" variant="ghost" @click="remove(announcement)">Delete</AppButton>
          </div>
        </div>
      </li>
    </ul>

    <EmptyState
      v-else
      title="Nothing announced"
      description="Maintenance windows, price changes and outages belong here — customers see them without asking."
    />
  </AdminLayout>
</template>
