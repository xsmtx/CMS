<script setup lang="ts">
/**
 * One incident: the timeline, the alerts underneath it, and what it was worth.
 *
 * **The update form is the top of the page, not the bottom.** Everything else
 * here is read once; the thing somebody has this screen open to do is say what
 * is happening now, possibly for the fourth time in an hour. A form below three
 * sections of history is a form that is scrolled to, during an outage.
 *
 * **The state and the sentence are one control.** There is no way to move an
 * incident to "identified" without saying what was identified — the use case
 * refuses it and this screen could not ask for it. Resolving is the same form
 * with the last option chosen, because it is the same act: a sentence, and
 * where it leaves things.
 *
 * The timeline is newest first and append-only. What was believed at half past
 * two is what a postmortem is written from, so nothing here edits or deletes.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DescriptionList from '../../../Components/DescriptionList.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import MetricStrip from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface Option {
  value: string
  label: string
}

interface MoneyRow {
  currency: string
  amount: string
  minor: number
}

interface TimelineEntry {
  id: string
  body: string
  state: string
  stateLabel: string
  stateTone: string
  author: string | null
  isPublic: boolean
  writtenAt: string | null
}

interface AttachedAlert {
  id: string
  subject: string
  rule: string | null
  observed: string | null
  severityTone: string
  severityLabel: string
}

interface Incident {
  id: string
  reference: string
  title: string
  state: string
  stateLabel: string
  stateTone: string
  severity: string
  severityLabel: string
  severityTone: string
  openedBy: string | null
  startedAt: string
  detectedAt: string | null
  resolvedAt: string | null
  durationSeconds: number | null
  isPublic: boolean
  alertCount: number
  summary: string | null
  postmortem: string | null
  updates: TimelineEntry[]
  alerts: AttachedAlert[]
  impact: {
    services: number
    customers: number
    recurring: MoneyRow[]
    frozenAt: string
  } | null
}

const props = defineProps<{
  incident: Incident
  states: Option[]
  unattached: { id: string; subject: string; rule: string | null }[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const ALERT_COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('reliability.alerts.columns.subject') },
  { key: 'severity', label: t('reliability.alerts.columns.severity') },
  { key: 'observed', label: t('reliability.alerts.columns.observed') },
  { key: 'rule', label: t('reliability.alerts.columns.rule') },
  { key: 'actions', label: '' },
]

const UNATTACHED_COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('reliability.alerts.columns.subject') },
  { key: 'rule', label: t('reliability.alerts.columns.rule') },
  { key: 'actions', label: '' },
]

const update = useForm({
  body: '',
  state: props.incident.state,
  is_public: false,
})

const resolving = ref(false)

/**
 * Resolving is asked for once, because it freezes the impact figure and writes
 * the timestamp an SLA is measured against. Level 2 rather than 4: it ends a
 * record, it does not take anything down.
 */
const isResolving = computed(() => update.state === 'resolved')

const facts = computed(() => [
  {
    key: 'started',
    label: t('reliability.incidents.columns.started'),
    value: when(props.incident.startedAt),
  },
  { key: 'detected', label: t('reliability.incidents.detected'), value: detected() },
  {
    key: 'duration',
    label: t('reliability.incidents.duration'),
    value: lasted(props.incident.durationSeconds),
  },
  {
    key: 'opened',
    label: t('reliability.incidents.opened_by'),
    value: props.incident.openedBy ?? '—',
  },
])

/** The frozen figures, as a strip rather than three sentences. */
const impactMetrics = computed(() => {
  const impact = props.incident.impact

  if (impact === null) return []

  return [
    {
      key: 'services',
      label: t('reliability.incidents.services'),
      value: String(impact.services),
    },
    {
      key: 'customers',
      label: t('reliability.incidents.customers'),
      value: String(impact.customers),
    },
    // The value is a slot rather than a string: money is a list here, and
    // a strip that printed a total across currencies would be a figure that
    // means nothing.
    { key: 'recurring', label: t('reliability.incidents.recurring'), value: '—' },
  ]
})

function submit(): void {
  update.post(`/admin/reliability/incidents/${props.incident.id}/updates`, {
    preserveScroll: true,
    onSuccess: () => {
      update.reset('body', 'is_public')
      resolving.value = false
    },
  })
}

function attach(alertId: string): void {
  router.post(
    `/admin/reliability/incidents/${props.incident.id}/alerts`,
    { alert: alertId },
    { preserveScroll: true },
  )
}

function detach(alertId: string): void {
  router.delete(`/admin/reliability/alerts/${alertId}/incident`, { preserveScroll: true })
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}

/**
 * The gap between when it broke and when anybody noticed, which is what a
 * postmortem is usually about — and the reason both timestamps are carried.
 */
function detected(): string {
  const at = props.incident.detectedAt

  if (at === null) return '—'

  const gap = Math.max(
    0,
    Math.round((new Date(at).getTime() - new Date(props.incident.startedAt).getTime()) / 1000),
  )

  return gap < 60
    ? when(at)
    : `${when(at)} · ${t('reliability.incidents.detected_after', { duration: lasted(gap) })}`
}

function lasted(seconds: number | null): string {
  if (seconds === null) return t('reliability.incidents.still_going')
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`

  return `${(seconds / 3600).toFixed(1)}h`
}
</script>

<template>
  <Head :title="incident.title" />

  <AdminLayout :heading="incident.title">
    <template #meta>
      <span class="font-mono">{{ incident.reference }}</span>
    </template>

    <template #status>
      <AppStatus :tone="asTone(incident.stateTone)" :label="incident.stateLabel" />
      <AppStatus :tone="asTone(incident.severityTone)" :label="incident.severityLabel" />
      <AppBadge :tone="incident.isPublic ? 'brand' : 'neutral'">
        {{
          incident.isPublic
            ? t('reliability.incidents.published')
            : t('reliability.incidents.internal')
        }}
      </AppBadge>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="update.errors.body" tone="danger">{{ update.errors.body }}</AppAlert>

      <!-- The form comes first. Everything below it is read once; this is the
           thing somebody is here to do, possibly for the fourth time. -->
      <DetailSection
        v-if="can.manage && incident.state !== 'resolved'"
        :title="t('reliability.incidents.post_update')"
      >
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submit">
          <AppTextarea
            v-model="update.body"
            :label="t('reliability.incidents.update_body')"
            :rows="3"
            :error="update.errors.body"
          />

          <AppSelect
            v-model="update.state"
            :label="t('reliability.incidents.update_state')"
            :options="states"
            :error="update.errors.state"
          />

          <AppCheckbox
            v-if="incident.isPublic"
            v-model="update.is_public"
            :label="t('reliability.incidents.update_public')"
          />

          <div>
            <AppButton
              type="button"
              variant="primary"
              :loading="update.processing"
              @click="isResolving ? (resolving = true) : submit()"
            >
              {{
                isResolving ? t('reliability.incidents.resolve') : t('reliability.incidents.post')
              }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <DetailSection :title="t('reliability.incidents.about')">
        <DescriptionList :items="facts" />
      </DetailSection>

      <DetailSection
        :title="t('reliability.incidents.timeline')"
        :description="t('reliability.incidents.timeline_intro')"
      >
        <ol class="divide-line-subtle divide-y">
          <li
            v-for="entry in incident.updates"
            :key="entry.id"
            class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0"
          >
            <div class="flex flex-wrap items-center gap-2">
              <AppStatus :tone="asTone(entry.stateTone)" :label="entry.stateLabel" />
              <span class="text-content-subtle text-chrome tabular-nums">
                {{ when(entry.writtenAt) }}
              </span>
              <span v-if="entry.author" class="text-content-muted text-chrome">
                {{ entry.author }}
              </span>
              <AppBadge v-if="incident.isPublic" :tone="entry.isPublic ? 'brand' : 'neutral'">
                {{
                  entry.isPublic
                    ? t('reliability.incidents.published')
                    : t('reliability.incidents.internal')
                }}
              </AppBadge>
            </div>
            <p class="text-body max-w-[80ch] whitespace-pre-line">{{ entry.body }}</p>
          </li>
        </ol>
      </DetailSection>

      <DetailSection
        :title="t('reliability.incidents.alerts')"
        :description="t('reliability.incidents.alerts_intro')"
      >
        <!--
          A table rather than a row of words, because the severity vocabulary
          answers *when* — "During the day", "Now" — and a word like that with
          no column header above it is a word with no question. The alerts
          list states it under URGENCY; so does this.
        -->
        <AppTable v-if="incident.alerts.length > 0" name="incident-alerts" :columns="ALERT_COLUMNS">
          <AppTableRow v-for="alert in incident.alerts" :key="alert.id">
            <td data-col="subject" class="font-medium">{{ alert.subject }}</td>
            <td data-col="severity">
              <AppStatus :tone="asTone(alert.severityTone)" :label="alert.severityLabel" />
            </td>
            <td data-col="observed" class="tabular-nums">{{ alert.observed ?? '—' }}</td>
            <td data-col="rule" class="text-content-muted">{{ alert.rule ?? '—' }}</td>
            <!--
              Not offered once it is resolved: the impact was computed from
              exactly these rows and then frozen, so changing them afterwards
              leaves a stored figure nobody can reconcile. The use case
              refuses it too — this only stops the button being there.
            -->
            <td data-col="actions" class="text-right whitespace-nowrap">
              <AppButton
                v-if="can.manage && incident.state !== 'resolved'"
                size="sm"
                variant="ghost"
                @click="detach(alert.id)"
              >
                {{ t('reliability.incidents.detach') }}
              </AppButton>
            </td>
          </AppTableRow>
        </AppTable>

        <p v-else class="text-content-muted text-body max-w-[80ch]">
          {{ t('reliability.incidents.no_alerts') }}
        </p>
      </DetailSection>

      <DetailSection
        v-if="can.manage && unattached.length > 0 && incident.state !== 'resolved'"
        :title="t('reliability.incidents.unattached')"
      >
        <AppTable name="unattached-alerts" :columns="UNATTACHED_COLUMNS">
          <AppTableRow v-for="alert in unattached" :key="alert.id">
            <td data-col="subject" class="font-medium">{{ alert.subject }}</td>
            <td data-col="rule" class="text-content-muted">{{ alert.rule ?? '—' }}</td>
            <td data-col="actions" class="text-right whitespace-nowrap">
              <AppButton size="sm" variant="ghost" @click="attach(alert.id)">
                {{ t('reliability.incidents.attach') }}
              </AppButton>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <DetailSection
        :title="t('reliability.incidents.impact')"
        :description="t('reliability.incidents.impact_intro')"
      >
        <MetricStrip v-if="incident.impact" :items="impactMetrics">
          <template #recurring>
            <span v-if="incident.impact.recurring.length === 0">—</span>
            <span
              v-for="line in incident.impact.recurring"
              :key="line.currency"
              class="block tabular-nums"
            >
              {{ line.amount }}
            </span>
          </template>
        </MetricStrip>

        <p v-else class="text-content-muted text-body">
          {{ t('reliability.incidents.impact_pending') }}
        </p>
      </DetailSection>
    </div>

    <AppConfirm
      :open="resolving"
      level="consequential"
      :title="t('reliability.incidents.resolve_title')"
      :description="t('reliability.incidents.resolve_body')"
      :confirm-label="t('reliability.incidents.resolve')"
      @close="resolving = false"
      @confirm="submit"
    />
  </AdminLayout>
</template>
