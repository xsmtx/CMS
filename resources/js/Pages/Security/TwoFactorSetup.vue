<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCard from '../../Components/AppCard.vue'
import AppInput from '../../Components/AppInput.vue'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import ClientLayout from '../../Layouts/ClientLayout.vue'

const props = defineProps<{
  guard: string
  qrCode: string
  secret: string
  confirmed: boolean
}>()

const layout = computed(() => (props.guard === 'staff' ? AdminLayout : ClientLayout))
const base = computed(() => (props.guard === 'staff' ? '/admin/security' : '/security'))

const form = useForm({ code: '' })

function confirm(): void {
  form.post(`${base.value}/two-factor/confirm`, { onFinish: () => form.reset('code') })
}
</script>

<template>
  <Head title="Two-factor authentication" />

  <component
    :is="layout"
    heading="Two-factor authentication"
    description="Scan the code with an authenticator app, then enter the six digits it shows."
  >
    <AppCard>
      <div class="grid gap-8 sm:grid-cols-[auto_1fr]">
        <div>
          <!--
            Rendered server side by bacon/bacon-qr-code: handing the shared
            secret to a third-party QR endpoint would hand that endpoint the
            secret. The markup is generated from a server-side value and no
            user input reaches it.
          -->
          <div class="border-line inline-block rounded-[var(--radius-sm)] border bg-white p-3">
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div v-html="qrCode" />
          </div>

          <p class="text-content-muted mt-3 max-w-[28ch] text-xs leading-relaxed">
            Cannot scan? Enter this key by hand:
          </p>
          <p class="mt-1 font-mono text-[13px] break-all">{{ secret }}</p>
        </div>

        <div>
          <AppAlert v-if="confirmed" tone="success" class="mb-5">
            Two-factor authentication is already on for this account.
          </AppAlert>

          <form v-else class="flex max-w-xs flex-col gap-5" @submit.prevent="confirm">
            <AppInput
              v-model="form.code"
              label="Authentication code"
              autocomplete="one-time-code"
              hint="Six digits from your authenticator app."
              :error="form.errors.code"
              required
            />

            <div>
              <AppButton type="submit" variant="primary" :loading="form.processing">
                Confirm and turn on
              </AppButton>
            </div>
          </form>

          <AppButton :href="base" variant="ghost" size="sm" class="mt-6">
            Back to security
          </AppButton>
        </div>
      </div>
    </AppCard>
  </component>
</template>
