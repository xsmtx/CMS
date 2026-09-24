<script setup lang="ts">
/**
 * This installation's relationship with the vendor.
 *
 * The screen answers three questions in the order somebody asks them: is the
 * licence in order, when did we last speak to the vendor, and what has happened
 * to it. Everything else — the key, the API URL, the public key — is
 * configuration, and configuration on a screen is configuration in a
 * screenshot.
 *
 * **Grace is shown, not hidden.** "Live" and "live but out of contact" are
 * different states and the second is the only warning anybody gets before the
 * vendor mark comes back. So it is a warning with a date on it rather than a
 * green tick.
 *
 * The lapsed state says what it actually costs, which is very little: the
 * vendor mark returns and nothing else changes. An operator who thinks a lapsed
 * licence will take their platform down will make a panicked decision about it
 * at two in the morning.
 */
import { Head, useForm, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface Licence {
  configured: boolean
  licenceId: string | null
  edition: string | null
  status: string
  statusLabel: string
  excluded: string[]
  limits: Record<string, number>
  issuedAt: string | null
  expiresAt: string | null
  heartbeatBy: string | null
  graceUntil: string | null
  lastContactAt: string | null
  lastFailure: string | null
  isLive: boolean
  isInGrace: boolean
}

const props = defineProps<{
  installation: { id: string; claims: Record<string, string> }
  configured: { api: boolean; key: boolean; publicKey: boolean; graceDays: number }
  licence: Licence
  history: { id: string; action: string; actor: string | null; reason: string | null; at: string }[]
}>()

const activation = useForm({ licence_key: '' })

const releasing = ref(false)
const busy = ref(false)

/**
 * The one line that says how things are.
 *
 * Four states, and they are not four shades of the same one: unlicensed is
 * fine, grace is a warning with a deadline, and lapsed has a consequence worth
 * naming.
 */
const overall = computed<{ tone: StatusTone; label: string }>(() => {
  if (!props.licence.configured) return { tone: 'unknown', label: 'No licence activated' }
  if (props.licence.isInGrace) return { tone: 'warning', label: 'Out of contact' }
  if (props.licence.isLive) return { tone: 'healthy', label: 'Licensed' }

  return { tone: 'critical', label: props.licence.statusLabel }
})

const limits = computed(() => Object.entries(props.licence.limits))

function activate(): void {
  activation.post('/admin/licence/activate', {
    preserveScroll: true,
    onSuccess: () => activation.reset('licence_key'),
  })
}

function heartbeat(): void {
  busy.value = true

  router.post(
    '/admin/licence/heartbeat',
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        busy.value = false
      },
    },
  )
}

function release(): void {
  busy.value = true

  router.post(
    '/admin/licence/deactivate',
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        busy.value = false
        releasing.value = false
      },
    },
  )
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function formatDateTime(value: string | null): string {
  return value === null ? 'Never' : new Date(value).toLocaleString()
}

/** `licensing.token.refused` reads as "Token refused". */
function readAction(action: string): string {
  const words = action.replace(/^licensing\./, '').replace(/[._]/g, ' ')

  return words.charAt(0).toUpperCase() + words.slice(1)
}
</script>

<template>
  <Head title="Licence" />

  <AdminLayout
    heading="Licence"
    description="What this installation is licensed for, and when it last spoke to the vendor."
  >
    <div class="flex flex-col gap-5">
      <!-- Said before anything else, because it is the thing that decides
           whether the rest of the screen matters. -->
      <AppAlert v-if="!configured.api" tone="info">
        No licence server is configured for this installation, so every feature is available and
        nothing here has to be done. A commercial distribution sets
        <code>LICENSE_API_URL</code>.
      </AppAlert>

      <AppAlert v-else-if="!configured.publicKey" tone="warning">
        This distribution has no licence public key, so no token can be verified. Activation will be
        refused until <code>LICENSE_PUBLIC_KEY_PATH</code> points at one.
      </AppAlert>

      <div class="grid gap-5 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Status">
          <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
              <AppStatus :tone="overall.tone" :label="overall.label" />
              <span v-if="licence.edition" class="text-content-muted text-chrome">
                {{ licence.edition }} edition
              </span>
            </div>

            <!-- The warning, with the date it runs out. A grace period with no
                 deadline on the screen is a grace period nobody acts on. -->
            <AppAlert v-if="licence.isInGrace" tone="warning">
              The licence server has not been reached since
              {{ formatDateTime(licence.lastContactAt) }}. Everything still works until
              {{ formatDate(licence.graceUntil) }}, after which the vendor mark returns.
            </AppAlert>

            <!-- What a lapse actually costs, said plainly. An operator who
                 thinks it takes the platform down will make a panicked
                 decision about it at two in the morning. -->
            <AppAlert v-else-if="licence.configured && !licence.isLive" tone="danger">
              This licence is {{ licence.statusLabel.toLowerCase() }}. The vendor mark has returned.
              Nothing else has changed: no screen is closed, no order is refused and no service is
              suspended.
            </AppAlert>

            <AppAlert v-if="licence.lastFailure" tone="warning">
              {{ licence.lastFailure }}
            </AppAlert>

            <dl v-if="licence.configured" class="text-body grid gap-2 sm:grid-cols-2">
              <div class="flex items-baseline justify-between gap-4">
                <dt class="text-content-muted">Licence</dt>
                <dd class="text-chrome font-mono">{{ licence.licenceId ?? '—' }}</dd>
              </div>
              <div class="flex items-baseline justify-between gap-4">
                <dt class="text-content-muted">Expires</dt>
                <dd>{{ formatDate(licence.expiresAt) }}</dd>
              </div>
              <div class="flex items-baseline justify-between gap-4">
                <dt class="text-content-muted">Heartbeat due</dt>
                <dd>{{ formatDate(licence.heartbeatBy) }}</dd>
              </div>
              <div class="flex items-baseline justify-between gap-4">
                <dt class="text-content-muted">Last contact</dt>
                <dd>{{ formatDateTime(licence.lastContactAt) }}</dd>
              </div>
            </dl>

            <div v-if="licence.excluded.length > 0">
              <p class="text-content-subtle text-label mb-1.5 uppercase">Not included</p>
              <ul class="text-body flex flex-wrap gap-2">
                <li
                  v-for="feature in licence.excluded"
                  :key="feature"
                  class="bg-surface-secondary text-chrome rounded-sm px-2 py-0.5 font-mono"
                >
                  {{ feature }}
                </li>
              </ul>
            </div>

            <div v-if="limits.length > 0">
              <p class="text-content-subtle text-label mb-1.5 uppercase">Limits</p>
              <dl class="text-body flex flex-col gap-1">
                <div
                  v-for="[name, value] in limits"
                  :key="name"
                  class="flex items-baseline justify-between gap-4"
                >
                  <dt class="text-content-muted text-chrome font-mono">{{ name }}</dt>
                  <dd class="tabular-nums">{{ value }}</dd>
                </div>
              </dl>
            </div>

            <div v-if="licence.configured" class="flex flex-wrap items-center gap-2">
              <AppButton :loading="busy" @click="heartbeat">Check now</AppButton>
              <AppButton variant="ghost" @click="releasing = true">Release this licence</AppButton>
            </div>
          </div>
        </AppCard>

        <AppCard
          title="This installation"
          description="The identity the vendor knows this installation by. Quote it when you telephone them."
        >
          <div class="flex flex-col gap-3">
            <AppCopy :value="installation.id" noun="installation ID" />

            <dl class="text-body flex flex-col gap-1.5">
              <div
                v-for="(value, name) in installation.claims"
                :key="name"
                class="flex items-baseline justify-between gap-4"
              >
                <dt class="text-content-muted text-chrome">{{ name }}</dt>
                <dd class="text-chrome">{{ value }}</dd>
              </div>
            </dl>

            <p class="text-content-subtle text-label">
              These three are everything the vendor is told. No paths, no database name, no keys.
            </p>
          </div>
        </AppCard>
      </div>

      <AppCard
        v-if="configured.api"
        :title="licence.configured ? 'Activate a different licence' : 'Activate a licence'"
        :description="`The key is stored by your deployment, never here. The last good answer keeps working for ${configured.graceDays} day(s) if the vendor cannot be reached.`"
      >
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="activate">
          <AppInput
            v-model="activation.licence_key"
            label="Licence key"
            class="min-w-64 flex-1"
            autocomplete="off"
            :error="activation.errors.licence_key"
          />
          <AppButton type="submit" variant="primary" :loading="activation.processing">
            Activate
          </AppButton>
        </form>
      </AppCard>

      <AppCard
        title="What has happened to this licence"
        description="Activations, heartbeats and every token this installation refused, with the reason."
      >
        <AppTable v-if="history.length > 0" :headers="['When', 'What', 'Who', 'Detail']">
          <tr v-for="entry in history" :key="entry.id">
            <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
              {{ formatDateTime(entry.at) }}
            </td>
            <td class="px-4 py-2.5">{{ readAction(entry.action) }}</td>
            <td class="text-content-muted px-4 py-2.5">{{ entry.actor ?? 'The scheduler' }}</td>
            <td class="text-content-muted px-4 py-2.5">{{ entry.reason ?? '—' }}</td>
          </tr>
        </AppTable>

        <p v-else class="text-content-muted text-body">Nothing yet.</p>
      </AppCard>
    </div>

    <!--
      Level 3, not 4: releasing a licence is reversible — the same key can be
      activated again — so it wants a reason for the audit record and not the
      installation's name typed out.
    -->
    <AppConfirm
      v-model:open="releasing"
      level="high-risk"
      title="Release this licence?"
      description="The activation is returned to the vendor and the vendor mark comes back. Nothing else changes, and the same key can be activated again."
      confirm-label="Release"
      :busy="busy"
      @confirm="release"
    />
  </AdminLayout>
</template>
