<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import { useTranslations } from '../../composables/useTranslations'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string }>()

const { t } = useTranslations()

const useRecoveryCode = ref(false)

const form = useForm({
  code: '',
  recovery: false,
})

const action = computed(() =>
  props.guard === 'staff' ? '/admin/two-factor-challenge' : '/two-factor-challenge',
)

const subheading = computed(() =>
  useRecoveryCode.value ? t('ui.auth.two_factor_recovery') : t('ui.auth.two_factor_app'),
)

function toggleMode(): void {
  useRecoveryCode.value = !useRecoveryCode.value
  form.recovery = useRecoveryCode.value
  form.code = ''
  form.clearErrors()
}

function submit(): void {
  form.post(action.value, { onFinish: () => form.reset('code') })
}
</script>

<template>
  <Head :title="t('ui.auth.two_factor_heading')" />

  <AuthLayout :heading="t('ui.auth.two_factor_heading')" :subheading="subheading">
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppInput
        v-model="form.code"
        :label="useRecoveryCode ? t('ui.auth.recovery_code') : t('ui.auth.code')"
        autocomplete="one-time-code"
        :inputmode="useRecoveryCode ? 'text' : 'numeric'"
        :error="form.errors.code"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        {{ t('ui.auth.continue') }}
      </AppButton>
    </form>

    <button
      type="button"
      class="text-content-muted hover:text-content text-body mt-6 underline underline-offset-4"
      @click="toggleMode"
    >
      {{ useRecoveryCode ? t('ui.auth.use_app') : t('ui.auth.use_recovery') }}
    </button>
  </AuthLayout>
</template>
