<script setup lang="ts">
/**
 * Your own account: the password, the second factor, and what is signed in
 * as you.
 *
 * The same screen on both guards — an operator and a customer are asking the
 * identical question about themselves — which is why it takes the layout as a
 * prop and stays inside the API both layouts share.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppBadge from '../../Components/AppBadge.vue'
import AppButton from '../../Components/AppButton.vue'
import AppConfirm from '../../Components/AppConfirm.vue'
import AppInput from '../../Components/AppInput.vue'
import AppStatus from '../../Components/AppStatus.vue'
import AppTable from '../../Components/AppTable.vue'
import AppTableRow from '../../Components/AppTableRow.vue'
import DetailSection from '../../Components/DetailSection.vue'
import { type TableColumn } from '../../Components/tableContext'
import { useTranslations } from '../../composables/useTranslations'
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
const { t } = useTranslations()

const layout = computed(() => (props.guard === 'staff' ? AdminLayout : ClientLayout))
const base = computed(() => (props.guard === 'staff' ? '/admin/security' : '/security'))

const recoveryCodes = computed<string[] | null>(() => page.props.flash.recoveryCodes ?? null)

const SESSION_COLUMNS: TableColumn[] = [
  { key: 'device', label: t('ui.security.device') },
  { key: 'address', label: t('ui.security.address') },
  { key: 'active', label: t('ui.security.last_active') },
  { key: 'actions', label: '' },
]

const LOGIN_COLUMNS: TableColumn[] = [
  { key: 'result', label: t('ui.security.result') },
  { key: 'address', label: t('ui.security.address') },
  { key: 'when', label: t('ui.security.when') },
]

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
  if (!agent) return t('ui.security.unknown_device')
  // Enough to recognise your own session without shipping a UA parser.
  const match = /(Firefox|Edg|Chrome|Safari)\/[\d.]+/.exec(agent)
  return match?.[1]?.replace('Edg', 'Edge') ?? t('ui.security.unknown_device')
}
</script>

<template>
  <Head :title="t('ui.security.title')" />

  <component :is="layout" :heading="t('ui.security.title')" :description="t('ui.security.intro')">
    <div class="flex flex-col gap-8">
      <AppAlert v-if="page.props.flash?.status" tone="success">
        {{ page.props.flash.status }}
      </AppAlert>

      <!--
        Shown once, and the sentence says so. A list of codes somebody has to
        keep is the one place on this screen worth interrupting for.
      -->
      <AppAlert v-if="recoveryCodes" tone="warning">
        <p class="font-medium">{{ t('ui.security.codes_title') }}</p>
        <p class="mt-1">{{ t('ui.security.codes_body') }}</p>
        <ul class="text-body mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono">
          <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
        </ul>
      </AppAlert>

      <DetailSection
        :title="t('ui.security.password')"
        :description="t('ui.security.password_intro')"
      >
        <form class="flex max-w-md flex-col gap-5" @submit.prevent="updatePassword">
          <AppInput
            v-model="passwordForm.current_password"
            :label="t('ui.security.current_password')"
            type="password"
            autocomplete="current-password"
            :error="passwordForm.errors.current_password"
            required
          />
          <AppInput
            v-model="passwordForm.password"
            :label="t('ui.auth.new_password')"
            type="password"
            autocomplete="new-password"
            :hint="t('ui.auth.new_password_hint')"
            :error="passwordForm.errors.password"
            required
          />
          <AppInput
            v-model="passwordForm.password_confirmation"
            :label="t('ui.auth.new_password_again')"
            type="password"
            autocomplete="new-password"
            :error="passwordForm.errors.password_confirmation"
            required
          />
          <div>
            <AppButton type="submit" variant="primary" :loading="passwordForm.processing">
              {{ t('ui.security.update_password') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <DetailSection :title="t('ui.security.two_factor')">
        <template #actions>
          <AppStatus
            :tone="twoFactor.enabled ? 'healthy' : 'neutral'"
            :label="twoFactor.enabled ? t('ui.security.on') : t('ui.security.off')"
          />
        </template>

        <div v-if="twoFactor.enabled" class="flex flex-col gap-4">
          <p class="text-content-muted text-body max-w-[60ch] leading-relaxed">
            {{ t('ui.security.codes_left', { count: twoFactor.recoveryCodeCount }) }}
          </p>
          <div class="flex flex-wrap gap-2">
            <AppButton :href="`${base}/two-factor`">{{ t('ui.security.view_setup') }}</AppButton>
            <AppButton @click="regenerateCodes">{{ t('ui.security.regenerate') }}</AppButton>
            <AppButton variant="danger-subtle" @click="confirmingTwoFactor = true">
              {{ t('ui.security.turn_off') }}
            </AppButton>
          </div>
        </div>

        <div v-else class="flex flex-col gap-4">
          <p class="text-content-muted text-body max-w-[60ch] leading-relaxed">
            {{ t('ui.security.two_factor_intro') }}
          </p>
          <div>
            <AppButton v-if="twoFactor.pending" variant="primary" :href="`${base}/two-factor`">
              {{ t('ui.security.finish_setup') }}
            </AppButton>
            <AppButton v-else variant="primary" @click="beginTwoFactor">
              {{ t('ui.security.set_up') }}
            </AppButton>
          </div>
        </div>
      </DetailSection>

      <DetailSection :title="t('ui.security.devices')" :divided="false">
        <template #actions>
          <AppButton v-if="sessions.length > 1" size="sm" @click="revokeOthers">
            {{ t('ui.security.sign_out_others') }}
          </AppButton>
        </template>

        <AppTable name="security-sessions" :columns="SESSION_COLUMNS">
          <AppTableRow v-for="session in sessions" :key="session.id">
            <td data-col="device">
              <span class="font-medium">{{ describeDevice(session.userAgent) }}</span>
              <AppBadge v-if="session.current" tone="brand" class="ml-2">
                {{ t('ui.security.this_device') }}
              </AppBadge>
            </td>
            <td data-col="address" class="text-content-muted font-mono">
              {{ session.ipAddress ?? t('ui.security.unknown_address') }}
            </td>
            <td data-col="active" class="text-content-muted">
              {{ formatTime(session.lastActiveAt) }}
            </td>
            <td data-col="actions" class="text-right">
              <span v-if="!session.current" class="row-actions">
                <AppButton size="sm" variant="ghost" @click="revoke(session.id)">
                  {{ t('ui.security.sign_out') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <DetailSection
        :title="t('ui.security.attempts')"
        :description="t('ui.security.attempts_intro')"
        :divided="false"
      >
        <AppTable name="security-logins" :columns="LOGIN_COLUMNS">
          <AppTableRow v-for="entry in loginHistory" :key="entry.id">
            <td data-col="result">
              <AppStatus
                :tone="entry.successful ? 'healthy' : 'critical'"
                :label="entry.successful ? t('ui.security.signed_in') : t('ui.security.failed')"
              />
            </td>
            <td data-col="address" class="text-content-muted font-mono">
              {{ entry.ipAddress ?? t('ui.security.unknown_address') }}
            </td>
            <td data-col="when" class="text-content-muted">{{ formatTime(entry.occurredAt) }}</td>
          </AppTableRow>
        </AppTable>
      </DetailSection>
    </div>

    <AppConfirm
      v-model:open="confirmingTwoFactor"
      level="consequential"
      :title="t('ui.security.turn_off_title')"
      :description="t('ui.security.turn_off_detail')"
      :confirm-label="t('ui.security.turn_off_confirm')"
      @confirm="disableTwoFactor"
    />
  </component>
</template>
