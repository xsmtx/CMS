<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'

import AppSegmented from './AppSegmented.vue'
import MoneyInput from './MoneyInput.vue'
import { useTranslations } from '../composables/useTranslations'
import { cellKey, type CurrencyOption, type CycleOption, type PriceCell } from '../types/catalog'

/**
 * The price grid: one row per billing cycle, one tab per currency.
 *
 * A cell that is switched off is deleted rather than zeroed, because zero
 * means free and absent means not sold. The distinction is the whole reason
 * this control has a toggle instead of just two number fields.
 *
 * Nothing is converted between currencies here. Each tab is an independent
 * set of prices an operator typed, which is why a plan can be 9.99 EUR and
 * 12.99 USD without either being wrong.
 */
const props = withDefaults(
  defineProps<{
    cycles: CycleOption[]
    currencies: CurrencyOption[]
    allowNegative?: boolean
    title?: string
    description?: string
  }>(),
  { allowNegative: false, title: undefined, description: undefined },
)

const { t } = useTranslations()

const model = defineModel<PriceCell[]>({ required: true })

interface Cell {
  enabled: boolean
  recurringMinor: number
  setupMinor: number
}

const cells = reactive(new Map<string, Cell>())

for (const currency of props.currencies) {
  for (const cycle of props.cycles) {
    const existing = model.value.find(
      (price) => price.billingCycle === cycle.value && price.currencyCode === currency.code,
    )

    cells.set(cellKey(cycle.value, currency.code), {
      enabled: existing !== undefined,
      recurringMinor: existing?.recurringMinor ?? 0,
      setupMinor: existing?.setupMinor ?? 0,
    })
  }
}

const active = ref(
  props.currencies.find((currency) => currency.isBase)?.code ?? props.currencies[0]?.code ?? '',
)

const activeCurrency = computed(() =>
  props.currencies.find((currency) => currency.code === active.value),
)

function cell(cycle: string): Cell {
  return (
    cells.get(cellKey(cycle, active.value)) ?? { enabled: false, recurringMinor: 0, setupMinor: 0 }
  )
}

/** How many cycles are priced in a currency, shown on its tab. */
function soldCount(code: string): number {
  return props.cycles.filter((cycle) => cells.get(cellKey(cycle.value, code))?.enabled).length
}

watch(
  cells,
  () => {
    const next: PriceCell[] = []

    for (const currency of props.currencies) {
      for (const cycle of props.cycles) {
        const current = cells.get(cellKey(cycle.value, currency.code))
        if (!current?.enabled) continue

        next.push({
          billingCycle: cycle.value,
          currencyCode: currency.code,
          recurringMinor: current.recurringMinor,
          setupMinor: current.setupMinor,
        })
      }
    }

    model.value = next
  },
  { deep: true },
)
</script>

<template>
  <section class="flex flex-col gap-4">
    <div v-if="title">
      <h2 class="text-title font-semibold">{{ title }}</h2>
      <p v-if="description" class="text-content-muted text-body mt-1 max-w-[60ch] leading-relaxed">
        {{ description }}
      </p>
    </div>

    <p v-if="currencies.length === 0" class="text-content-muted text-body">
      {{ t('catalog.pricing.no_currencies') }}
    </p>

    <template v-else>
      <AppSegmented
        v-model="active"
        :segments="
          currencies.map((c) => ({ value: c.code, label: c.code, count: soldCount(c.code) }))
        "
        :label="t('catalog.pricing.currency')"
      />

      <div class="border-line overflow-x-auto rounded-lg border">
        <table class="text-body w-full text-left">
          <thead class="bg-surface-secondary text-content-muted">
            <tr>
              <th scope="col" class="text-label px-4 py-2.5 uppercase">
                {{ t('catalog.pricing.billing_cycle') }}
              </th>
              <th scope="col" class="text-label w-40 px-4 py-2.5 uppercase">
                {{ t('catalog.pricing.recurring') }}
              </th>
              <th scope="col" class="text-label w-40 px-4 py-2.5 uppercase">
                {{ t('catalog.pricing.setup') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-line bg-surface-primary divide-y">
            <tr v-for="cycle in cycles" :key="cycle.value">
              <td class="px-4 py-2.5">
                <label class="flex items-center gap-3">
                  <input
                    v-model="cell(cycle.value).enabled"
                    type="checkbox"
                    class="border-line-strong accent-brand size-4 shrink-0 rounded-[4px] border"
                  />
                  <span :class="cell(cycle.value).enabled ? '' : 'text-content-subtle'">
                    {{ cycle.label }}
                  </span>
                </label>
              </td>
              <td class="px-4 py-2.5">
                <MoneyInput
                  v-model="cell(cycle.value).recurringMinor"
                  :exponent="activeCurrency?.exponent ?? 2"
                  :symbol="activeCurrency?.symbol ?? activeCurrency?.code"
                  :disabled="!cell(cycle.value).enabled"
                  :allow-negative="allowNegative"
                  :aria-label="t('catalog.pricing.recurring_aria', { cycle: cycle.label })"
                />
              </td>
              <td class="px-4 py-2.5">
                <MoneyInput
                  v-model="cell(cycle.value).setupMinor"
                  :exponent="activeCurrency?.exponent ?? 2"
                  :symbol="activeCurrency?.symbol ?? activeCurrency?.code"
                  :disabled="!cell(cycle.value).enabled"
                  :allow-negative="allowNegative"
                  :aria-label="t('catalog.pricing.setup_aria', { cycle: cycle.label })"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="text-content-subtle text-chrome">
        {{ t('catalog.pricing.not_sold_in', { currency: active }) }}
      </p>
    </template>
  </section>
</template>
