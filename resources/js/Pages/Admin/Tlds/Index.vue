<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
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

function remove(tld: TldRow): void {
  router.delete(`/admin/catalog/tlds/${tld.id}`, { preserveScroll: true })
}

function priceSummary(tld: TldRow): string {
  const registrations = tld.prices.filter((price) => price.action === 'register')

  return registrations.length === 0 ? '—' : `${registrations.length} cells`
}

function cellsFor(currency: string, action: string): EditableCell[] {
  return cells.value.filter((cell) => cell.currencyCode === currency && cell.action === action)
}
</script>

<template>
  <Head title="TLD pricing" />

  <AdminLayout
    heading="TLD pricing"
    description="What each extension costs, per term and currency. A missing price means it is not sold."
  >
    <AppCard>
      <template #actions>
        <AppButton v-if="can.manage" size="sm" @click="startNew">Add an extension</AppButton>
      </template>

      <AppTable
        v-if="tlds.length > 0"
        :headers="['Extension', 'Registrar', 'Terms', 'Prices', 'Domains', '']"
      >
        <tr v-for="tld in tlds" :key="tld.id">
          <td class="px-5 py-3.5 font-medium">.{{ tld.extension }}</td>
          <td class="text-content-muted px-5 py-3.5">{{ tld.registrar ?? '—' }}</td>
          <td class="px-5 py-3.5">{{ tld.minYears }}–{{ tld.maxYears }} years</td>
          <td class="text-content-muted px-5 py-3.5">{{ priceSummary(tld) }}</td>
          <td class="px-5 py-3.5 tabular-nums">{{ tld.domains }}</td>
          <td class="px-5 py-3.5 text-right">
            <div v-if="can.manage" class="flex justify-end gap-2">
              <AppButton size="sm" variant="ghost" @click="edit(tld)">Edit</AppButton>
              <AppButton v-if="tld.domains === 0" size="sm" variant="ghost" @click="remove(tld)">
                Delete
              </AppButton>
            </div>
          </td>
        </tr>
      </AppTable>

      <EmptyState
        v-else
        title="No extensions yet"
        description="Add the extensions you sell and what each costs to register, renew and transfer."
      />
    </AppCard>

    <AppCard
      v-if="editing"
      class="mt-6"
      :title="editing === 'new' ? 'Add an extension' : 'Edit extension'"
    >
      <div class="grid gap-4 sm:grid-cols-3">
        <AppInput
          v-model="form.extension"
          label="Extension"
          hint="Without the dot: com, co.uk"
          :error="form.errors.extension"
        />
        <AppSelect
          v-model="form.registrar"
          label="Registrar"
          :options="options.registrars"
          :error="form.errors.registrar"
        />
        <AppSelect
          v-model="form.status"
          label="Status"
          :options="options.statuses"
          :error="form.errors.status"
        />
        <AppInput
          v-model="form.min_years"
          type="number"
          label="Minimum years"
          hint="The registry's rule, not a preference."
          :error="form.errors.min_years"
        />
        <AppInput
          v-model="form.max_years"
          type="number"
          label="Maximum years"
          :error="form.errors.max_years"
        />
        <AppInput
          v-model="form.position"
          type="number"
          label="Position"
          :error="form.errors.position"
        />
        <AppInput
          v-model="form.grace_days"
          type="number"
          label="Grace days"
          :error="form.errors.grace_days"
        />
        <AppInput
          v-model="form.redemption_days"
          type="number"
          label="Redemption days"
          :error="form.errors.redemption_days"
        />
      </div>

      <div class="mt-4 flex flex-wrap gap-5">
        <AppCheckbox v-model="form.allows_transfer" label="Transfers offered" />
        <AppCheckbox v-model="form.allows_whois_privacy" label="WHOIS privacy offered" />
        <AppCheckbox v-model="form.requires_epp_code" label="Needs an EPP code" />
        <AppCheckbox v-model="form.supports_idn" label="Supports IDN" />
      </div>

      <div v-for="currency in options.currencies" :key="currency.code" class="mt-8">
        <h3 class="text-sm font-semibold">{{ currency.code }}</h3>
        <p class="text-content-muted mt-1 text-xs leading-relaxed">
          Leave a cell unticked and that term is not sold in this currency. Zero means free.
        </p>

        <div v-for="action in options.actions" :key="action.value" class="mt-4">
          <p class="text-content-muted mb-2 text-xs font-medium">{{ action.label }}</p>

          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="cell in cellsFor(currency.code, action.value)"
              :key="`${cell.action}-${cell.years}-${cell.currencyCode}`"
              class="border-line rounded-[var(--radius-sm)] border p-3"
            >
              <AppCheckbox v-model="cell.enabled" :label="`${cell.years} year(s)`" />

              <div v-if="cell.enabled" class="mt-2 grid gap-2">
                <AppInput v-model="cell.amount" label="Price" />
                <AppInput v-model="cell.cost" label="Registrar cost" hint="Optional." />
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-6 flex gap-2">
        <AppButton variant="primary" :loading="form.processing" @click="save">Save</AppButton>
        <AppButton variant="ghost" @click="cancel">Cancel</AppButton>
      </div>
    </AppCard>
  </AdminLayout>
</template>
