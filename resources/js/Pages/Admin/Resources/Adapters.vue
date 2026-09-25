<script setup lang="ts">
/**
 * What this installation can read from, and what it may change.
 *
 * The screen exists for one decision, and everything on it serves that decision:
 * allowing an adapter to write is the moment this platform stops being a window
 * onto somebody's estate and starts being a control plane over it.
 *
 * So the confirmation **names the capabilities one by one** rather than saying
 * "allow changes", and it is the destructive level of the ladder — a typed phrase
 * — because "reboot a machine" and "push a firewall policy" are not things to
 * agree to by pressing a switch. The route behind it asks for a password again
 * (Phase 17), which an operator will meet about once.
 *
 * An adapter with nothing to write says so and offers no switch at all. A toggle
 * that did nothing would teach somebody that the toggle means nothing.
 */
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface CapabilityRow {
  value: string
  label: string
  description: string | null
  area: string
  highRisk: boolean
}

interface AdapterRow {
  id: string
  key: string
  name: string
  vendor: string
  module: string | null
  areas: { value: string; label: string }[]
  reads: CapabilityRow[]
  writes: CapabilityRow[]
  permitted: string[]
  enabled: boolean
  writesEnabled: boolean
  health: string
  healthLabel: string
  healthMessage: string | null
  remoteVersion: string | null
  supported: boolean
  checkedAt: string | null
  credential: { set: boolean; rotatedAt: string | null }
  limits: { perMinute: number; concurrency: number; batchSize: number }
}

defineProps<{
  adapters: AdapterRow[]
  orphaned: {
    id: string
    key: string
    name: string
    vendor: string
    module: string | null
    writesEnabled: boolean
  }[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

/**
 * The adapter whose credential is being written, and the value being typed.
 *
 * Write-only, like the licence key: the field starts empty every time and
 * the screen never learns what is stored. Clearing it is sending an empty
 * value, which destroys the credential rather than storing an empty string —
 * a blank credential fails authentication in a way nobody can read.
 */
const credentialFor = ref<AdapterRow | null>(null)
const credentialValue = ref('')
const savingCredential = ref(false)

function askForCredential(adapter: AdapterRow): void {
  credentialFor.value = adapter
  credentialValue.value = ''
}

function saveCredential(): void {
  const adapter = credentialFor.value

  if (adapter === null) return

  savingCredential.value = true

  router.put(
    `/admin/resources/adapters/${adapter.id}/credential`,
    { value: credentialValue.value },
    {
      preserveScroll: true,
      onFinish: () => {
        savingCredential.value = false
        credentialValue.value = ''
        credentialFor.value = null
      },
    },
  )
}

const confirming = ref<AdapterRow | null>(null)
const confirmOpen = ref(false)
const busy = ref(false)

function askToAllow(adapter: AdapterRow): void {
  confirming.value = adapter
  confirmOpen.value = true
}

function allow(reason: string | null): void {
  const adapter = confirming.value

  if (adapter === null) return

  busy.value = true

  router.put(
    `/admin/resources/adapters/${adapter.id}/writes`,
    { writes_enabled: true, reason },
    {
      preserveScroll: true,
      onFinish: () => {
        busy.value = false
        confirmOpen.value = false
        confirming.value = null
      },
    },
  )
}

/** Revoking is not confirmed. Taking permission away is never the risky direction. */
function revoke(adapter: AdapterRow): void {
  router.put(
    `/admin/resources/adapters/${adapter.id}/writes`,
    { writes_enabled: false },
    { preserveScroll: true },
  )
}

function setEnabled(adapter: AdapterRow, enabled: boolean): void {
  router.put(`/admin/resources/adapters/${adapter.id}`, { enabled }, { preserveScroll: true })
}

function check(adapter: AdapterRow): void {
  router.post(`/admin/resources/adapters/${adapter.id}/check`, {}, { preserveScroll: true })
}

const TONES: Record<string, StatusTone> = {
  ok: 'healthy',
  degraded: 'warning',
  failing: 'critical',
}

function toneOf(adapter: AdapterRow): StatusTone {
  // Switched off on purpose is maintenance, not a fault.
  if (!adapter.enabled) return 'maintenance'
  if (!adapter.supported) return 'warning'

  return TONES[adapter.health] ?? 'unknown'
}

function when(value: string | null): string {
  return value === null
    ? t('infrastructure.adapters.never_checked')
    : new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="Adapters" />

  <AdminLayout
    :heading="t('infrastructure.adapters.title')"
    :description="t('infrastructure.adapters.intro')"
  >
    <div class="flex flex-col gap-4">
      <EmptyState
        v-if="adapters.length === 0 && orphaned.length === 0"
        :title="t('infrastructure.adapters.title')"
        :description="t('infrastructure.adapters.empty')"
        icon="connection"
      />

      <div
        v-for="adapter in adapters"
        :key="adapter.id"
        class="border-line bg-surface-primary rounded-lg border p-4"
      >
        <div class="flex flex-col gap-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-body font-semibold">{{ adapter.name }}</h2>
                <AppBadge tone="neutral">{{ adapter.vendor }}</AppBadge>
                <AppBadge v-if="adapter.module" tone="info">{{ adapter.module }}</AppBadge>
                <AppBadge v-if="adapter.writesEnabled" tone="warning">
                  {{ t('infrastructure.adapters.writes_allowed') }}
                </AppBadge>
                <AppBadge v-else tone="unknown">
                  {{ t('infrastructure.adapters.read_only') }}
                </AppBadge>
              </div>
              <p class="text-content-subtle text-chrome font-mono">{{ adapter.key }}</p>
              <div class="flex flex-wrap items-center gap-2">
                <AppStatus :tone="toneOf(adapter)" :label="adapter.healthLabel" />
                <span class="text-content-muted text-chrome">{{ when(adapter.checkedAt) }}</span>
              </div>
              <p v-if="adapter.healthMessage" class="text-content-muted text-chrome">
                {{ adapter.healthMessage }}
              </p>

              <!-- Whether there is one, and when it last changed. Never what
                   it is: a secret a screen can print is a secret in a
                   browser's history and in somebody's support screenshot. -->
              <p class="text-content-muted text-chrome">
                {{
                  adapter.credential.set
                    ? t('infrastructure.adapters.credential_set', {
                        when: when(adapter.credential.rotatedAt),
                      })
                    : t('infrastructure.adapters.credential_unset')
                }}
              </p>
              <!-- Said out loud rather than folded into "degraded": an operator
                   debugging an adapter that returns nothing usually finds that
                   somebody upgraded the device. -->
              <p
                v-if="!adapter.supported && adapter.remoteVersion"
                class="text-warning text-chrome"
              >
                {{
                  t('infrastructure.adapters.unsupported').replace(
                    ':version',
                    adapter.remoteVersion,
                  )
                }}
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <AppButton variant="ghost" size="sm" @click="check(adapter)">
                {{ t('infrastructure.adapters.check') }}
              </AppButton>

              <template v-if="can.manage">
                <AppButton
                  v-if="adapter.writes.length > 0 && !adapter.writesEnabled"
                  variant="secondary"
                  size="sm"
                  @click="askToAllow(adapter)"
                >
                  {{ t('infrastructure.adapters.allow_writes') }}
                </AppButton>
                <AppButton
                  v-else-if="adapter.writes.length > 0"
                  variant="secondary"
                  size="sm"
                  @click="revoke(adapter)"
                >
                  {{ t('infrastructure.adapters.revoke_writes') }}
                </AppButton>
                <span v-else class="text-content-muted text-chrome">
                  {{ t('infrastructure.adapters.writes_none') }}
                </span>

                <AppButton variant="ghost" size="sm" @click="askForCredential(adapter)">
                  {{
                    adapter.credential.set
                      ? t('infrastructure.adapters.rotate_credential')
                      : t('infrastructure.adapters.set_credential')
                  }}
                </AppButton>

                <AppButton variant="ghost" size="sm" @click="setEnabled(adapter, !adapter.enabled)">
                  {{
                    adapter.enabled
                      ? t('infrastructure.adapters.disable')
                      : t('infrastructure.adapters.enable')
                  }}
                </AppButton>
              </template>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-1.5">
              <h3 class="text-content-subtle text-label uppercase">
                {{ t('infrastructure.adapters.columns.areas') }}
              </h3>
              <div class="flex flex-wrap gap-1.5">
                <AppBadge v-for="area in adapter.areas" :key="area.value" tone="neutral">
                  {{ area.label }}
                </AppBadge>
              </div>
              <ul class="text-content-muted text-chrome mt-1 flex flex-col gap-0.5">
                <li v-for="capability in adapter.reads" :key="capability.value">
                  {{ capability.label }}
                </li>
              </ul>
            </div>

            <div v-if="adapter.writes.length > 0" class="flex flex-col gap-1.5">
              <h3 class="text-content-subtle text-label uppercase">
                {{ t('infrastructure.adapters.columns.writes') }}
              </h3>
              <ul class="flex flex-col gap-1">
                <li
                  v-for="capability in adapter.writes"
                  :key="capability.value"
                  class="text-body flex items-center gap-2"
                >
                  <AppStatus
                    :tone="adapter.permitted.includes(capability.value) ? 'warning' : 'unknown'"
                    :label="capability.label"
                    compact
                  />
                  <span>{{ capability.label }}</span>
                  <!-- A word, not an exclamation mark: a glyph on its own is
                       nothing at all to a screen reader, and this is the row
                       that says a capability can cut the power. -->
                  <AppBadge v-if="capability.highRisk" tone="danger">
                    {{ t('infrastructure.adapters.high_risk') }}
                  </AppBadge>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Rows whose module is gone. Shown rather than hidden: one that says an
           operator once allowed writes is worth seeing before the module comes
           back. -->
      <div v-if="orphaned.length > 0" class="border-line bg-surface-primary rounded-lg border p-4">
        <div class="flex flex-col gap-2">
          <p class="text-content-muted text-body">{{ t('infrastructure.adapters.orphaned') }}</p>
          <ul class="flex flex-col gap-1">
            <li v-for="row in orphaned" :key="row.id" class="text-body flex items-center gap-2">
              <span class="text-chrome font-mono">{{ row.key }}</span>
              <span>{{ row.name }}</span>
              <AppBadge v-if="row.writesEnabled" tone="warning">
                {{ t('infrastructure.adapters.writes_allowed') }}
              </AppBadge>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <AppConfirm
      v-model:open="confirmOpen"
      level="destructive"
      :title="
        t('infrastructure.adapters.confirm_writes.title').replace(':name', confirming?.name ?? '')
      "
      :description="t('infrastructure.adapters.confirm_writes.body')"
      :phrase="t('infrastructure.adapters.confirm_writes.phrase')"
      :confirm-label="t('infrastructure.adapters.allow_writes')"
      :busy="busy"
      @confirm="allow"
    >
      <ul class="flex flex-col gap-1">
        <li
          v-for="capability in confirming?.writes ?? []"
          :key="capability.value"
          class="text-body"
        >
          <span class="font-medium">{{ capability.label }}</span>
          <span v-if="capability.description" class="text-content-muted text-chrome block">
            {{ capability.description }}
          </span>
        </li>
      </ul>
    </AppConfirm>
    <AppConfirm
      :open="credentialFor !== null"
      level="consequential"
      :title="
        credentialFor?.credential.set
          ? t('infrastructure.adapters.rotate_credential')
          : t('infrastructure.adapters.set_credential')
      "
      :description="t('infrastructure.adapters.credential_hint')"
      :confirm-label="t('ui.common.save')"
      :busy="savingCredential"
      @update:open="(value: boolean) => (credentialFor = value ? credentialFor : null)"
      @confirm="saveCredential"
    >
      <AppInput
        v-model="credentialValue"
        type="password"
        autocomplete="off"
        :label="t('infrastructure.adapters.credential')"
        :hint="t('infrastructure.adapters.credential_clear_hint')"
      />
    </AppConfirm>
  </AdminLayout>
</template>
