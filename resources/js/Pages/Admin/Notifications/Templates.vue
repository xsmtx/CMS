<script setup lang="ts">
/**
 * What each message this installation can send actually says.
 *
 * Read down the list to see the wording a customer will get; open one to
 * change it. The preview is the rendered text rather than the source, so a
 * placeholder that never had a value is visible as itself.
 *
 * The language is a property of the template, not of the operator reading
 * it: switching it reloads the page, because a different locale is a
 * different set of rows.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSegmented from '../../../Components/AppSegmented.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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
  editingLocale: string
  locales: { value: string; label: string }[]
  templates: TemplateRow[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const editing = ref<string | null>(null)
const chosenLocale = ref(props.editingLocale)

const form = useForm({ subject: '', body: '', action_label: '' })

function edit(template: TemplateRow): void {
  editing.value = template.event
  form.subject = template.subject
  form.body = template.body
  form.action_label = template.actionLabel ?? ''
}

function save(event: string): void {
  form.put(`/admin/notifications/templates/${event}/${props.editingLocale}`, {
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
    `/admin/notifications/templates/${event}/${props.editingLocale}/test`,
    {},
    { preserveScroll: true },
  )
}

/** The query parameter is the server's word, not the page's prop name. */
function switchLocale(locale: string): void {
  if (locale === props.editingLocale) return

  router.get('/admin/notifications/templates', { locale }, { preserveState: false })
}
</script>

<template>
  <Head :title="t('notifications.admin.templates_title')" />

  <AdminLayout
    :heading="t('notifications.admin.templates_title')"
    :description="t('notifications.admin.templates_subtitle')"
  >
    <div class="flex flex-col gap-5">
      <AppSegmented
        v-model="chosenLocale"
        :segments="locales.map((option) => ({ value: option.value, label: option.label }))"
        :label="t('notifications.admin.locale')"
        @update:model-value="switchLocale"
      />

      <!-- One surface with hairline-divided rows: a message is an item in a
           list of wordings, not a separate object with a card of its own. -->
      <div class="border-line bg-surface-primary divide-line divide-y rounded-lg border">
        <div v-for="template in templates" :key="template.event" class="p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-body flex flex-wrap items-center gap-2 font-medium">
                {{ template.label }}
                <AppBadge v-if="template.isCustomised" tone="brand">
                  {{ t('notifications.admin.customised') }}
                </AppBadge>
                <!-- Stated, because an operator editing this should know it
                     goes out whatever the customer has switched off. -->
                <AppBadge v-if="template.transactional">
                  {{ t('notifications.admin.always_sent') }}
                </AppBadge>
              </h2>
              <p class="text-content-muted text-chrome mt-0.5">{{ template.category }}</p>
            </div>

            <div v-if="can.manage" class="flex gap-1">
              <AppButton size="sm" variant="ghost" @click="edit(template)">
                {{ t('ui.common.edit') }}
              </AppButton>
              <AppButton size="sm" variant="ghost" @click="test(template.event)">
                {{ t('notifications.admin.send_test') }}
              </AppButton>
              <AppButton
                v-if="template.isCustomised"
                size="sm"
                variant="ghost"
                @click="reset(template)"
              >
                {{ t('notifications.admin.reset') }}
              </AppButton>
            </div>
          </div>

          <template v-if="editing === template.event">
            <div class="mt-4 flex flex-col gap-3">
              <AppInput
                v-model="form.subject"
                :label="t('notifications.admin.subject')"
                :error="form.errors.subject"
              />
              <AppTextarea
                v-model="form.body"
                :label="t('notifications.admin.body')"
                :rows="6"
                :error="form.errors.body"
              />
              <AppInput
                v-model="form.action_label"
                :label="t('notifications.admin.action_label')"
                :error="form.errors.action_label"
              />

              <p class="text-content-muted text-chrome max-w-[80ch] leading-relaxed">
                {{ t('notifications.admin.placeholders') }}:
                {{ template.placeholders.map((name) => `:${name}`).join(', ') }} —
                {{ t('notifications.admin.placeholders_hint') }}
              </p>
            </div>

            <div class="mt-4 flex gap-2">
              <AppButton variant="primary" :loading="form.processing" @click="save(template.event)">
                {{ t('ui.common.save') }}
              </AppButton>
              <AppButton variant="ghost" @click="editing = null">
                {{ t('ui.confirm.cancel') }}
              </AppButton>
            </div>
          </template>

          <div v-else class="border-line bg-surface-secondary mt-3 rounded-sm border p-3">
            <p class="text-body font-medium">{{ template.previewSubject }}</p>
            <p class="text-content-muted text-body mt-2 leading-relaxed whitespace-pre-line">
              {{ template.previewBody }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
