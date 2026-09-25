<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCopy from '../../Components/AppCopy.vue'
import AppInput from '../../Components/AppInput.vue'
import DetailSection from '../../Components/DetailSection.vue'
import { useTranslations } from '../../composables/useTranslations'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import ClientLayout from '../../Layouts/ClientLayout.vue'

const props = defineProps<{
  guard: string
  qrCode: string
  secret: string
  confirmed: boolean
}>()

const { t } = useTranslations()

const layout = computed(() => (props.guard === 'staff' ? AdminLayout : ClientLayout))
const base = computed(() => (props.guard === 'staff' ? '/admin/security' : '/security'))

const form = useForm({ code: '' })

function confirm(): void {
  form.post(`${base.value}/two-factor/confirm`, { onFinish: () => form.reset('code') })
}
</script>

<template>
  <Head :title="t('ui.auth.two_factor_heading')" />

  <component
    :is="layout"
    :heading="t('ui.auth.two_factor_heading')"
    :description="t('ui.security.setup_intro')"
  >
    <DetailSection :title="t('ui.security.setup_title')" :divided="false">
      <div class="grid gap-8 sm:grid-cols-[auto_1fr]">
        <div>
          <!--
            Rendered server side by bacon/bacon-qr-code: handing the shared
            secret to a third-party QR endpoint would hand that endpoint the
            secret. The markup is generated from a server-side value and no
            user input reaches it.

            White, deliberately, and the one place in the product that is: a
            dark card behind a dark QR code is a code no camera reads. This is
            a scannable surface rather than a design one.
          -->
          <div class="border-line inline-block rounded-sm border bg-white p-3">
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div v-html="qrCode" />
          </div>

          <p class="text-content-muted text-chrome mt-3 max-w-[28ch] leading-relaxed">
            {{ t('ui.security.cannot_scan') }}
          </p>
          <div class="mt-1">
            <AppCopy :value="secret" :noun="t('ui.security.secret')" mono />
          </div>
        </div>

        <div>
          <AppAlert v-if="confirmed" tone="success" class="mb-5">
            {{ t('ui.security.already_on') }}
          </AppAlert>

          <form v-else class="flex max-w-xs flex-col gap-5" @submit.prevent="confirm">
            <AppInput
              v-model="form.code"
              :label="t('ui.auth.code')"
              autocomplete="one-time-code"
              inputmode="numeric"
              :hint="t('ui.security.code_hint')"
              :error="form.errors.code"
              required
            />

            <div>
              <AppButton type="submit" variant="primary" :loading="form.processing">
                {{ t('ui.security.confirm_and_enable') }}
              </AppButton>
            </div>
          </form>

          <div class="mt-6">
            <AppButton :href="base" variant="ghost" size="sm">
              {{ t('ui.security.back') }}
            </AppButton>
          </div>
        </div>
      </div>
    </DetailSection>
  </component>
</template>
