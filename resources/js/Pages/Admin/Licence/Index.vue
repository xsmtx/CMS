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
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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
  history: {
    id: string
    action: string
    actionLabel: string
    actor: string | null
    reason: string | null
    at: string
  }[]
}>()

const { t } = useTranslations()

const activation = useForm({ licence_key: '' })

const HISTORY_COLUMNS: TableColumn[] = [
  { key: 'when', label: t('ui.licence.when') },
  { key: 'what', label: t('ui.licence.what') },
  { key: 'who', label: t('ui.licence.who') },
  { key: 'detail', label: t('ui.licence.detail') },
]

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
  if (!props.licence.configured) {
    return { tone: 'unknown', label: t('ui.licence.not_activated') }
  }

  if (props.licence.isInGrace) return { tone: 'warning', label: t('ui.licence.out_of_contact') }
  if (props.licence.isLive) return { tone: 'healthy', label: t('ui.licence.licensed') }

  return { tone: 'critical', label: props.licence.statusLabel }
})

const facts = computed<DescriptionItem[]>(() => [
  { key: 'licence', label: t('ui.licence.licence'), value: props.licence.licenceId, mono: true },
  { key: 'expires', label: t('ui.licence.expires'), value: formatDate(props.licence.expiresAt) },
  {
    key: 'heartbeat',
    label: t('ui.licence.heartbeat_due'),
    value: formatDate(props.licence.heartbeatBy),
  },
  {
    key: 'contact',
    label: t('ui.licence.last_contact'),
    value: formatDateTime(props.licence.lastContactAt),
  },
])

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
  return value === null ? t('ui.common.never') : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('ui.licence.title')" />

  <AdminLayout :heading="t('ui.licence.title')">
    <template #header>
      <PageHeader :title="t('ui.licence.title')" :description="t('ui.licence.intro')">
        <template #status>
          <AppStatus :tone="overall.tone" :label="overall.label" />
        </template>

        <template v-if="licence.edition" #meta>
          <span>{{ t('ui.licence.edition', { edition: licence.edition }) }}</span>
        </template>

        <template v-if="licence.configured" #actions>
          <AppButton icon="sync" :loading="busy" @click="heartbeat">
            {{ t('ui.licence.check_now') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <!-- Said before anything else, because it is the thing that decides
           whether the rest of the screen matters. -->
      <AppAlert v-if="!configured.api" tone="info">
        {{ t('ui.licence.no_server', { name: 'LICENSE_API_URL' }) }}
      </AppAlert>

      <AppAlert v-else-if="!configured.publicKey" tone="warning">
        {{ t('ui.licence.no_public_key', { name: 'LICENSE_PUBLIC_KEY_PATH' }) }}
      </AppAlert>

      <!-- The warning, with the date it runs out. A grace period with no
           deadline on the screen is a grace period nobody acts on. -->
      <AppAlert v-if="licence.isInGrace" tone="warning">
        {{
          t('ui.licence.grace', {
            since: formatDateTime(licence.lastContactAt),
            until: formatDate(licence.graceUntil),
          })
        }}
      </AppAlert>

      <!-- What a lapse actually costs, said plainly. An operator who thinks it
           takes the platform down will make a panicked decision about it at two
           in the morning. -->
      <AppAlert v-else-if="licence.configured && !licence.isLive" tone="danger">
        {{ t('ui.licence.lapsed', { status: licence.statusLabel.toLowerCase() }) }}
      </AppAlert>

      <AppAlert v-if="licence.lastFailure" tone="warning">{{ licence.lastFailure }}</AppAlert>

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div class="flex min-w-0 flex-col gap-8">
          <DetailSection v-if="licence.configured" :title="t('ui.licence.status')">
            <DescriptionList :items="facts" />

            <div v-if="licence.excluded.length > 0" class="mt-5">
              <p class="text-content-subtle text-label mb-1.5 uppercase">
                {{ t('ui.licence.not_included') }}
              </p>
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

            <div v-if="limits.length > 0" class="mt-5">
              <p class="text-content-subtle text-label mb-1.5 uppercase">
                {{ t('ui.licence.limits') }}
              </p>
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
          </DetailSection>

          <DetailSection
            v-if="configured.api"
            :title="licence.configured ? t('ui.licence.activate_other') : t('ui.licence.activate')"
            :description="t('ui.licence.activate_intro', { days: configured.graceDays })"
          >
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="activate">
              <AppInput
                v-model="activation.licence_key"
                :label="t('ui.licence.key')"
                class="min-w-64 flex-1"
                autocomplete="off"
                :error="activation.errors.licence_key"
              />
              <AppButton type="submit" variant="primary" :loading="activation.processing">
                {{ t('ui.licence.activate_button') }}
              </AppButton>
            </form>
          </DetailSection>

          <DetailSection
            :title="t('ui.licence.history')"
            :description="t('ui.licence.history_intro')"
            :divided="history.length === 0"
          >
            <AppTable v-if="history.length > 0" name="licence-history" :columns="HISTORY_COLUMNS">
              <AppTableRow v-for="entry in history" :key="entry.id">
                <td data-col="when" class="text-content-muted whitespace-nowrap">
                  {{ formatDateTime(entry.at) }}
                </td>
                <td data-col="what">{{ entry.actionLabel }}</td>
                <td data-col="who" class="text-content-muted">
                  {{ entry.actor ?? t('ui.licence.the_scheduler') }}
                </td>
                <td data-col="detail" class="text-content-muted">{{ entry.reason ?? '—' }}</td>
              </AppTableRow>
            </AppTable>

            <EmptyState
              v-else
              variant="plain"
              icon="history"
              :title="t('ui.licence.no_history')"
              :description="t('ui.licence.no_history_detail')"
            />
          </DetailSection>
        </div>

        <aside class="flex min-w-0 flex-col gap-8">
          <DetailSection
            :title="t('ui.licence.installation')"
            :description="t('ui.licence.installation_intro')"
          >
            <div class="flex flex-col gap-3">
              <AppCopy :value="installation.id" :noun="t('ui.licence.installation_id')" />

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

              <p class="text-content-subtle text-chrome leading-relaxed">
                {{ t('ui.licence.claims_note') }}
              </p>
            </div>
          </DetailSection>
        </aside>
      </div>
    </div>

    <DangerZone v-if="licence.configured">
      <DangerZoneRow
        :title="t('ui.licence.release_title')"
        :description="t('ui.licence.release_detail')"
      >
        <AppButton variant="danger-subtle" @click="releasing = true">
          {{ t('ui.licence.release_button') }}
        </AppButton>
      </DangerZoneRow>
    </DangerZone>

    <!--
      Level 3, not 4: releasing a licence is reversible — the same key can be
      activated again — so it wants a reason for the audit record and not the
      installation's name typed out.
    -->
    <AppConfirm
      v-model:open="releasing"
      level="high-risk"
      :title="t('ui.licence.release_confirm_title')"
      :description="t('ui.licence.release_confirm_detail')"
      :confirm-label="t('ui.licence.release_confirm')"
      :busy="busy"
      @confirm="release"
    />
  </AdminLayout>
</template>
