<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppInput from '../../Components/AppInput.vue'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{
  guard: string
  forgotPasswordUrl: string
  status?: string | null
}>()

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const action = computed(() => (props.guard === 'staff' ? '/admin/login' : '/login'))
const heading = computed(() => (props.guard === 'staff' ? 'Staff sign in' : 'Sign in'))

function submit(): void {
  // The password is cleared whatever the outcome, so a failed attempt never
  // leaves it sitting in the DOM.
  form.post(action.value, { onFinish: () => form.reset('password') })
}
</script>

<template>
  <Head :title="heading" />

  <AuthLayout :heading="heading" subheading="Enter your email address and password to continue.">
    <AppAlert v-if="status" tone="success" class="mb-6">{{ status }}</AppAlert>

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
        label="Password"
        type="password"
        autocomplete="current-password"
        :error="form.errors.password"
        required
      />

      <AppCheckbox v-model="form.remember" label="Keep me signed in on this device" />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        Sign in
      </AppButton>
    </form>

    <a
      :href="forgotPasswordUrl"
      class="text-content-muted hover:text-content text-body mt-6 inline-block underline underline-offset-4"
    >
      Forgot your password?
    </a>
  </AuthLayout>
</template>
