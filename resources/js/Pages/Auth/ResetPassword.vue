<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string; token: string; email: string }>()

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
  <Head title="Choose a new password" />

  <AuthLayout
    heading="Choose a new password"
    subheading="Any other devices you are signed in on will be signed out."
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppInput
        v-model="form.email"
        label="Email address"
        type="email"
        autocomplete="username"
        :error="form.errors.email"
        required
      />

      <AppInput
        v-model="form.password"
        label="New password"
        type="password"
        autocomplete="new-password"
        hint="At least 12 characters, with letters, numbers and symbols."
        :error="form.errors.password"
        required
      />

      <AppInput
        v-model="form.password_confirmation"
        label="Confirm new password"
        type="password"
        autocomplete="new-password"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        Set new password
      </AppButton>
    </form>
  </AuthLayout>
</template>
