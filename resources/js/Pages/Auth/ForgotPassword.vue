<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import { useTranslations } from '../../composables/useTranslations'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string; status?: string | null }>()

const { t } = useTranslations()

const form = useForm({ email: '' })

const action = computed(() =>
  props.guard === 'staff' ? '/admin/forgot-password' : '/forgot-password',
)
const loginUrl = computed(() => (props.guard === 'staff' ? '/admin/login' : '/login'))
</script>

<template>
  <Head :title="t('ui.auth.forgot_heading')" />

  <AuthLayout :heading="t('ui.auth.forgot_heading')" :subheading="t('ui.auth.forgot_intro')">
    <AppAlert v-if="status" tone="success" class="mb-6">{{ status }}</AppAlert>

    <form class="flex flex-col gap-5" @submit.prevent="form.post(action)">
      <AppInput
        v-model="form.email"
        :label="t('ui.auth.email')"
        type="email"
        autocomplete="username"
        :error="form.errors.email"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        {{ t('ui.auth.forgot_submit') }}
      </AppButton>
    </form>

    <a
      :href="loginUrl"
      class="text-content-muted hover:text-content text-body mt-6 inline-block underline underline-offset-4"
    >
      {{ t('ui.auth.back') }}
    </a>
  </AuthLayout>
</template>
