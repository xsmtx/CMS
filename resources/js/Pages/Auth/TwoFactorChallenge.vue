<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string }>()

const useRecoveryCode = ref(false)

const form = useForm({
  code: '',
  recovery: false,
})

const action = computed(() =>
  props.guard === 'staff' ? '/admin/two-factor-challenge' : '/two-factor-challenge',
)

const subheading = computed(() =>
  useRecoveryCode.value
    ? 'Enter one of the recovery codes you saved when you turned this on.'
    : 'Enter the six-digit code from your authenticator app.',
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
  <Head title="Two-factor authentication" />

  <AuthLayout heading="Two-factor authentication" :subheading="subheading">
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppInput
        v-model="form.code"
        :label="useRecoveryCode ? 'Recovery code' : 'Authentication code'"
        autocomplete="one-time-code"
        :error="form.errors.code"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        Continue
      </AppButton>
    </form>

    <button
      type="button"
      class="text-content-muted hover:text-content text-body mt-6 underline underline-offset-4"
      @click="toggleMode"
    >
      {{ useRecoveryCode ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
    </button>
  </AuthLayout>
</template>
