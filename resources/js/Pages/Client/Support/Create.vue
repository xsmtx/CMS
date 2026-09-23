<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

const props = defineProps<{
  departments: { value: string; label: string; description: string | null }[]
  services: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const form = useForm({
  department_id: props.departments[0]?.value ?? '',
  service_id: '',
  subject: '',
  body: '',
})

function submit(): void {
  form.post('/client/support')
}
</script>

<template>
  <Head :title="t('support.portal.new_ticket')" />

  <ClientLayout :heading="t('support.portal.new_ticket')">
    <AppCard class="max-w-2xl">
      <div class="flex flex-col gap-4">
        <AppSelect
          v-model="form.department_id"
          :label="t('support.portal.pick_department')"
          :options="departments"
          :error="form.errors.department_id"
        />

        <AppSelect
          v-if="services.length > 0"
          v-model="form.service_id"
          :label="t('support.portal.about')"
          :options="[{ value: '', label: t('support.portal.about_none') }, ...services]"
          :error="form.errors.service_id"
        />

        <AppInput
          v-model="form.subject"
          :label="t('support.tickets.subject')"
          :error="form.errors.subject"
        />

        <AppTextarea
          v-model="form.body"
          :label="t('support.portal.message')"
          :hint="t('support.portal.message_hint')"
          :error="form.errors.body"
          :rows="8"
        />
      </div>

      <div class="mt-6">
        <AppButton variant="primary" :loading="form.processing" @click="submit">
          {{ t('support.portal.submit') }}
        </AppButton>
      </div>
    </AppCard>
  </ClientLayout>
</template>
