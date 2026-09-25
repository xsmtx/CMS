<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { toDecimal, toMinor } from '../../../types/catalog'

interface PriceCell {
  action: string
  years: number
  currencyCode: string
  amountMinor: number
  costMinor: number | null
}

interface TldRow {
  id: string
  extension: string
  registrar: string | null
  minYears: number
  maxYears: number
  allowsTransfer: boolean
  allowsWhoisPrivacy: boolean
  requiresEppCode: boolean
  supportsIdn: boolean
  status: string
  position: number
  graceDays: number
  redemptionDays: number
  domains: number
  prices: PriceCell[]
}

const props = defineProps<{
  tlds: TldRow[]
  options: {
    registrars: { value: string; label: string }[]
    actions: { value: string; label: string }[]
    statuses: { value: string; label: string }[]
    currencies: { code: string; label: string; exponent: number }[]
  }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'extension', label: t('ui.tlds.extension') },
  { key: 'registrar', label: t('ui.tlds.registrar') },
  { key: 'terms', label: t('ui.tlds.terms') },
  { key: 'prices', label: t('ui.tlds.prices') },
  { key: 'domains', label: t('ui.tlds.domains'), numeric: true },
  { key: 'actions', label: '' },
]

const editing = ref<string | null>(null)

/**
 * One editable cell per (action, term, currency). A row that is absent from
 * the payload is a term the operator has stopped selling; a row with zero
 * is one they give away. The tick is what keeps the two apart.
 */
interface EditableCell {
  action: string
  years: number
  currencyCode: string
  exponent: number
  enabled: boolean
  amount: string
  cost: string
}

const form = useForm({
  extension: '',
  registrar: props.options.registrars[0]?.value ?? 'manual',
  min_years: 1,
  max_years: 10,
  allows_transfer: true,
  allows_whois_privacy: false,
  requires_epp_code: true,
  supports_idn: false,
  status: 'active',
  position: 0,
  grace_days: 30,
  redemption_days: 30,
  prices: [] as {
    action: string
    years: number
    currency_code: string
    amount_minor: number
    cost_minor: number | null
  }[],
})

const cells = ref<EditableCell[]>([])

const terms = computed(() => {
  const from = Number(form.min_years) || 1
  const to = Number(form.max_years) || 1
  const years: number[] = []

  for (let year = from; year <= to && years.length < 10; year += 1) {
    years.push(year)
  }

  return years
})

function buildCells(existing: PriceCell[]): EditableCell[] {
  const built: EditableCell[] = []

  for (const currency of props.options.currencies) {
    for (const action of props.options.actions) {
      for (const year of terms.value) {
        const match = existing.find(
          (cell) =>
            cell.action === action.value &&
            cell.years === year &&
            cell.currencyCode === currency.code,
        )

        built.push({
          action: action.value,
          years: year,
          currencyCode: currency.code,
          exponent: currency.exponent,
          enabled: match !== undefined,
          amount: match ? toDecimal(match.amountMinor, currency.exponent) : '',
          cost: match?.costMinor != null ? toDecimal(match.costMinor, currency.exponent) : '',
        })
      }
    }
  }

  return built
}

function startNew(): void {
  editing.value = 'new'
  form.reset()
  form.registrar = props.options.registrars[0]?.value ?? 'manual'
  cells.value = buildCells([])
}

function edit(tld: TldRow): void {
  editing.value = tld.id
  form.extension = tld.extension
  form.registrar = tld.registrar ?? 'manual'
  form.min_years = tld.minYears
  form.max_years = tld.maxYears
  form.allows_transfer = tld.allowsTransfer
  form.allows_whois_privacy = tld.allowsWhoisPrivacy
  form.requires_epp_code = tld.requiresEppCode
  form.supports_idn = tld.supportsIdn
  form.status = tld.status
  form.position = tld.position
  form.grace_days = tld.graceDays
  form.redemption_days = tld.redemptionDays
  cells.value = buildCells(tld.prices)
}

function cancel(): void {
  editing.value = null
  form.reset()
  cells.value = []
}

function save(): void {
  // A cell whose amount will not parse is dropped rather than sent as
  // zero: silently pricing something at nothing is the worst possible
  // reading of a typo.
  form.prices = cells.value
    .filter((cell) => cell.enabled && toMinor(cell.amount, cell.exponent) !== null)
    .map((cell) => ({
      action: cell.action,
      years: cell.years,
      currency_code: cell.currencyCode,
      amount_minor: toMinor(cell.amount, cell.exponent) ?? 0,
      cost_minor: cell.cost === '' ? null : toMinor(cell.cost, cell.exponent),
    }))

  if (editing.value === 'new') {
    form.post('/admin/catalog/tlds', { preserveScroll: true, onSuccess: cancel })
    return
  }

  form.put(`/admin/catalog/tlds/${editing.value}`, { preserveScroll: true, onSuccess: cancel })
}

/**
 * Removing an extension used to happen on the first click.
 *
 * It is only offered while no domain is registered under it, so what goes is
 * a price list and not somebody's name — level 2, and the sentence says so.
 */
const removing = ref<TldRow | null>(null)

function remove(): void {
  const tld = removing.value

  if (tld === null) return

  router.delete(`/admin/catalog/tlds/${tld.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}

/**
 * How many registration prices exist, not what they are.
 *
 * A summary that tried to show the cheapest would have to pick a currency,
 * and there is no rate in this product to choose one with.
 */
function priceSummary(tld: TldRow): string {
  const registrations = tld.prices.filter((price) => price.action === 'register')

  return registrations.length === 0 ? '—' : t('ui.tlds.cells', { count: registrations.length })
}

function cellsFor(currency: string, action: string): EditableCell[] {
  return cells.value.filter((cell) => cell.currencyCode === currency && cell.action === action)
}
</script>

<template>
  <Head :title="t('ui.tlds.title')" />

  <AdminLayout :heading="t('ui.tlds.title')">
    <template #header>
      <PageHeader :title="t('ui.tlds.title')" :description="t('ui.tlds.intro')">
        <template v-if="can.manage" #actions>
          <AppButton variant="primary" icon="add" @click="startNew">
            {{ t('ui.tlds.add') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <AppTable v-if="tlds.length > 0" name="tlds" :columns="COLUMNS">
        <AppTableRow v-for="tld in tlds" :key="tld.id">
          <td data-col="extension" class="font-medium">.{{ tld.extension }}</td>
          <td data-col="registrar" class="text-content-muted">{{ tld.registrar ?? '—' }}</td>
          <td data-col="terms">
            {{ t('ui.tlds.years_range', { min: tld.minYears, max: tld.maxYears }) }}
          </td>
          <td data-col="prices" class="text-content-muted">{{ priceSummary(tld) }}</td>
          <td data-col="domains" class="numeric tabular-nums">{{ tld.domains }}</td>
          <td data-col="actions" class="text-right">
            <span v-if="can.manage" class="row-actions inline-flex gap-1">
              <AppButton size="sm" variant="ghost" @click="edit(tld)">
                {{ t('ui.tlds.edit') }}
              </AppButton>
              <AppButton
                v-if="tld.domains === 0"
                size="sm"
                variant="danger-subtle"
                @click="removing = tld"
              >
                {{ t('ui.tlds.delete') }}
              </AppButton>
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <EmptyState
        v-else
        icon="domains"
        :title="t('ui.tlds.empty')"
        :description="t('ui.tlds.empty_detail')"
      />

      <DetailSection
        v-if="editing"
        :title="editing === 'new' ? t('ui.tlds.add') : t('ui.tlds.edit_extension')"
        :description="t('ui.tlds.form_intro')"
      >
        <div class="grid gap-4 sm:grid-cols-3">
          <AppInput
            v-model="form.extension"
            :label="t('ui.tlds.extension')"
            :hint="t('ui.tlds.extension_hint')"
            :error="form.errors.extension"
          />
          <AppSelect
            v-model="form.registrar"
            :label="t('ui.tlds.registrar')"
            :options="options.registrars"
            :error="form.errors.registrar"
          />
          <AppSelect
            v-model="form.status"
            :label="t('ui.tlds.status')"
            :options="options.statuses"
            :error="form.errors.status"
          />
          <AppInput
            v-model="form.min_years"
            type="number"
            :label="t('ui.tlds.min_years')"
            :hint="t('ui.tlds.min_years_hint')"
            :error="form.errors.min_years"
          />
          <AppInput
            v-model="form.max_years"
            type="number"
            :label="t('ui.tlds.max_years')"
            :error="form.errors.max_years"
          />
          <AppInput
            v-model="form.position"
            type="number"
            :label="t('ui.tlds.position')"
            :error="form.errors.position"
          />
          <AppInput
            v-model="form.grace_days"
            type="number"
            :label="t('ui.tlds.grace_days')"
            :error="form.errors.grace_days"
          />
          <AppInput
            v-model="form.redemption_days"
            type="number"
            :label="t('ui.tlds.redemption_days')"
            :error="form.errors.redemption_days"
          />
        </div>

        <div class="mt-4 flex flex-wrap gap-5">
          <AppCheckbox v-model="form.allows_transfer" :label="t('ui.tlds.transfers')" />
          <AppCheckbox v-model="form.allows_whois_privacy" :label="t('ui.tlds.whois')" />
          <AppCheckbox v-model="form.requires_epp_code" :label="t('ui.tlds.epp')" />
          <AppCheckbox v-model="form.supports_idn" :label="t('ui.tlds.idn')" />
        </div>

        <div v-for="currency in options.currencies" :key="currency.code" class="mt-8">
          <h3 class="text-content-subtle text-label uppercase">{{ currency.code }}</h3>
          <p class="text-content-muted text-chrome mt-1 leading-relaxed">
            {{ t('ui.tlds.cells_hint') }}
          </p>

          <div v-for="action in options.actions" :key="action.value" class="mt-4">
            <p class="text-content-muted text-chrome mb-2 font-medium">{{ action.label }}</p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <div
                v-for="cell in cellsFor(currency.code, action.value)"
                :key="`${cell.action}-${cell.years}-${cell.currencyCode}`"
                class="border-line rounded-md border p-3"
              >
                <AppCheckbox
                  v-model="cell.enabled"
                  :label="t('ui.tlds.years', { count: cell.years })"
                />

                <div v-if="cell.enabled" class="mt-2 grid gap-2">
                  <AppInput v-model="cell.amount" :label="t('ui.tlds.price')" />
                  <AppInput
                    v-model="cell.cost"
                    :label="t('ui.tlds.cost')"
                    :hint="t('ui.tlds.optional')"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-6 flex gap-2">
          <AppButton variant="primary" :loading="form.processing" @click="save">
            {{ t('ui.tlds.save') }}
          </AppButton>
          <AppButton variant="ghost" @click="cancel">{{ t('ui.confirm.cancel') }}</AppButton>
        </div>
      </DetailSection>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('ui.tlds.delete_title', { extension: removing?.extension ?? '' })"
      :description="t('ui.tlds.delete_detail')"
      :confirm-label="t('ui.tlds.delete_confirm')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
