<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppBadge from '../../Components/AppBadge.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCard from '../../Components/AppCard.vue'
import AppConfirm from '../../Components/AppConfirm.vue'
import AppInput from '../../Components/AppInput.vue'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import ClientLayout from '../../Layouts/ClientLayout.vue'

interface SessionRow {
  id: string
  ipAddress: string | null
  userAgent: string | null
  lastActiveAt: string
  current: boolean
}

interface LoginRow {
  id: string
  successful: boolean
  reason: string | null
  ipAddress: string | null
  occurredAt: string
}

const props = defineProps<{
  guard: string
  twoFactor: { enabled: boolean; pending: boolean; recoveryCodeCount: number }
  sessions: SessionRow[]
  loginHistory: LoginRow[]
}>()

const page = usePage()

const layout = computed(() => (props.guard === 'staff' ? AdminLayout : ClientLayout))
const base = computed(() => (props.guard === 'staff' ? '/admin/security' : '/security'))

const recoveryCodes = computed<string[] | null>(() => page.props.flash.recoveryCodes ?? null)

const passwordForm = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

function updatePassword(): void {
  passwordForm.put(`${base.value}/password`, {
    preserveScroll: true,
    onSuccess: () => passwordForm.reset(),
  })
}

function beginTwoFactor(): void {
  router.post(`${base.value}/two-factor`, {}, { preserveScroll: true })
}

/** Turning off your own second factor is asked about first. */
const confirmingTwoFactor = ref(false)

function disableTwoFactor(): void {
  router.delete(`${base.value}/two-factor`, {
    preserveScroll: true,
    onFinish: () => (confirmingTwoFactor.value = false),
  })
}

function regenerateCodes(): void {
  router.post(`${base.value}/two-factor/recovery-codes`, {}, { preserveScroll: true })
}

function revokeOthers(): void {
  router.delete(`${base.value}/sessions`, { preserveScroll: true })
}

function revoke(id: string): void {
  router.delete(`${base.value}/sessions/${id}`, { preserveScroll: true })
}

function formatTime(value: string): string {
  return new Date(value).toLocaleString()
}

function describeDevice(agent: string | null): string {
  if (!agent) return 'Unknown device'
  // Enough to recognise your own session without shipping a UA parser.
  const match = /(Firefox|Edg|Chrome|Safari)\/[\d.]+/.exec(agent)
  return match?.[1]?.replace('Edg', 'Edge') ?? 'Unknown device'
}
</script>

<template>
  <Head title="Security" />

  <component
    :is="layout"
    heading="Security"
    description="Your password, second factor and the devices you are signed in on."
  >
    <div class="flex flex-col gap-5">
      <AppAlert v-if="page.props.flash?.status" tone="success">
        {{ page.props.flash.status }}
      </AppAlert>

      <AppAlert v-if="recoveryCodes" tone="info">
        <p class="font-medium">Save these recovery codes now.</p>
        <p class="mt-1">
          Each one can be used once if you lose your authenticator. They will not be shown again.
        </p>
        <ul class="text-body mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono">
          <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
        </ul>
      </AppAlert>

      <AppCard title="Password" description="Changing it signs you out everywhere else.">
        <form class="flex max-w-md flex-col gap-5" @submit.prevent="updatePassword">
          <AppInput
            v-model="passwordForm.current_password"
            label="Current password"
            type="password"
            autocomplete="current-password"
            :error="passwordForm.errors.current_password"
            required
          />
          <AppInput
            v-model="passwordForm.password"
            label="New password"
            type="password"
            autocomplete="new-password"
            hint="At least 12 characters, with letters, numbers and symbols."
            :error="passwordForm.errors.password"
            required
          />
          <AppInput
            v-model="passwordForm.password_confirmation"
            label="Confirm new password"
            type="password"
            autocomplete="new-password"
            required
          />
          <div>
            <AppButton type="submit" variant="primary" :loading="passwordForm.processing">
              Update password
            </AppButton>
          </div>
        </form>
      </AppCard>

      <AppCard title="Two-factor authentication">
        <template #actions>
          <AppBadge :tone="twoFactor.enabled ? 'success' : 'neutral'">
            {{ twoFactor.enabled ? 'On' : 'Off' }}
          </AppBadge>
        </template>

        <div v-if="twoFactor.enabled" class="flex flex-col gap-4">
          <p class="text-content-muted text-body max-w-[60ch] leading-relaxed">
            You have {{ twoFactor.recoveryCodeCount }} unused recovery code(s). Generate a new set
            if you have lost them; the old ones stop working immediately.
          </p>
          <div class="flex flex-wrap gap-2">
            <AppButton :href="`${base}/two-factor`">View setup</AppButton>
            <AppButton @click="regenerateCodes">Regenerate recovery codes</AppButton>
            <AppButton variant="danger-subtle" @click="confirmingTwoFactor = true"
              >Turn off</AppButton
            >
          </div>
        </div>

        <div v-else class="flex flex-col gap-4">
          <p class="text-content-muted text-body max-w-[60ch] leading-relaxed">
            Require a code from an authenticator app in addition to your password.
          </p>
          <div>
            <AppButton v-if="twoFactor.pending" variant="primary" :href="`${base}/two-factor`">
              Finish setting up
            </AppButton>
            <AppButton v-else variant="primary" @click="beginTwoFactor">Set up</AppButton>
          </div>
        </div>
      </AppCard>

      <AppCard title="Signed-in devices">
        <template #actions>
          <AppButton v-if="sessions.length > 1" size="sm" @click="revokeOthers">
            Sign out others
          </AppButton>
        </template>

        <ul class="divide-line divide-y">
          <li
            v-for="session in sessions"
            :key="session.id"
            class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
          >
            <div class="min-w-0">
              <p class="text-body font-medium">
                {{ describeDevice(session.userAgent) }}
                <AppBadge v-if="session.current" tone="brand" class="ml-2">This device</AppBadge>
              </p>
              <p class="text-content-muted text-chrome mt-0.5">
                {{ session.ipAddress ?? 'Unknown address' }} · last active
                {{ formatTime(session.lastActiveAt) }}
              </p>
            </div>
            <AppButton
              v-if="!session.current"
              size="sm"
              variant="ghost"
              @click="revoke(session.id)"
            >
              Sign out
            </AppButton>
          </li>
        </ul>
      </AppCard>

      <AppCard title="Recent sign-in attempts" description="Successful and failed, newest first.">
        <ul class="divide-line divide-y">
          <li
            v-for="entry in loginHistory"
            :key="entry.id"
            class="text-body flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
          >
            <span>
              <AppBadge :tone="entry.successful ? 'success' : 'danger'">
                {{ entry.successful ? 'Signed in' : 'Failed' }}
              </AppBadge>
              <span class="text-content-muted ml-2">{{
                entry.ipAddress ?? 'Unknown address'
              }}</span>
            </span>
            <span class="text-content-muted text-chrome">{{ formatTime(entry.occurredAt) }}</span>
          </li>
        </ul>
      </AppCard>
    </div>
    <AppConfirm
      v-model:open="confirmingTwoFactor"
      level="consequential"
      title="Turn off two-factor authentication?"
      description="Your account will be protected by your password alone, and your recovery codes stop working."
      confirm-label="Turn off two-factor"
      @confirm="disableTwoFactor"
    />
  </component>
</template>
