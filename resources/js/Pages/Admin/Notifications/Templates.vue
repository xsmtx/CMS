<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface TemplateRow {
  event: string
  label: string
  category: string
  transactional: boolean
  isCustomised: boolean
  templateId: string | null
  subject: string
  body: string
  actionLabel: string | null
  previewSubject: string
  previewBody: string
  placeholders: string[]
}

const props = defineProps<{
  locale: string
  locales: { value: string; label: string }[]
  templates: TemplateRow[]
  can: { manage: boolean }
}>()

const editing = ref<string | null>(null)

const form = useForm({ subject: '', body: '', action_label: '' })

function edit(template: TemplateRow): void {
  editing.value = template.event
  form.subject = template.subject
  form.body = template.body
  form.action_label = template.actionLabel ?? ''
}

function save(event: string): void {
  form.put(`/admin/notifications/templates/${event}/${props.locale}`, {
    preserveScroll: true,
    onSuccess: () => (editing.value = null),
  })
}

function reset(template: TemplateRow): void {
  if (template.templateId === null) return

  router.delete(`/admin/notifications/templates/${template.templateId}`, { preserveScroll: true })
}

function test(event: string): void {
  router.post(
    `/admin/notifications/templates/${event}/${props.locale}/test`,
    {},
    { preserveScroll: true },
  )
}

function switchLocale(locale: string): void {
  router.get('/admin/notifications/templates', { locale }, { preserveState: false })
}
</script>

<template>
  <Head title="Notification templates" />

  <AdminLayout
    heading="Notification templates"
    description="What each message says. Editing one here changes it for every customer."
  >
    <div class="mb-6 flex flex-wrap gap-1.5">
      <button
        v-for="option in locales"
        :key="option.value"
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          locale === option.value
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="switchLocale(option.value)"
      >
        {{ option.label }}
      </button>
    </div>

    <div class="flex flex-col gap-4">
      <AppCard v-for="template in templates" :key="template.event">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold">
              {{ template.label }}
              <AppBadge v-if="template.isCustomised" class="ml-2" tone="brand">Edited</AppBadge>
              <!-- Stated, because an operator editing this should know it
                   goes out whatever the customer has switched off. -->
              <AppBadge v-if="template.transactional" class="ml-2">Always sent</AppBadge>
            </h2>
            <p class="text-content-muted mt-1 text-xs">{{ template.category }}</p>
          </div>

          <div v-if="can.manage" class="flex gap-2">
            <AppButton size="sm" variant="ghost" @click="edit(template)">Edit</AppButton>
            <AppButton size="sm" variant="ghost" @click="test(template.event)">
              Send a test to myself
            </AppButton>
            <AppButton
              v-if="template.isCustomised"
              size="sm"
              variant="ghost"
              @click="reset(template)"
            >
              Reset
            </AppButton>
          </div>
        </div>

        <template v-if="editing === template.event">
          <div class="mt-4 flex flex-col gap-3">
            <AppInput v-model="form.subject" label="Subject" :error="form.errors.subject" />
            <AppTextarea v-model="form.body" label="Body" :rows="6" :error="form.errors.body" />
            <AppInput
              v-model="form.action_label"
              label="Button label"
              :error="form.errors.action_label"
            />

            <p class="text-content-muted text-xs leading-relaxed">
              Placeholders: {{ template.placeholders.map((name) => `:${name}`).join(', ') }} — write
              them as :name. One with no value is left as itself rather than blanked, so a mistake
              is visible.
            </p>
          </div>

          <div class="mt-4 flex gap-2">
            <AppButton variant="primary" :loading="form.processing" @click="save(template.event)">
              Save
            </AppButton>
            <AppButton variant="ghost" @click="editing = null">Cancel</AppButton>
          </div>
        </template>

        <template v-else>
          <div class="border-line mt-4 rounded-[var(--radius-sm)] border p-3">
            <p class="text-sm font-medium">{{ template.previewSubject }}</p>
            <p class="text-content-muted mt-2 text-sm leading-relaxed whitespace-pre-line">
              {{ template.previewBody }}
            </p>
          </div>
        </template>
      </AppCard>
    </div>
  </AdminLayout>
</template>
