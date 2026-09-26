<script setup lang="ts">
/**
 * What is currently wrong, and the rules that decide it.
 *
 * One screen for both, because they are the same subject read two ways. A
 * separate rules page would be a page an operator visits once; here the edit
 * happens with the consequence on screen — which is the moment somebody
 * realises the threshold is wrong.
 *
 * **An empty list is the good outcome and says so.** A screen that looked
 * broken when nothing was wrong would be a screen people check by breaking
 * something.
 *
 * The severity is three fields rather than two: `severityTone` as well as the
 * value and the word. The tone is the server's because `AlertSeverity::tone()`
 * is where "emergency and critical are both critical to look at" is decided,
 * and a second mapping in the browser would be a second place to get it wrong.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface Option {
  value: string
  label: string
}

interface AlertRow {
  id: string
  subject: string
  subjectKey: string
  rule: string | null
  severity: string
  severityLabel: string
  severityTone: string
  state: string
  stateLabel: string
  observed: string | null
  occurrences: number
  firstSeenAt: string
  lastSeenAt: string
  clearedAt: string | null
}

interface RuleRow {
  id: string
  name: string
  subject: string
  subjectLabel: string
  target: string | null
  comparison: string | null
  comparisonLabel: string | null
  threshold: number | null
  forMinutes: number
  severity: string
  severityLabel: string
  enabled: boolean
  notify: boolean
  note: string | null
  openAlerts: number
}

const props = defineProps<{
  alerts: {
    data: AlertRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  rules: RuleRow[]
  options: { subjects: Option[]; severities: Option[]; comparisons: Option[] }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const writing = ref(false)
const deleting = ref<RuleRow | null>(null)

const form = useForm({
  name: '',
  subject: props.options.subjects[0]?.value ?? 'metric',
  target: '',
  comparison: props.options.comparisons[0]?.value ?? 'above',
  threshold: '',
  for_minutes: 0,
  severity: props.options.severities[0]?.value ?? 'warning',
  enabled: true,
  notify: true,
  note: '',
})

/**
 * A threshold is only asked for where it means something. A field saying "90"
 * next to "the scheduler has stopped" is a field nobody can fill in.
 */
const isNumeric = computed(() => form.subject === 'metric' || form.subject === 'capacity')

const ALERT_COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('reliability.alerts.columns.subject') },
  { key: 'severity', label: t('reliability.alerts.columns.severity') },
  { key: 'observed', label: t('reliability.alerts.columns.observed') },
  { key: 'rule', label: t('reliability.alerts.columns.rule') },
  { key: 'state', label: t('reliability.alerts.columns.state') },
  { key: 'since', label: t('reliability.alerts.columns.since') },
  { key: 'seen', label: t('reliability.alerts.columns.seen'), numeric: true, optional: true },
]

const RULE_COLUMNS: TableColumn[] = [
  { key: 'name', label: t('reliability.rules.columns.name') },
  { key: 'subject', label: t('reliability.rules.columns.subject') },
  { key: 'threshold', label: t('reliability.rules.columns.threshold') },
  { key: 'severity', label: t('reliability.rules.columns.severity') },
  { key: 'open', label: t('reliability.rules.columns.open'), numeric: true },
  { key: 'state', label: t('reliability.rules.columns.state') },
  { key: 'actions', label: '' },
]

function submit(): void {
  form.post('/admin/reliability/alert-rules', {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      writing.value = false
    },
  })
}

function toggleEnabled(rule: RuleRow): void {
  router.put(
    `/admin/reliability/alert-rules/${rule.id}`,
    {
      name: rule.name,
      subject: rule.subject,
      target: rule.target,
      comparison: rule.comparison,
      threshold: rule.threshold === null ? null : String(rule.threshold),
      for_minutes: rule.forMinutes,
      severity: rule.severity,
      enabled: !rule.enabled,
      notify: rule.notify,
      note: rule.note,
    },
    { preserveScroll: true },
  )
}

function remove(): void {
  const rule = deleting.value

  if (rule === null) return

  router.delete(`/admin/reliability/alert-rules/${rule.id}`, {
    preserveScroll: true,
    onFinish: () => (deleting.value = null),
  })
}

function toggleAll(): void {
  router.get(
    '/admin/reliability/alerts',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function when(value: string): string {
  return new Date(value).toLocaleString()
}

/** What a rule is asking, in one line. */
function asks(rule: RuleRow): string {
  if (rule.threshold === null || rule.comparisonLabel === null) return '—'

  return `${rule.comparisonLabel} ${rule.threshold}`
}
</script>

<template>
  <Head :title="t('reliability.alerts.title')" />

  <AdminLayout
    :heading="t('reliability.alerts.title')"
    :description="t('reliability.alerts.intro')"
  >
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('reliability.alerts.show_open') : t('reliability.alerts.show_all') }}
      </AppButton>
      <AppButton v-if="can.manage" variant="primary" icon="add" @click="writing = !writing">
        {{ t('reliability.rules.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="writing && can.manage"
        :title="t('reliability.rules.add')"
        :description="t('reliability.rules.add_intro')"
      >
        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <div class="grid gap-4 md:grid-cols-2">
            <AppInput
              v-model="form.name"
              :label="t('reliability.rules.name')"
              :error="form.errors.name"
            />
            <AppSelect
              v-model="form.subject"
              :label="t('reliability.rules.subject')"
              :options="options.subjects"
              :error="form.errors.subject"
            />
          </div>

          <div class="grid gap-4 md:grid-cols-3">
            <AppInput
              v-model="form.target"
              :label="t('reliability.rules.target')"
              :hint="t('reliability.rules.target_hint')"
              :error="form.errors.target"
            />
            <AppSelect
              v-if="isNumeric"
              v-model="form.comparison"
              :label="t('reliability.rules.comparison')"
              :options="options.comparisons"
              :error="form.errors.comparison"
            />
            <AppInput
              v-if="isNumeric"
              v-model="form.threshold"
              :label="t('reliability.rules.threshold')"
              :hint="t('reliability.rules.threshold_hint')"
              :error="form.errors.threshold"
            />
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <AppInput
              v-model.number="form.for_minutes"
              type="number"
              :label="t('reliability.rules.for_minutes')"
              :hint="t('reliability.rules.for_minutes_hint')"
              :error="form.errors.for_minutes"
            />
            <AppSelect
              v-model="form.severity"
              :label="t('reliability.rules.severity')"
              :options="options.severities"
              :error="form.errors.severity"
            />
          </div>

          <AppTextarea
            v-model="form.note"
            :label="t('reliability.rules.note')"
            :rows="2"
            :error="form.errors.note"
          />

          <AppCheckbox
            v-model="form.notify"
            :label="t('reliability.rules.notify')"
            :hint="t('reliability.rules.notify_hint')"
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('reliability.rules.add') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <!-- Nothing wrong is the good outcome, and the empty state says so
           rather than looking like a screen that failed to load. -->
      <EmptyState
        v-if="alerts.data.length === 0 && rules.length > 0"
        icon="ok"
        :title="t('reliability.alerts.empty')"
        :description="t('reliability.alerts.empty_detail')"
        boxed
      />

      <EmptyState
        v-else-if="rules.length === 0"
        icon="notifications"
        :title="t('reliability.alerts.no_rules')"
        :description="t('reliability.alerts.no_rules_detail')"
        boxed
      />

      <AppTable v-if="alerts.data.length > 0" name="alerts" :columns="ALERT_COLUMNS">
        <AppTableRow v-for="alert in alerts.data" :key="alert.id">
          <td data-col="subject" class="font-medium">
            {{ alert.subject }}
            <span class="text-content-subtle text-chrome block font-mono">
              {{ alert.subjectKey }}
            </span>
          </td>
          <td data-col="severity">
            <AppStatus :tone="statusTone(alert.severityTone)" :label="alert.severityLabel" />
          </td>
          <td data-col="observed" class="tabular-nums">{{ alert.observed ?? '—' }}</td>
          <td data-col="rule" class="text-content-muted">{{ alert.rule ?? '—' }}</td>
          <td data-col="state">
            <AppStatus :tone="statusTone(alert.state)" :label="alert.stateLabel" />
          </td>
          <td data-col="since" class="text-content-muted text-chrome tabular-nums">
            {{ when(alert.firstSeenAt) }}
          </td>
          <!-- The bare number: the column header already says what it
               counts, and ":count times" reads as "1 times" the first time
               anything is seen once. `useTranslations()` has no
               `trans_choice`, and a header plus a figure needs none. -->
          <td data-col="seen" class="numeric text-content-muted tabular-nums">
            {{ alert.occurrences }}
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="alerts.links" :total="alerts.total" />

      <DetailSection
        v-if="rules.length > 0"
        :title="t('reliability.rules.title')"
        :description="t('reliability.rules.intro')"
      >
        <AppTable name="alert-rules" :columns="RULE_COLUMNS" flush>
          <AppTableRow v-for="rule in rules" :key="rule.id">
            <td data-col="name" class="font-medium">
              {{ rule.name }}
              <span v-if="rule.target" class="text-content-subtle text-chrome block font-mono">
                {{ rule.target }}
              </span>
            </td>
            <td data-col="subject" class="text-content-muted">{{ rule.subjectLabel }}</td>
            <td data-col="threshold" class="text-content-muted tabular-nums">{{ asks(rule) }}</td>
            <td data-col="severity" class="text-content-muted">{{ rule.severityLabel }}</td>
            <td data-col="open" class="numeric tabular-nums">{{ rule.openAlerts }}</td>
            <td data-col="state">
              <AppStatus
                :tone="rule.enabled ? 'healthy' : 'neutral'"
                :label="rule.enabled ? t('reliability.rules.enabled') : t('ui.common.disabled')"
              />
            </td>
            <td data-col="actions" class="text-right whitespace-nowrap">
              <span v-if="can.manage" class="row-actions inline-flex gap-1">
                <AppButton size="sm" variant="ghost" @click="toggleEnabled(rule)">
                  {{ rule.enabled ? t('ui.common.disable') : t('ui.common.enable') }}
                </AppButton>
                <AppButton size="sm" variant="danger-subtle" @click="deleting = rule">
                  {{ t('reliability.rules.delete') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>
    </div>

    <AppConfirm
      :open="deleting !== null"
      level="consequential"
      :title="t('reliability.rules.delete_title')"
      :description="t('reliability.rules.delete_body')"
      :confirm-label="t('reliability.rules.delete')"
      @close="deleting = null"
      @confirm="remove"
    />
  </AdminLayout>
</template>
