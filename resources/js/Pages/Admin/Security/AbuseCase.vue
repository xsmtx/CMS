<script setup lang="ts">
/**
 * One abuse case: what arrived, whose it was, what was done, and the
 * evidence (§13).
 *
 * **The note form is the top of the page**, for the reason an incident's
 * update form is: everything else here is read once, and the thing somebody
 * has this screen open to do is record what just happened.
 *
 * **Acting is a separate section with its own permission**, because
 * suspending a paying customer is a commercial decision and recording a
 * complaint is not. Three of the four actions land in "somebody has to do
 * it", which is honest rather than unfinished — this platform has no seam for
 * them and an action that silently did nothing would be worse.
 *
 * **Evidence shows its deadline.** Each reference is deleted when its
 * retention passes; a desk reading this a year later should know that the
 * absence of evidence is a policy rather than a mistake.
 */
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DescriptionList from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface Option {
  value: string
  label: string
}

interface CaseEvent {
  id: string
  body: string
  state: string
  stateLabel: string
  stateTone: string
  author: string | null
  writtenAt: string | null
}

interface Evidence {
  id: string
  kind: string
  kindLabel: string
  reference: string
  capturedAt: string
  retainUntil: string
}

interface ActionRecord {
  id: string
  action: string
  actionLabel: string
  state: string
  stateLabel: string
  stateTone: string
  reason: string
  service: string | null
  decider: string | null
  result: string | null
  performedAt: string | null
}

interface AbuseCaseProps {
  id: string
  reference: string
  summary: string
  kind: string
  kindLabel: string
  state: string
  stateLabel: string
  stateTone: string
  severity: string
  severityLabel: string
  severityTone: string
  customer: string | null
  customerId: string | null
  occurredAt: string
  reportedAt: string
  closedAt: string | null
  actionCount: number
  source: string | null
  externalReference: string | null
  subjectType: string | null
  subjectValue: string | null
  domain: string | null
  service: string | null
  events: CaseEvent[]
  evidence: Evidence[]
  actions: ActionRecord[]
}

const props = defineProps<{
  abuseCase: AbuseCaseProps
  options: {
    states: Option[]
    actions: Option[]
    evidenceKinds: Option[]
    services: Option[]
  }
  can: { manage: boolean; act: boolean }
}>()

const { t } = useTranslations()

const note = useForm({ body: '', state: props.abuseCase.state })
const action = useForm({ action: props.options.actions[0]?.value ?? '', reason: '', service: '' })
const evidence = useForm({
  kind: props.options.evidenceKinds[0]?.value ?? 'note',
  reference: '',
})

/** Only suspension names a service; the others would ask for nothing. */
const needsService = computed(() => action.action === 'suspend_service')

const isOpen = computed(
  () => !['actioned', 'no_action', 'rejected'].includes(props.abuseCase.state),
)

const facts = computed(() => [
  { key: 'kind', label: t('security.abuse.kind'), value: props.abuseCase.kindLabel },
  { key: 'severity', label: t('security.abuse.severity'), value: props.abuseCase.severityLabel },
  {
    key: 'subject',
    label: t('security.abuse.subject_value'),
    value: props.abuseCase.subjectValue ?? '—',
  },
  {
    key: 'occurred',
    label: t('security.abuse.occurred_at'),
    value: when(props.abuseCase.occurredAt),
  },
  { key: 'source', label: t('security.abuse.source'), value: props.abuseCase.source ?? '—' },
  {
    key: 'reference',
    label: t('security.abuse.external_reference'),
    value: props.abuseCase.externalReference ?? '—',
  },
])

const ACTION_COLUMNS: TableColumn[] = [
  { key: 'action', label: t('security.abuse.act_action') },
  { key: 'state', label: t('security.abuse.columns.state') },
  { key: 'reason', label: t('security.abuse.act_reason') },
  { key: 'decider', label: t('security.abuse.act_decider') },
]

function submitNote(): void {
  note.post(`/admin/security/abuse/${props.abuseCase.id}/notes`, {
    preserveScroll: true,
    onSuccess: () => note.reset('body'),
  })
}

function submitAction(): void {
  action.post(`/admin/security/abuse/${props.abuseCase.id}/actions`, {
    preserveScroll: true,
    onSuccess: () => action.reset('reason'),
  })
}

function submitEvidence(): void {
  evidence.post(`/admin/security/abuse/${props.abuseCase.id}/evidence`, {
    preserveScroll: true,
    onSuccess: () => evidence.reset('reference'),
  })
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="abuseCase.summary" />

  <AdminLayout :heading="abuseCase.summary">
    <template #meta>
      <span class="font-mono">{{ abuseCase.reference }}</span>
    </template>

    <template #status>
      <AppStatus :tone="asTone(abuseCase.stateTone)" :label="abuseCase.stateLabel" />
      <AppStatus :tone="asTone(abuseCase.severityTone)" :label="abuseCase.severityLabel" />
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="note.errors.body" tone="danger">{{ note.errors.body }}</AppAlert>
      <AppAlert v-if="action.errors.action" tone="danger">{{ action.errors.action }}</AppAlert>

      <DetailSection v-if="can.manage && isOpen" :title="t('security.abuse.note')">
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submitNote">
          <AppTextarea
            v-model="note.body"
            :label="t('security.abuse.note_body')"
            :rows="3"
            :error="note.errors.body"
          />
          <AppSelect
            v-model="note.state"
            :label="t('security.abuse.note_state')"
            :options="options.states"
            :error="note.errors.state"
          />
          <div>
            <AppButton type="submit" variant="primary" :loading="note.processing">
              {{ t('security.abuse.note_save') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <DetailSection :title="t('security.abuse.about')">
        <DescriptionList :items="facts" />

        <!--
          Whose it was at the time. When nothing was found, the sentence says
          why rather than leaving a dash a reader would take for a bug — this
          platform genuinely could not tell, and that is a fact about the
          complaint rather than about the software.
        -->
        <p v-if="abuseCase.customerId" class="text-body mt-4">
          <Link
            :href="`/admin/customers/${abuseCase.customerId}`"
            class="text-brand hover:underline"
          >
            {{ abuseCase.customer }}
          </Link>
          <span class="text-content-muted text-chrome">
            · {{ t('security.abuse.attributed_at', { at: when(abuseCase.occurredAt) }) }}
          </span>
        </p>

        <p v-else class="text-content-muted text-body mt-4 max-w-[80ch]">
          {{ t('security.abuse.unattributed_detail') }}
        </p>
      </DetailSection>

      <DetailSection
        :title="t('security.abuse.timeline')"
        :description="t('security.abuse.timeline_intro')"
      >
        <ol class="divide-line-subtle divide-y">
          <li
            v-for="event in abuseCase.events"
            :key="event.id"
            class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0"
          >
            <div class="flex flex-wrap items-center gap-2">
              <AppStatus :tone="asTone(event.stateTone)" :label="event.stateLabel" />
              <span class="text-content-subtle text-chrome tabular-nums">
                {{ when(event.writtenAt) }}
              </span>
              <span v-if="event.author" class="text-content-muted text-chrome">
                {{ event.author }}
              </span>
            </div>
            <p class="text-body max-w-[80ch] whitespace-pre-line">{{ event.body }}</p>
          </li>
        </ol>
      </DetailSection>

      <DetailSection
        v-if="can.act"
        :title="t('security.abuse.act')"
        :description="t('security.abuse.act_intro')"
      >
        <form v-if="isOpen" class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submitAction">
          <AppSelect
            v-model="action.action"
            :label="t('security.abuse.act_action')"
            :options="options.actions"
            :error="action.errors.action"
          />

          <!--
            An empty required dropdown with a button under it is worse than
            no form: the server refuses on a field whose list was empty, and
            the operator learns nothing from it. When there is nothing to
            suspend, this says why instead.
          -->
          <AppSelect
            v-if="needsService && options.services.length > 0"
            v-model="action.service"
            :label="t('security.abuse.act_service')"
            :options="options.services"
            :error="action.errors.service"
          />

          <p v-else-if="needsService" class="text-content-muted text-body max-w-[70ch]">
            {{ t('security.abuse.act_no_service') }}
          </p>

          <AppTextarea
            v-model="action.reason"
            :label="t('security.abuse.act_reason')"
            :hint="t('security.abuse.act_reason_hint')"
            :rows="2"
            :error="action.errors.reason"
          />

          <div>
            <AppButton
              type="submit"
              variant="danger-subtle"
              :loading="action.processing"
              :disabled="needsService && options.services.length === 0"
            >
              {{ t('security.abuse.act') }}
            </AppButton>
          </div>
        </form>

        <AppTable
          v-if="abuseCase.actions.length > 0"
          name="abuse-actions"
          :columns="ACTION_COLUMNS"
          class="mt-6"
        >
          <AppTableRow v-for="record in abuseCase.actions" :key="record.id">
            <td data-col="action" class="font-medium">
              {{ record.actionLabel }}
              <span v-if="record.service" class="text-content-subtle text-chrome block">
                {{ record.service }}
              </span>
            </td>
            <td data-col="state">
              <AppStatus :tone="asTone(record.stateTone)" :label="record.stateLabel" />
              <span v-if="record.result" class="text-danger text-chrome block">
                {{ record.result }}
              </span>
            </td>
            <td data-col="reason" class="text-content-muted">{{ record.reason }}</td>
            <td data-col="decider" class="text-content-muted text-chrome">
              {{ record.decider ?? '—' }}
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <DetailSection
        v-if="can.manage"
        :title="t('security.abuse.evidence')"
        :description="t('security.abuse.evidence_intro')"
      >
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submitEvidence">
          <div class="grid gap-4 md:grid-cols-2">
            <AppSelect
              v-model="evidence.kind"
              :label="t('security.abuse.evidence_kind')"
              :options="options.evidenceKinds"
              :error="evidence.errors.kind"
            />
            <AppInput
              v-model="evidence.reference"
              :label="t('security.abuse.evidence_reference')"
              :error="evidence.errors.reference"
            />
          </div>
          <div>
            <AppButton type="submit" variant="secondary" :loading="evidence.processing">
              {{ t('security.abuse.evidence_keep') }}
            </AppButton>
          </div>
        </form>

        <ul v-if="abuseCase.evidence.length > 0" class="divide-line-subtle mt-6 divide-y">
          <li
            v-for="item in abuseCase.evidence"
            :key="item.id"
            class="flex flex-wrap items-center gap-3 py-2 first:pt-0 last:pb-0"
          >
            <span class="text-content-muted text-chrome">{{ item.kindLabel }}</span>
            <span class="text-body max-w-[60ch] truncate font-mono">{{ item.reference }}</span>
            <!--
              The deadline, shown rather than implied. A desk reading this a
              year later should know that missing evidence is a policy this
              installation set, not something that went wrong.
            -->
            <span class="text-content-subtle text-chrome ml-auto">
              {{ t('security.abuse.evidence_until', { date: day(item.retainUntil) }) }}
            </span>
          </li>
        </ul>

        <p v-else class="text-content-muted text-body mt-4">
          {{ t('security.abuse.evidence_none') }}
        </p>
      </DetailSection>
    </div>
  </AdminLayout>
</template>
