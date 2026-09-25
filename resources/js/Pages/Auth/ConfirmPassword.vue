<script setup lang="ts">
/**
 * "It is still you, isn't it."
 *
 * The screen says **why** before it asks, because an operator presented with a
 * password field out of nowhere concludes something is broken and goes looking
 * for the bug rather than typing.
 *
 * It also says how long the answer lasts. A confirmation with no stated window
 * is one people expect on every action, and the expectation is what makes them
 * leave the window open all afternoon instead.
 *
 * Deliberately narrow and deliberately plain — no navigation, no other actions.
 * This is the one screen in the product where the only correct thing to do is
 * the thing it is asking for, which is what `AuthLayout` is: one column, the
 * brand, and nothing else. It used to draw its own brand mark and its own
 * raised card, which made it the only screen in the product with a shadow on
 * something that is not floating.
 */
import { Head, useForm } from '@inertiajs/vue3'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppInput from '../../Components/AppInput.vue'
import { useTranslations } from '../../composables/useTranslations'
import AuthLayout from '../../Layouts/AuthLayout.vue'

const props = defineProps<{ guard: string; intended: string | null }>()

const { t } = useTranslations()

const form = useForm({ password: '' })

const action = props.guard === 'staff' ? '/admin/confirm-password' : '/confirm-password'

function submit(): void {
  form.post(action, {
    // The field is cleared whichever way it goes: a password left in a form
    // after a failure is a password in the browser's autofill history.
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <Head :title="t('identity.auth.confirm_title')" />

  <AuthLayout :heading="t('identity.auth.confirm_title')">
    <!-- Why, and for how long. Both, before the field. -->
    <AppAlert tone="warning" class="mb-6">
      {{ t('identity.auth.confirm_body', { minutes: '15' }) }}
    </AppAlert>

    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppInput
        v-model="form.password"
        :label="t('ui.auth.password')"
        type="password"
        autocomplete="current-password"
        :error="form.errors.password"
        required
      />

      <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
        {{ t('ui.auth.confirm_submit') }}
      </AppButton>
    </form>

    <p v-if="intended" class="text-content-muted text-chrome mt-6">
      {{ t('ui.auth.confirm_return') }}
    </p>
  </AuthLayout>
</template>
