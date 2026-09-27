<script setup lang="ts">
/**
 * The abuse desk (§13).
 *
 * **An empty list is the good outcome and says so**, like the alerts screen:
 * a page that looked broken when nobody was complaining is a page people
 * check by breaking something.
 *
 * The one column worth explaining is *Whose*. It says who held the address or
 * the domain **when the complaint says it happened** — not who holds it now —
 * and an empty one is a real answer rather than a gap: an address in a range
 * nobody recorded, a domain that is not ours, a sender who is simply wrong.
 * A report nobody can attribute is still a report somebody has to answer.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
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

interface CaseRow {
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
}

const props = defineProps<{
  cases: {
    data: CaseRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  options: { kinds: Option[]; severities: Option[] }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const recording = ref(false)

const form = useForm({
  summary: '',
  kind: props.options.kinds[0]?.value ?? 'spam',
  severity: props.options.severities[0]?.value ?? 'warning',
  subject_type: 'ip',
  subject_value: '',
  occurred_at: '',
  source: '',
  external_reference: '',
})

/** A complaint that named neither is a real complaint, so the field goes. */
const namesSomething = computed(() => form.subject_type !== 'none')

const SUBJECTS: Option[] = [
  { value: 'ip', label: t('security.abuse.subjects.ip') },
  { value: 'domain', label: t('security.abuse.subjects.domain') },
  { value: 'none', label: t('security.abuse.subjects.none') },
]

const COLUMNS: TableColumn[] = [
  { key: 'case', label: t('security.abuse.columns.case') },
  { key: 'kind', label: t('security.abuse.columns.kind') },
  { key: 'state', label: t('security.abuse.columns.state') },
  { key: 'customer', label: t('security.abuse.columns.customer') },
  { key: 'occurred', label: t('security.abuse.columns.occurred') },
  { key: 'actions', label: t('security.abuse.columns.actions'), numeric: true },
]

function submit(): void {
  form.post('/admin/security/abuse', {
    onSuccess: () => {
      form.reset()
      recording.value = false
    },
  })
}

function toggleAll(): void {
  router.get(
    '/admin/security/abuse',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function when(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('security.abuse.title')" />

  <AdminLayout :heading="t('ui.nav.abuse')" :description="t('security.abuse.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('security.abuse.show_open') : t('security.abuse.show_all') }}
      </AppButton>
      <AppButton v-if="can.manage" variant="primary" icon="add" @click="recording = !recording">
        {{ t('security.abuse.open') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="recording && can.manage"
        :title="t('security.abuse.open')"
        :description="t('security.abuse.open_intro')"
      >
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submit">
          <AppInput
            v-model="form.summary"
            :label="t('security.abuse.summary')"
            :error="form.errors.summary"
          />

          <div class="grid gap-4 md:grid-cols-2">
            <AppSelect
              v-model="form.kind"
              :label="t('security.abuse.kind')"
              :options="options.kinds"
              :error="form.errors.kind"
            />
            <AppSelect
              v-model="form.severity"
              :label="t('security.abuse.severity')"
              :options="options.severities"
              :error="form.errors.severity"
            />
          </div>

          <div class="grid gap-4 md:grid-cols-2">
            <AppSelect
              v-model="form.subject_type"
              :label="t('security.abuse.subject_type')"
              :options="SUBJECTS"
              :error="form.errors.subject_type"
            />
            <AppInput
              v-if="namesSomething"
              v-model="form.subject_value"
              :label="t('security.abuse.subject_value')"
              :hint="t('security.abuse.subject_hint')"
              :error="form.errors.subject_value"
            />
          </div>

          <AppInput
            v-model="form.occurred_at"
            type="datetime-local"
            :label="t('security.abuse.occurred_at')"
            :hint="t('security.abuse.occurred_at_hint')"
            :error="form.errors.occurred_at"
          />

          <div class="grid gap-4 md:grid-cols-2">
            <AppInput
              v-model="form.source"
              :label="t('security.abuse.source')"
              :hint="t('security.abuse.source_hint')"
              :error="form.errors.source"
            />
            <AppInput
              v-model="form.external_reference"
              :label="t('security.abuse.external_reference')"
              :error="form.errors.external_reference"
            />
          </div>

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('security.abuse.record') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="cases.data.length === 0"
        icon="ok"
        :title="t('security.abuse.empty')"
        :description="t('security.abuse.empty_detail')"
        boxed
      />

      <AppTable v-else name="abuse-cases" :columns="COLUMNS">
        <AppTableRow v-for="row in cases.data" :key="row.id">
          <!--
            The link is in the first cell: a `<tr>` cannot be wrapped in an
            anchor and `AppTableRow` has no `href`, so one passed to it would
            fall through and do nothing.
          -->
          <td data-col="case">
            <Link
              :href="`/admin/security/abuse/${row.id}`"
              class="text-brand font-medium hover:underline"
            >
              {{ row.summary }}
            </Link>
            <span class="text-content-subtle text-chrome block font-mono">
              {{ row.reference }}
            </span>
          </td>
          <td data-col="kind" class="text-content-muted">{{ row.kindLabel }}</td>
          <td data-col="state">
            <AppStatus :tone="asTone(row.stateTone)" :label="row.stateLabel" />
          </td>
          <!--
            Whose it was **when it happened**, and an empty one is an answer
            rather than a gap — so it says so in words rather than drawing a
            dash a reader would take for missing data.
          -->
          <td data-col="customer">
            <Link
              v-if="row.customerId"
              :href="`/admin/customers/${row.customerId}`"
              class="text-brand hover:underline"
            >
              {{ row.customer }}
            </Link>
            <span v-else class="text-content-subtle">
              {{ t('security.abuse.unattributed') }}
            </span>
          </td>
          <td data-col="occurred" class="text-content-muted text-chrome tabular-nums">
            {{ when(row.occurredAt) }}
          </td>
          <td data-col="actions" class="numeric tabular-nums">{{ row.actionCount }}</td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="cases.links" :total="cases.total" />
    </div>
  </AdminLayout>
</template>
