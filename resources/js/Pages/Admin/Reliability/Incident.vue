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
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
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
import MoneyInput from '../../../Components/MoneyInput.vue'
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

interface CreditInvoice {
  id: string
  number: string
  currency: string
  total: string
  totalMinor: number
  issuedOn: string | null
}

interface AffectedCustomer {
  id: string
  name: string
  services: number
  invoices: CreditInvoice[]
  credited: { amount: string; at: string | null } | null
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
  postmortemAt: string | null
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
  affected: AffectedCustomer[]
  can: { manage: boolean; credit: boolean; confirmed: boolean }
  // Whether this seller turned the incident draft on (ADR 0050).
  ai: { update: boolean }
}>()

const page = usePage()
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

// Its own headers rather than the impact strip's: those two words label
// *counts* of a whole incident, and a column heading names what is in each
// row. Borrowing one for the other is how a screen ends up saying "Customers"
// above a single customer's name.
const AFFECTED_COLUMNS: TableColumn[] = [
  { key: 'customer', label: t('reliability.incidents.credit_customer') },
  { key: 'services', label: t('reliability.incidents.credit_affected'), numeric: true },
  { key: 'state', label: '' },
  { key: 'actions', label: '' },
]

const update = useForm({
  body: '',
  state: props.incident.state,
  is_public: false,
})

const resolving = ref(false)

/*
 * The draft, flashed rather than stored: a draft is not an update until
 * somebody posts it, and a table of drafts nobody posted would be a second
 * copy of what this incident said (ADR 0050).
 */
const drafting = ref(false)

const draft = computed(() => page.props.flash.draft ?? null)

function askForDraft(): void {
  drafting.value = true

  router.post(
    `/admin/reliability/incidents/${props.incident.id}/draft`,
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        drafting.value = false
      },
      onSuccess: () => {
        // Appended rather than replacing: somebody who has already started
        // typing has not asked to lose it.
        if (draft.value !== null) {
          update.body =
            update.body === ''
              ? draft.value.text
              : `${update.body}

${draft.value.text}`
        }
      },
    },
  )
}

const postmortem = useForm({ postmortem: props.incident.postmortem ?? '' })

/**
 * One form, reused per customer rather than one form each: an operator credits
 * one customer at a time, and twelve `useForm`s on a screen is twelve sets of
 * errors that can disagree with each other.
 */
const crediting = ref<AffectedCustomer | null>(null)

const credit = useForm({ invoice: '', amount_minor: 0, reason: '' })

const isResolved = computed(() => props.incident.state === 'resolved')

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

function savePostmortem(): void {
  postmortem.put(`/admin/reliability/incidents/${props.incident.id}/postmortem`, {
    preserveScroll: true,
  })
}

/**
 * Opening the form picks their newest invoice and offers nothing for the
 * amount. A prefilled figure would be this platform suggesting what an outage
 * is worth, which is the seller's SLA to read and not ours.
 */
function startCredit(customer: AffectedCustomer): void {
  /*
   * The password first, if the window has closed. `auth.recent` redirects
   * with a GET, so being challenged on submit throws away the amount and the
   * sentence already typed — and a money form is the worst place in the
   * product to lose what somebody wrote.
   */
  if (!props.can.confirmed) {
    router.get(`/admin/reliability/incidents/${props.incident.id}/credits/confirm`)

    return
  }

  crediting.value = customer
  credit.reset()
  credit.clearErrors()
  credit.invoice = customer.invoices[0]?.id ?? ''
}

function submitCredit(): void {
  const customer = crediting.value

  if (customer === null) return

  credit.post(`/admin/reliability/incidents/${props.incident.id}/credits`, {
    preserveScroll: true,
    onSuccess: () => (crediting.value = null),
  })
}

/** The currency is the invoice's, never a field somebody can disagree with. */
const creditCurrency = computed(
  () => crediting.value?.invoices.find((invoice) => invoice.id === credit.invoice)?.currency ?? '',
)

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
        <!--
          Beside the box it fills. §15's rule that the state and the sentence
          are one act is untouched: this writes nothing, and `note()` still
          moves the state when the operator presses the button below.
        -->
        <template v-if="ai.update" #actions>
          <AppButton size="sm" variant="ghost" :loading="drafting" @click="askForDraft">
            {{ t('ai.draft_update') }}
          </AppButton>
        </template>
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submit">
          <AppTextarea
            v-model="update.body"
            :label="t('reliability.incidents.update_body')"
            :rows="3"
            :error="update.errors.body"
          />

          <!-- Said rather than assumed: a model wrote what is in the box. -->
          <AppAlert v-if="draft" tone="info">
            {{ t('ai.drafted', { model: draft.model }) }}
          </AppAlert>

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

      <!--
        The postmortem. Only once it has ended, because one written during an
        outage is a guess and the timeline it comes from is not finished.
      -->
      <DetailSection
        :title="t('reliability.incidents.postmortem')"
        :description="t('reliability.incidents.postmortem_intro')"
      >
        <form
          v-if="can.manage && isResolved"
          class="flex max-w-[80ch] flex-col gap-4"
          @submit.prevent="savePostmortem"
        >
          <AppTextarea
            v-model="postmortem.postmortem"
            :label="t('reliability.incidents.postmortem_body')"
            :hint="t('reliability.incidents.postmortem_hint')"
            :rows="8"
            :error="postmortem.errors.postmortem"
          />

          <div class="flex flex-wrap items-center gap-4">
            <AppButton type="submit" variant="primary" :loading="postmortem.processing">
              {{ t('reliability.incidents.postmortem_save') }}
            </AppButton>

            <span v-if="incident.postmortemAt" class="text-content-muted text-chrome">
              {{
                t('reliability.incidents.postmortem_written', {
                  at: when(incident.postmortemAt),
                })
              }}
            </span>
          </div>
        </form>

        <p v-else-if="!isResolved" class="text-content-muted text-body">
          {{ t('reliability.incidents.postmortem_pending') }}
        </p>

        <p v-else class="text-body max-w-[80ch] whitespace-pre-line">
          {{ incident.postmortem ?? '—' }}
        </p>
      </DetailSection>

      <!--
        Who it actually hit, and the credit. The server sends this only to
        somebody who may act on it: a list of affected customers is a list of
        who had a bad day.
      -->
      <DetailSection
        v-if="can.credit"
        :title="t('reliability.incidents.credits')"
        :description="t('reliability.incidents.credits_intro')"
      >
        <p v-if="!isResolved" class="text-content-muted text-body">
          {{ t('reliability.incidents.credits_pending') }}
        </p>

        <p v-else-if="affected.length === 0" class="text-content-muted text-body max-w-[80ch]">
          {{ t('reliability.incidents.credits_none') }}
        </p>

        <AppTable v-else name="affected-customers" :columns="AFFECTED_COLUMNS">
          <AppTableRow v-for="row in affected" :key="row.id">
            <td data-col="customer" class="font-medium">
              <Link :href="`/admin/customers/${row.id}`" class="text-brand hover:underline">
                {{ row.name }}
              </Link>
            </td>
            <td data-col="services" class="numeric tabular-nums">{{ row.services }}</td>
            <td data-col="state" class="text-content-muted text-chrome">
              <span v-if="row.credited">
                {{
                  t('reliability.incidents.credited_already', {
                    amount: row.credited.amount,
                    at: when(row.credited.at),
                  })
                }}
              </span>
              <span v-else-if="row.invoices.length === 0">
                {{ t('reliability.incidents.credit_no_invoice') }}
              </span>
            </td>
            <td data-col="actions" class="text-right whitespace-nowrap">
              <AppButton
                v-if="row.credited === null && row.invoices.length > 0"
                size="sm"
                variant="ghost"
                @click="startCredit(row)"
              >
                {{ t('reliability.incidents.credit') }}
              </AppButton>
            </td>
          </AppTableRow>
        </AppTable>

        <!--
          The form appears under the table rather than in a dialog: it asks
          for three things, one of which is money, and a confirmation is the
          wrong shape for a form somebody has to think about.
        -->
        <form
          v-if="crediting"
          class="border-line mt-6 flex max-w-[80ch] flex-col gap-4 border-t pt-6"
          @submit.prevent="submitCredit"
        >
          <p class="text-body font-medium">{{ crediting.name }}</p>

          <AppSelect
            v-model="credit.invoice"
            :label="t('reliability.incidents.credit_invoice')"
            :options="
              crediting.invoices.map((invoice) => ({
                value: invoice.id,
                label: `${invoice.number} · ${invoice.total}`,
              }))
            "
            :error="credit.errors.invoice"
          />

          <MoneyInput
            v-model="credit.amount_minor"
            :label="t('reliability.incidents.credit_amount')"
            :exponent="2"
            :symbol="creditCurrency"
          />

          <p v-if="credit.errors.amount_minor" class="text-danger text-chrome">
            {{ credit.errors.amount_minor }}
          </p>

          <p class="text-content-muted text-chrome max-w-[70ch]">
            {{ t('reliability.incidents.credit_amount_hint') }}
          </p>

          <AppTextarea
            v-model="credit.reason"
            :label="t('reliability.incidents.credit_reason')"
            :hint="t('reliability.incidents.credit_reason_hint')"
            :rows="2"
            :error="credit.errors.reason"
          />

          <div class="flex gap-2">
            <AppButton type="submit" variant="primary" :loading="credit.processing">
              {{ t('reliability.incidents.credit') }}
            </AppButton>
            <AppButton type="button" variant="secondary" @click="crediting = null">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
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
