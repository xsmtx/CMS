<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppInput from '../../Components/AppInput.vue'
import { useTranslations } from '../../composables/useTranslations'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{
  guard: string
  forgotPasswordUrl: string
  /** Absent when this installation does not let visitors open their own account. */
  registerUrl?: string | null
  status?: string | null
}>()

const { t } = useTranslations()

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const action = computed(() => (props.guard === 'staff' ? '/admin/login' : '/login'))

const heading = computed(() =>
  props.guard === 'staff' ? t('ui.auth.staff_heading') : t('ui.auth.heading'),
)

function submit(): void {
  // The password is cleared whatever the outcome, so a failed attempt never
  // leaves it sitting in the DOM.
  form.post(action.value, { onFinish: () => form.reset('password') })
}
</script>

<template>
  <Head :title="heading" />

  <AuthLayout :heading="heading" :subheading="t('ui.auth.intro')">
    <AppAlert v-if="status" tone="success" class="mb-6">{{ status }}</AppAlert>

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
        :label="t('ui.auth.password')"
        type="password"
        autocomplete="current-password"
        :error="form.errors.password"
        required
      />

      <AppCheckbox v-model="form.remember" :label="t('ui.auth.remember')" />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        {{ t('ui.auth.submit') }}
      </AppButton>
    </form>

    <div class="text-body mt-6 flex flex-col gap-2">
      <a
        :href="forgotPasswordUrl"
        class="text-content-muted hover:text-content inline-block underline underline-offset-4"
      >
        {{ t('ui.auth.forgot') }}
      </a>

      <p v-if="registerUrl" class="text-content-muted">
        {{ t('ui.auth.no_account') }}
        <Link :href="registerUrl" class="text-brand underline underline-offset-4">
          {{ t('ui.auth.register') }}
        </Link>
      </p>
    </div>
  </AuthLayout>
</template>
