<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'

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

/**
 * What the chosen department is for.
 *
 * An operator wrote that sentence to stop tickets arriving in the wrong
 * queue, and the screen was dropping it: the description has been in the
 * payload since the form was written and nothing drew it.
 */
const departmentHint = computed(
  () =>
    props.departments.find((department) => department.value === form.department_id)?.description,
)
</script>

<template>
  <Head :title="t('support.portal.new_ticket')" />

  <ClientLayout :heading="t('support.portal.new_ticket')">
    <!--
      A form that cannot be submitted is worse than no form. With no
      departments configured there is nowhere for a ticket to go, and the
      server refuses on a field whose list is empty — so the screen says so
      rather than offering an empty required dropdown and a Send button.
    -->
    <EmptyState
      v-if="departments.length === 0"
      icon="support"
      :title="t('support.portal.no_departments')"
      :description="t('support.portal.no_departments_hint')"
    />

    <div v-else class="flex max-w-2xl flex-col gap-4">
      <AppSelect
        v-model="form.department_id"
        :label="t('support.portal.pick_department')"
        :options="departments"
        :hint="departmentHint ?? undefined"
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

      <div class="mt-2">
        <AppButton
          variant="primary"
          :loading="form.processing"
          @click="form.post('/client/support')"
        >
          {{ t('support.portal.submit') }}
        </AppButton>
      </div>
    </div>
  </ClientLayout>
</template>
