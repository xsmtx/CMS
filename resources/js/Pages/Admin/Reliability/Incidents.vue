<script setup lang="ts">
/**
 * What went wrong, and what is going wrong now.
 *
 * **An empty list is the good outcome and says so**, the same as the alerts
 * screen: a page that looked broken when nothing was wrong is a page people
 * check by breaking something.
 *
 * The list defaults to what is open, because that is the question somebody
 * has this screen open to answer. Everything ever recorded is one press away
 * and is the other question — the one asked in a review, weeks later.
 *
 * Opening one is a form on this page rather than a page of its own: the
 * moment an operator needs it, a second navigation is a second thing to do
 * while something is down.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
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
import { asTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface Option {
  value: string
  label: string
}

interface MoneyRow {
  currency: string
  amount: string
  minor: number
}

interface IncidentRow {
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
  impact: {
    services: number
    customers: number
    recurring: MoneyRow[]
    frozenAt: string
  } | null
}

const props = defineProps<{
  incidents: {
    data: IncidentRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  severities: Option[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const writing = ref(false)

const form = useForm({
  title: '',
  body: '',
  severity: props.severities[0]?.value ?? 'warning',
  started_at: '',
  is_public: false,
})

const COLUMNS: TableColumn[] = [
  { key: 'reference', label: t('reliability.incidents.columns.reference') },
  { key: 'state', label: t('reliability.incidents.columns.state') },
  { key: 'severity', label: t('reliability.incidents.columns.severity') },
  { key: 'started', label: t('reliability.incidents.columns.started') },
  { key: 'duration', label: t('reliability.incidents.columns.duration') },
  { key: 'alerts', label: t('reliability.incidents.columns.alerts'), numeric: true },
  // Two columns rather than one: "0 / 0" under a header reading "Underneath"
  // is operator shorthand nobody defined, and the two figures answer
  // different questions. Both are empty until the incident is resolved,
  // because that is when the impact is frozen.
  {
    key: 'customers',
    label: t('reliability.incidents.columns.customers'),
    numeric: true,
    optional: true,
  },
  {
    key: 'recurring',
    label: t('reliability.incidents.columns.recurring'),
    numeric: true,
    optional: true,
  },
]

function submit(): void {
  form.post('/admin/reliability/incidents', {
    onSuccess: () => {
      form.reset()
      writing.value = false
    },
  })
}

function toggleAll(): void {
  router.get(
    '/admin/reliability/incidents',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function when(value: string): string {
  return new Date(value).toLocaleString()
}

/**
 * A duration a person reads, and a word rather than a growing number while it
 * is still going: a figure that changes on every refresh is a figure two
 * operators would quote differently in the same minute.
 */
function lasted(seconds: number | null): string {
  if (seconds === null) return t('reliability.incidents.still_going')
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`

  return `${(seconds / 3600).toFixed(1)}h`
}

/**
 * What it was worth, per currency — never a total. There is no rate anywhere
 * in this product, so a figure added across currencies is a figure that means
 * nothing and is the one somebody would quote.
 */
function worth(row: IncidentRow): string {
  if (row.impact === null || row.impact.recurring.length === 0) return '—'

  return row.impact.recurring.map((line) => line.amount).join(' · ')
}
</script>

<template>
  <Head :title="t('reliability.incidents.title')" />

  <AdminLayout :heading="t('ui.nav.incidents')" :description="t('reliability.incidents.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{
          filters.all ? t('reliability.incidents.show_open') : t('reliability.incidents.show_all')
        }}
      </AppButton>
      <AppButton v-if="can.manage" variant="primary" icon="add" @click="writing = !writing">
        {{ t('reliability.incidents.open') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="writing && can.manage"
        :title="t('reliability.incidents.open')"
        :description="t('reliability.incidents.open_intro')"
      >
        <!-- A title field is not 1400px of text; the detail screen's form is
             capped the same way. -->
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submit">
          <AppInput
            v-model="form.title"
            :label="t('reliability.incidents.incident_title')"
            :error="form.errors.title"
          />

          <AppTextarea
            v-model="form.body"
            :label="t('reliability.incidents.first_update')"
            :hint="t('reliability.incidents.first_update_hint')"
            :rows="3"
            :error="form.errors.body"
          />

          <div class="grid gap-4 md:grid-cols-2">
            <AppSelect
              v-model="form.severity"
              :label="t('reliability.incidents.severity')"
              :options="severities"
              :error="form.errors.severity"
            />
            <AppInput
              v-model="form.started_at"
              type="datetime-local"
              :label="t('reliability.incidents.started_at')"
              :hint="t('reliability.incidents.started_at_hint')"
              :error="form.errors.started_at"
            />
          </div>

          <AppCheckbox
            v-model="form.is_public"
            :label="t('reliability.incidents.is_public')"
            :description="t('reliability.incidents.is_public_hint')"
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('reliability.incidents.open_it') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <!-- Nothing going wrong is the good outcome, and the empty state says
           so rather than reading as a screen that failed to load. -->
      <EmptyState
        v-if="incidents.data.length === 0"
        icon="ok"
        :title="t('reliability.incidents.empty')"
        :description="t('reliability.incidents.empty_detail')"
        boxed
      />

      <AppTable v-else name="incidents" :columns="COLUMNS">
        <AppTableRow v-for="row in incidents.data" :key="row.id">
          <!--
            The link is in the first cell rather than on the row: a `<tr>`
            cannot be wrapped in an anchor and `AppTableRow` has no `href`,
            so one passed to it would fall through as an attribute and do
            nothing — which is how a detail page ends up linked to by nothing.
          -->
          <td data-col="reference">
            <Link
              :href="`/admin/reliability/incidents/${row.id}`"
              class="text-brand font-medium hover:underline"
            >
              {{ row.title }}
            </Link>
            <span class="text-content-subtle text-chrome block font-mono">
              {{ row.reference }}
            </span>
          </td>
          <td data-col="state">
            <AppStatus :tone="asTone(row.stateTone)" :label="row.stateLabel" />
          </td>
          <td data-col="severity">
            <AppStatus :tone="asTone(row.severityTone)" :label="row.severityLabel" />
          </td>
          <td data-col="started" class="text-content-muted text-chrome tabular-nums">
            {{ when(row.startedAt) }}
          </td>
          <td data-col="duration" class="text-content-muted tabular-nums">
            {{ lasted(row.durationSeconds) }}
          </td>
          <td data-col="alerts" class="numeric tabular-nums">{{ row.alertCount }}</td>
          <td data-col="customers" class="numeric text-content-muted tabular-nums">
            {{ row.impact === null ? '—' : row.impact.customers }}
          </td>
          <td data-col="recurring" class="numeric text-content-muted tabular-nums">
            {{ worth(row) }}
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="incidents.links" :total="incidents.total" />
    </div>
  </AdminLayout>
</template>
