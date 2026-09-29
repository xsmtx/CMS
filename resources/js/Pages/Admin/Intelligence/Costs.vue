<script setup lang="ts">
/**
 * What this business pays somebody else (§21).
 *
 * **Nothing here is discovered.** No adapter reports a hosting invoice or
 * the rent, so this is rows an operator types — the same shape as tax rules
 * and dunning steps, and core ships none of those either.
 *
 * **Every field the form shows is one the server will read.** Which fields
 * appear comes from the enums themselves (`needsSubject`, `needsMetric`)
 * rather than from a literal here: the alert rule form held a second copy of
 * exactly that kind of fact and it stopped being true three phases later.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MoneyInput from '../../../Components/MoneyInput.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface Option {
  value: string
  label: string
}

interface ScopeOption extends Option {
  needsSubject: boolean
}

interface StrategyOption extends Option {
  description: string
  needsMetric: boolean
}

interface EntryRow {
  id: string
  label: string
  vendor: string | null
  scope: string
  scopeLabel: string
  subjectId: string | null
  subject: string | null
  currency: string
  amountMinor: number
  amount: string
  period: string
  periodLabel: string
  strategy: string
  strategyLabel: string
  metric: string | null
  metricLabel: string | null
  startsOn: string | null
  endsOn: string | null
  note: string | null
}

const props = defineProps<{
  entries: {
    data: EntryRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  options: {
    scopes: ScopeOption[]
    periods: Option[]
    strategies: StrategyOption[]
    metrics: Option[]
    servers: Option[]
    products: Option[]
  }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'label', label: t('intelligence.costs.columns.label') },
  { key: 'scope', label: t('intelligence.costs.columns.scope') },
  { key: 'amount', label: t('intelligence.costs.columns.amount'), numeric: true },
  { key: 'period', label: t('intelligence.costs.columns.period') },
  { key: 'shared', label: t('intelligence.costs.columns.shared') },
  { key: 'actions', label: '' },
]

const editing = ref<EntryRow | null>(null)
const adding = ref(false)
const removing = ref<EntryRow | null>(null)

const form = useForm({
  label: '',
  vendor: '',
  scope: 'server',
  subject_id: '',
  currency_code: 'EUR',
  amount_minor: 0,
  period: 'monthly',
  strategy: 'even',
  metric: '',
  starts_on: '',
  ends_on: '',
  note: '',
})

/*
 * Which fields the form shows comes from the enum, sent per option. A
 * literal here would be a second copy of a fact the server owns.
 */
const scope = computed(() => props.options.scopes.find((s) => s.value === form.scope))
const strategy = computed(() => props.options.strategies.find((s) => s.value === form.strategy))

const subjects = computed<Option[]>(() =>
  form.scope === 'product' ? props.options.products : props.options.servers,
)

const subjectLabel = computed(() =>
  form.scope === 'product'
    ? t('intelligence.costs.fields.product')
    : t('intelligence.costs.fields.server'),
)

function open(entry: EntryRow | null): void {
  editing.value = entry
  adding.value = entry === null

  form.clearErrors()
  form.label = entry?.label ?? ''
  form.vendor = entry?.vendor ?? ''
  form.scope = entry?.scope ?? 'server'
  form.subject_id = entry?.subjectId ?? ''
  form.currency_code = entry?.currency ?? 'EUR'
  form.amount_minor = entry?.amountMinor ?? 0
  form.period = entry?.period ?? 'monthly'
  form.strategy = entry?.strategy ?? 'even'
  form.metric = entry?.metric ?? ''
  form.starts_on = entry?.startsOn ?? ''
  form.ends_on = entry?.endsOn ?? ''
  form.note = entry?.note ?? ''
}

function close(): void {
  editing.value = null
  adding.value = false
  form.reset()
}

function submit(): void {
  const entry = editing.value

  if (entry === null) {
    form.post('/admin/intelligence/costs', { preserveScroll: true, onSuccess: close })

    return
  }

  form.put(`/admin/intelligence/costs/${entry.id}`, { preserveScroll: true, onSuccess: close })
}

function remove(): void {
  const entry = removing.value

  if (entry === null) return

  router.delete(`/admin/intelligence/costs/${entry.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      removing.value = null
    },
  })
}

function day(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('intelligence.costs.title')" />

  <AdminLayout :heading="t('ui.nav.costs')" :description="t('intelligence.costs.intro')">
    <template v-if="can.manage" #actions>
      <AppButton variant="secondary" @click="adding || editing ? close() : open(null)">
        {{ t('intelligence.costs.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="adding || editing"
        :title="
          editing
            ? `${editing.label} — ${t('intelligence.costs.edit')}`
            : t('intelligence.costs.add')
        "
      >
        <form class="flex max-w-3xl flex-col gap-4" @submit.prevent="submit">
          <div class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-model="form.label"
              :label="t('intelligence.costs.fields.label')"
              :hint="t('intelligence.costs.fields.label_hint')"
              :error="form.errors.label"
            />
            <AppInput
              v-model="form.vendor"
              :label="t('intelligence.costs.fields.vendor')"
              :error="form.errors.vendor"
            />
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <AppSelect
              v-model="form.scope"
              :label="t('intelligence.costs.fields.scope')"
              :options="options.scopes"
              :error="form.errors.scope"
            />
            <!--
              Asked only where there is something to name, and the enum is
              what says so.
            -->
            <AppSelect
              v-if="scope?.needsSubject"
              v-model="form.subject_id"
              :label="subjectLabel"
              :options="[{ value: '', label: t('intelligence.costs.fields.none') }, ...subjects]"
              :error="form.errors.subject_id"
            />
          </div>

          <div class="grid gap-3 sm:grid-cols-3">
            <MoneyInput
              v-model="form.amount_minor"
              :label="t('intelligence.costs.fields.amount')"
              :exponent="2"
            />
            <AppInput
              v-model="form.currency_code"
              :label="t('intelligence.costs.fields.currency')"
              :error="form.errors.currency_code"
            />
            <AppSelect
              v-model="form.period"
              :label="t('intelligence.costs.fields.period')"
              :options="options.periods"
              :error="form.errors.period"
            />
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <AppSelect
              v-model="form.strategy"
              :label="t('intelligence.costs.fields.strategy')"
              :hint="strategy?.description"
              :options="options.strategies"
              :error="form.errors.strategy"
            />
            <AppSelect
              v-if="strategy?.needsMetric"
              v-model="form.metric"
              :label="t('intelligence.costs.fields.metric')"
              :hint="t('intelligence.costs.fields.metric_hint')"
              :options="[
                { value: '', label: t('intelligence.costs.fields.none') },
                ...options.metrics,
              ]"
              :error="form.errors.metric"
            />
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-model="form.starts_on"
              type="date"
              :label="t('intelligence.costs.fields.starts_on')"
              :hint="t('intelligence.costs.fields.dates_hint')"
              :error="form.errors.starts_on"
            />
            <AppInput
              v-model="form.ends_on"
              type="date"
              :label="t('intelligence.costs.fields.ends_on')"
              :error="form.errors.ends_on"
            />
          </div>

          <AppTextarea
            v-model="form.note"
            :label="t('intelligence.costs.fields.note')"
            :error="form.errors.note"
          />

          <div class="flex gap-3">
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('intelligence.costs.fields.save') }}
            </AppButton>
            <AppButton variant="ghost" @click="close">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="entries.data.length === 0"
        icon="billing"
        :title="t('intelligence.costs.empty')"
        :description="t('intelligence.costs.empty_detail')"
      />

      <AppTable v-else name="cost-entries" :columns="COLUMNS">
        <AppTableRow v-for="entry in entries.data" :key="entry.id">
          <td data-col="label">
            <span class="font-medium">{{ entry.label }}</span>
            <span v-if="entry.vendor" class="text-content-muted text-chrome block">
              {{ entry.vendor }}
            </span>
            <span
              v-if="entry.startsOn || entry.endsOn"
              class="text-content-subtle text-chrome block tabular-nums"
            >
              {{ day(entry.startsOn)
              }}<template v-if="entry.endsOn"> — {{ day(entry.endsOn) }}</template>
            </span>
          </td>
          <td data-col="scope">
            <span>{{ entry.scopeLabel }}</span>
            <span v-if="entry.subject" class="text-content-muted text-chrome block">
              {{ entry.subject }}
            </span>
          </td>
          <td data-col="amount" class="numeric tabular-nums">{{ entry.amount }}</td>
          <td data-col="period" class="text-content-muted">{{ entry.periodLabel }}</td>
          <td data-col="shared">
            <span>{{ entry.strategyLabel }}</span>
            <span v-if="entry.metricLabel" class="text-content-subtle text-chrome block">
              {{ entry.metricLabel }}
            </span>
          </td>
          <td data-col="actions" class="w-44 text-right whitespace-nowrap">
            <span v-if="can.manage" class="row-actions inline-flex gap-1">
              <AppButton size="sm" variant="ghost" @click="open(entry)">
                {{ t('intelligence.costs.edit') }}
              </AppButton>
              <AppButton size="sm" variant="danger-subtle" @click="removing = entry">
                {{ t('intelligence.costs.remove') }}
              </AppButton>
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="entries.links" :total="entries.total" />
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('intelligence.costs.remove_title', { label: removing?.label ?? '' })"
      :description="t('intelligence.costs.remove_body')"
      :confirm-label="t('intelligence.costs.remove')"
      @confirm="remove"
      @close="removing = null"
    />
  </AdminLayout>
</template>
