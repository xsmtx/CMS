<script setup lang="ts">
/**
 * Tax, as rows an operator maintains.
 *
 * The screen is three panels in the order somebody configuring a country works:
 * state the rules, say how tax behaves, then **try it on an amount**. The third
 * one is not a nicety — this platform ships no rates and knows no jurisdiction
 * (ADR 0045), so the only way an operator can be sure their rules mean what they
 * intended is to watch the real calculator apply them before a customer does.
 *
 * The rate is typed as a percentage, because that is what a tax authority
 * publishes and what an accountant says out loud. It becomes an integer number of
 * parts per million once, on the server, and nothing downstream ever holds it as
 * a float.
 *
 * Nothing here knows a country either. There is no list of jurisdictions in this
 * file, and adding one would be the mistake the whole design exists to avoid.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface Option {
  value: string
  label: string
}

interface RuleRow {
  id: string
  name: string
  rate: string
  countryCode: string | null
  regionCode: string | null
  postcodePattern: string | null
  level: number
  compound: boolean
  appliesTo: string
  customerKind: string
  exemptsValidatedBusiness: boolean
  exemptionNote: string | null
  priority: number
  startsOn: string | null
  endsOn: string | null
  isActive: boolean
  notes: string | null
}

interface Preview {
  net: string
  tax: string
  gross: string
  exemption: string | null
  components: { name: string; rate: string; amount: string; jurisdiction: string | null }[]
}

const props = defineProps<{
  rules: RuleRow[]
  settings: {
    pricesIncludeTax: boolean
    rounding: string
    taxIdLabel: string | null
    requireTaxIdForBusiness: boolean
    exemptionNote: string | null
  }
  options: {
    appliesTo: Option[]
    customerKinds: Option[]
    rounding: Option[]
    currencies: string[]
  }
  preview?: Preview | null
}>()

const { t } = useTranslations()

const editing = ref<string | null>(null)

const form = useForm({
  name: '',
  rate: '',
  country_code: '',
  region_code: '',
  postcode_pattern: '',
  level: 1,
  compound: false,
  applies_to: 'all',
  customer_kind: 'all',
  exempts_validated_business: false,
  exemption_note: '',
  priority: 0,
  starts_on: '',
  ends_on: '',
  is_active: true,
  notes: '',
})

function edit(rule: RuleRow): void {
  editing.value = rule.id
  form.name = rule.name
  form.rate = rule.rate
  form.country_code = rule.countryCode ?? ''
  form.region_code = rule.regionCode ?? ''
  form.postcode_pattern = rule.postcodePattern ?? ''
  form.level = rule.level
  form.compound = rule.compound
  form.applies_to = rule.appliesTo
  form.customer_kind = rule.customerKind
  form.exempts_validated_business = rule.exemptsValidatedBusiness
  form.exemption_note = rule.exemptionNote ?? ''
  form.priority = rule.priority
  form.starts_on = rule.startsOn ?? ''
  form.ends_on = rule.endsOn ?? ''
  form.is_active = rule.isActive
  form.notes = rule.notes ?? ''
  form.clearErrors()
}

function reset(): void {
  editing.value = null
  form.reset()
  form.clearErrors()
}

function save(): void {
  if (editing.value === null) {
    form.post('/admin/tax/rules', { preserveScroll: true, onSuccess: reset })
    return
  }

  form.put(`/admin/tax/rules/${editing.value}`, { preserveScroll: true, onSuccess: reset })
}

function remove(rule: RuleRow): void {
  router.delete(`/admin/tax/rules/${rule.id}`, { preserveScroll: true })
}

const settingsForm = useForm({
  prices_include_tax: props.settings.pricesIncludeTax,
  rounding: props.settings.rounding,
  tax_id_label: props.settings.taxIdLabel ?? '',
  require_tax_id_for_business: props.settings.requireTaxIdForBusiness,
  exemption_note: props.settings.exemptionNote ?? '',
})

function saveSettings(): void {
  settingsForm.put('/admin/tax/settings', { preserveScroll: true })
}

const trial = ref({
  amount: '10000',
  currency: props.options.currencies[0] ?? 'EUR',
  country_code: '',
  region_code: '',
  postcode: '',
  has_tax_id: false,
  is_business: false,
  applies_to: 'all',
})

const working = ref(false)

/**
 * The preview is a named prop on this same route, so working it out costs one
 * partial reload rather than an endpoint of its own — and an ordinary page load
 * computes nothing.
 */
function tryIt(): void {
  working.value = true

  router.reload({
    only: ['preview'],
    data: {
      ...trial.value,
      is_business: trial.value.is_business ? 1 : 0,
      has_tax_id: trial.value.has_tax_id ? 1 : 0,
    },
    onFinish: () => (working.value = false),
  })
}

const preview = computed<Preview | null>(() => props.preview ?? null)

const columns: TableColumn[] = [
  { key: 'name', label: t('tax.rules.columns.name') },
  { key: 'rate', label: t('tax.rules.columns.rate'), numeric: true },
  { key: 'place', label: t('tax.rules.columns.place') },
  { key: 'level', label: t('tax.rules.columns.level') },
  { key: 'applies', label: t('tax.rules.columns.applies_to'), optional: true },
  { key: 'customer', label: t('tax.rules.columns.customer'), optional: true },
  { key: 'dates', label: t('tax.rules.columns.dates'), optional: true },
  { key: 'state', label: t('tax.rules.columns.state') },
  { key: 'actions', label: '' },
]

function place(rule: RuleRow): string {
  const parts = [rule.countryCode, rule.regionCode, rule.postcodePattern].filter(
    (part): part is string => part !== null && part !== '',
  )

  return parts.length === 0 ? t('tax.rules.anywhere') : parts.join(' · ')
}

function dates(rule: RuleRow): string {
  if (rule.startsOn === null && rule.endsOn === null) return '—'

  return `${rule.startsOn ?? '…'} → ${rule.endsOn ?? '…'}`
}

function labelOf(options: Option[], value: string): string {
  return options.find((option) => option.value === value)?.label ?? value
}
</script>

<template>
  <Head :title="t('tax.title')" />

  <AdminLayout :heading="t('tax.title')" :description="t('tax.intro')">
    <div class="flex flex-col gap-6">
      <!-- The rules. Nothing is charged until one of these exists, which the
           empty state says rather than leaving somebody to wonder. -->
      <section class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <h2 class="text-title font-semibold tracking-tight">{{ t('tax.rules.title') }}</h2>
          <p class="text-content-muted max-w-[75ch] text-sm leading-relaxed">
            {{ t('tax.rules.intro') }}
          </p>
        </div>

        <EmptyState
          v-if="rules.length === 0"
          :title="t('tax.rules.title')"
          :description="t('tax.rules.empty')"
          icon="invoice"
        />

        <AppTable v-else name="tax-rules" :columns="columns">
          <AppTableRow v-for="rule in rules" :key="rule.id">
            <td data-col="name" class="px-4 py-2.5 font-medium">{{ rule.name }}</td>
            <td data-col="rate" class="numeric px-4 py-2.5 tabular-nums">{{ rule.rate }}%</td>
            <td data-col="place" class="px-4 py-2.5">{{ place(rule) }}</td>
            <td data-col="level" class="px-4 py-2.5">
              {{ rule.level }}
              <AppBadge v-if="rule.compound" tone="info">+</AppBadge>
            </td>
            <td data-col="applies" class="text-content-muted px-4 py-2.5 text-xs">
              {{ labelOf(options.appliesTo, rule.appliesTo) }}
            </td>
            <td data-col="customer" class="text-content-muted px-4 py-2.5 text-xs">
              {{ labelOf(options.customerKinds, rule.customerKind) }}
            </td>
            <td data-col="dates" class="text-content-muted px-4 py-2.5 text-xs tabular-nums">
              {{ dates(rule) }}
            </td>
            <td data-col="state" class="px-4 py-2.5">
              <AppBadge :tone="rule.isActive ? 'success' : 'unknown'">
                {{ rule.isActive ? t('tax.rules.fields.is_active') : '—' }}
              </AppBadge>
            </td>
            <td data-col="actions" class="px-4 py-2.5">
              <div class="flex items-center gap-1.5">
                <AppButton size="sm" variant="ghost" @click="edit(rule)">
                  {{ t('tax.rules.edit') }}
                </AppButton>
                <AppButton size="sm" variant="ghost" @click="remove(rule)">×</AppButton>
              </div>
            </td>
          </AppTableRow>
        </AppTable>

        <AppCard>
          <form class="flex flex-col gap-4" @submit.prevent="save">
            <h3 class="text-body font-semibold">
              {{ editing === null ? t('tax.rules.add') : t('tax.rules.edit') }}
            </h3>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <AppInput
                v-model="form.name"
                :label="t('tax.rules.fields.name')"
                :hint="t('tax.rules.fields.name_hint')"
                :error="form.errors.name"
                required
              />
              <AppInput
                v-model="form.rate"
                :label="t('tax.rules.fields.rate')"
                :hint="t('tax.rules.fields.rate_hint')"
                :error="form.errors.rate"
                required
              />
              <AppInput
                v-model="form.country_code"
                :label="t('tax.rules.fields.country')"
                :hint="t('tax.rules.fields.country_hint')"
                :error="form.errors.country_code"
              />
              <AppInput
                v-model="form.region_code"
                :label="t('tax.rules.fields.region')"
                :hint="t('tax.rules.fields.region_hint')"
                :error="form.errors.region_code"
              />
              <AppInput
                v-model="form.postcode_pattern"
                :label="t('tax.rules.fields.postcode')"
                :hint="t('tax.rules.fields.postcode_hint')"
                :error="form.errors.postcode_pattern"
              />
              <AppInput
                v-model="form.level"
                type="number"
                :label="t('tax.rules.fields.level')"
                :hint="t('tax.rules.fields.level_hint')"
                :error="form.errors.level"
              />
              <AppSelect
                v-model="form.applies_to"
                :label="t('tax.rules.fields.applies_to')"
                :options="options.appliesTo"
                :error="form.errors.applies_to"
              />
              <AppSelect
                v-model="form.customer_kind"
                :label="t('tax.rules.fields.customer_kind')"
                :options="options.customerKinds"
                :error="form.errors.customer_kind"
              />
              <AppInput
                v-model="form.starts_on"
                type="date"
                :label="t('tax.rules.fields.starts_on')"
                :error="form.errors.starts_on"
              />
              <AppInput
                v-model="form.ends_on"
                type="date"
                :label="t('tax.rules.fields.ends_on')"
                :hint="t('tax.rules.fields.dates_hint')"
                :error="form.errors.ends_on"
              />
              <AppInput
                v-model="form.priority"
                type="number"
                :label="t('tax.rules.fields.priority')"
                :hint="t('tax.rules.fields.priority_hint')"
                :error="form.errors.priority"
              />
              <AppInput
                v-model="form.exemption_note"
                :label="t('tax.rules.fields.exemption_note')"
                :hint="t('tax.rules.fields.exemption_note_hint')"
                :error="form.errors.exemption_note"
              />
            </div>

            <div class="flex flex-col gap-2">
              <AppCheckbox v-model="form.compound" :label="t('tax.rules.fields.compound')" />
              <AppCheckbox
                v-model="form.exempts_validated_business"
                :label="t('tax.rules.fields.exempts')"
              />
              <AppCheckbox v-model="form.is_active" :label="t('tax.rules.fields.is_active')" />
            </div>

            <AppTextarea
              v-model="form.notes"
              :label="t('tax.rules.fields.notes')"
              :rows="2"
              :error="form.errors.notes"
            />

            <div class="flex items-center gap-2">
              <AppButton type="submit" variant="primary" :loading="form.processing">
                {{ editing === null ? t('tax.rules.add') : t('tax.rules.edit') }}
              </AppButton>
              <AppButton v-if="editing !== null" variant="ghost" @click="reset">×</AppButton>
            </div>
          </form>
        </AppCard>
      </section>

      <!-- How tax behaves. Two of these change an amount and one changes what an
           amount means, which is why they are stated rather than inferred. -->
      <section class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <h2 class="text-title font-semibold tracking-tight">{{ t('tax.settings.title') }}</h2>
          <p class="text-content-muted max-w-[75ch] text-sm leading-relaxed">
            {{ t('tax.settings.intro') }}
          </p>
        </div>

        <AppCard>
          <form class="flex flex-col gap-4" @submit.prevent="saveSettings">
            <div class="grid gap-4 sm:grid-cols-2">
              <AppSelect
                v-model="settingsForm.rounding"
                :label="t('tax.settings.rounding')"
                :hint="t('tax.settings.rounding_hint')"
                :options="options.rounding"
                :error="settingsForm.errors.rounding"
              />
              <AppInput
                v-model="settingsForm.tax_id_label"
                :label="t('tax.settings.tax_id_label')"
                :hint="t('tax.settings.tax_id_label_hint')"
                :error="settingsForm.errors.tax_id_label"
              />
              <AppInput
                v-model="settingsForm.exemption_note"
                :label="t('tax.settings.exemption_note')"
                :error="settingsForm.errors.exemption_note"
              />
            </div>

            <div class="flex flex-col gap-2">
              <AppCheckbox
                v-model="settingsForm.prices_include_tax"
                :label="t('tax.settings.prices_include_tax')"
                :description="t('tax.settings.prices_include_tax_hint')"
              />
              <AppCheckbox
                v-model="settingsForm.require_tax_id_for_business"
                :label="t('tax.settings.require_tax_id_for_business')"
              />
            </div>

            <div>
              <AppButton type="submit" variant="primary" :loading="settingsForm.processing">
                {{ t('tax.settings.save') }}
              </AppButton>
            </div>
          </form>
        </AppCard>
      </section>

      <!-- Try it. The panel that earns the screen: an operator configuring a
           country this platform knows nothing about sees what their own rules do
           before a customer does. -->
      <section class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <h2 class="text-title font-semibold tracking-tight">{{ t('tax.preview.title') }}</h2>
          <p class="text-content-muted max-w-[75ch] text-sm leading-relaxed">
            {{ t('tax.preview.intro') }}
          </p>
        </div>

        <AppCard>
          <form class="flex flex-col gap-4" @submit.prevent="tryIt">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <AppInput
                v-model="trial.amount"
                :label="t('tax.preview.amount')"
                :hint="t('tax.preview.amount_hint')"
              />
              <AppSelect
                v-model="trial.currency"
                :label="t('tax.preview.currency')"
                :options="options.currencies.map((code) => ({ value: code, label: code }))"
              />
              <AppInput v-model="trial.country_code" :label="t('tax.preview.country')" />
              <AppInput v-model="trial.region_code" :label="t('tax.preview.region')" />
              <AppInput v-model="trial.postcode" :label="t('tax.preview.postcode')" />

              <AppSelect
                v-model="trial.applies_to"
                :label="t('tax.preview.applies_to')"
                :options="options.appliesTo"
              />
            </div>

            <div class="flex flex-col gap-2">
              <AppCheckbox v-model="trial.is_business" :label="t('tax.preview.is_business')" />
              <!--
                Whether there is a tax id, never the id itself. This panel is a
                GET, so anything it sends is in the address bar, the history and
                the access log; and the calculator only ever asks whether one was
                given, because validating an id means calling a country's own
                service and core makes no such call.
              -->
              <AppCheckbox
                v-model="trial.has_tax_id"
                :label="t('tax.preview.has_tax_id')"
                :description="t('tax.preview.has_tax_id_hint')"
              />
            </div>

            <div>
              <AppButton type="submit" variant="secondary" :loading="working">
                {{ t('tax.preview.run') }}
              </AppButton>
            </div>

            <div
              v-if="preview"
              class="border-line flex flex-col gap-2 rounded-[var(--radius-md)] border p-4"
            >
              <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
                <dt class="text-content-muted text-body">{{ t('tax.preview.net') }}</dt>
                <dd class="text-body text-right tabular-nums">{{ preview.net }}</dd>

                <template v-for="component in preview.components" :key="component.name">
                  <dt class="text-content-muted text-body">
                    {{ component.name }} {{ component.rate }}%
                    <span v-if="component.jurisdiction" class="text-content-subtle text-xs">
                      {{ component.jurisdiction }}
                    </span>
                  </dt>
                  <dd class="text-body text-right tabular-nums">{{ component.amount }}</dd>
                </template>

                <dt class="text-body font-semibold">{{ t('tax.preview.gross') }}</dt>
                <dd class="text-body text-right font-semibold tabular-nums">{{ preview.gross }}</dd>
              </dl>

              <AppAlert v-if="preview.exemption" tone="info">
                {{ t('tax.preview.exempt', { reason: preview.exemption }) }}
              </AppAlert>

              <p v-else-if="preview.components.length === 0" class="text-content-muted text-sm">
                {{ t('tax.preview.nothing') }}
              </p>
            </div>
          </form>
        </AppCard>
      </section>
    </div>
  </AdminLayout>
</template>
