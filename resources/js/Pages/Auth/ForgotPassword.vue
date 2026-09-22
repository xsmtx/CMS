<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string; status?: string | null }>()

const form = useForm({ email: '' })

const action = computed(() =>
  props.guard === 'staff' ? '/admin/forgot-password' : '/forgot-password',
)
const loginUrl = computed(() => (props.guard === 'staff' ? '/admin/login' : '/login'))
</script>

<template>
  <Head title="Reset your password" />

  <AuthLayout
    heading="Reset your password"
    subheading="We will email you a link to choose a new one."
  >
    <AppAlert v-if="status" tone="success" class="mb-6">{{ status }}</AppAlert>

    <form class="flex flex-col gap-5" @submit.prevent="form.post(action)">
      <AppInput
        v-model="form.email"
        label="Email address"
        type="email"
        autocomplete="username"
        :error="form.errors.email"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        Email me a link
      </AppButton>
    </form>

    <a
      :href="loginUrl"
      class="text-content-muted hover:text-content mt-6 inline-block text-sm underline underline-offset-4"
    >
      Back to sign in
    </a>
  </AuthLayout>
</template>
