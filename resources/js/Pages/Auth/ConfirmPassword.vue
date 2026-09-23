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
 * the thing it is asking for.
 */
import { Head, useForm } from '@inertiajs/vue3'

import AppButton from '../../Components/AppButton.vue'
import AppIcon from '../../Components/AppIcon.vue'
import AppInput from '../../Components/AppInput.vue'
import { useBranding } from '../../composables/useBranding'
import { useTranslations } from '../../composables/useTranslations'

const props = defineProps<{ guard: string; intended: string | null }>()

const { brand } = useBranding()
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

  <div class="bg-background grid min-h-dvh place-items-center px-4 py-12">
    <div class="w-full max-w-sm">
      <div class="mb-6 flex items-center gap-2">
        <span
          class="bg-brand text-content-inverse grid size-7 shrink-0 place-items-center rounded-[6px] text-xs font-bold"
          aria-hidden="true"
        >
          {{ brand.name.slice(0, 1).toUpperCase() }}
        </span>
        <span class="text-title font-semibold">{{ brand.name }}</span>
      </div>

      <div
        class="border-line bg-surface-primary rounded-[var(--radius-lg)] border p-5 shadow-(--shadow-raised)"
      >
        <div class="mb-4 flex items-start gap-3">
          <span class="text-warning mt-0.5 shrink-0" aria-hidden="true">
            <AppIcon name="security" :size="18" />
          </span>
          <div>
            <h1 class="text-title font-semibold">{{ t('identity.auth.confirm_title') }}</h1>
            <!-- Why, and for how long. Both, before the field. -->
            <p class="text-content-muted text-chrome mt-1 leading-relaxed">
              {{ t('identity.auth.confirm_body', { minutes: '15' }) }}
            </p>
          </div>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <AppInput
            v-model="form.password"
            label="Password"
            type="password"
            autocomplete="current-password"
            :error="form.errors.password"
            required
          />

          <AppButton type="submit" variant="primary" :loading="form.processing">
            Confirm
          </AppButton>
        </form>
      </div>

      <p v-if="intended" class="text-content-subtle text-label mt-4 text-center">
        You will be returned to where you were.
      </p>
    </div>
  </div>
</template>
