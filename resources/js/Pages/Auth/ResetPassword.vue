<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import { useTranslations } from '../../composables/useTranslations'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string; token: string; email: string }>()

const { t } = useTranslations()

const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

const action = computed(() =>
  props.guard === 'staff' ? '/admin/reset-password' : '/reset-password',
)

function submit(): void {
  form.post(action.value, {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head :title="t('ui.auth.reset_heading')" />

  <AuthLayout :heading="t('ui.auth.reset_heading')" :subheading="t('ui.auth.reset_intro')">
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppInput
        v-model="form.email"
        :label="t('ui.auth.email')"
        type="email"
        autocomplete="username"
        :error="form.errors.email"
        required
      />

      <AppInput
        v-model="form.password"
        :label="t('ui.auth.new_password')"
        type="password"
        autocomplete="new-password"
        :hint="t('ui.auth.new_password_hint')"
        :error="form.errors.password"
        required
      />

      <AppInput
        v-model="form.password_confirmation"
        :label="t('ui.auth.new_password_again')"
        type="password"
        autocomplete="new-password"
        :error="form.errors.password_confirmation"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        {{ t('ui.auth.reset_submit') }}
      </AppButton>
    </form>
  </AuthLayout>
</template>
