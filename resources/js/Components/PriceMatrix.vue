<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'

import MoneyInput from './MoneyInput.vue'
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
      <h2 class="text-base font-semibold tracking-tight">{{ title }}</h2>
      <p v-if="description" class="text-content-muted mt-1 max-w-[60ch] text-sm leading-relaxed">
        {{ description }}
      </p>
    </div>

    <p v-if="currencies.length === 0" class="text-content-muted text-sm">
      No active currencies yet. Add one before pricing anything.
    </p>

    <template v-else>
      <div
        class="border-line bg-surface-secondary inline-flex w-fit max-w-full gap-0.5 overflow-x-auto rounded-[var(--radius-sm)] border p-0.5"
        role="tablist"
        aria-label="Currency"
      >
        <button
          v-for="currency in currencies"
          :key="currency.code"
          type="button"
          role="tab"
          :aria-selected="currency.code === active"
          class="pressable text-body rounded-[calc(var(--radius-sm)-2px)] px-3 py-1.5 font-medium whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
          :class="
            currency.code === active
              ? 'bg-surface-primary text-content shadow-(--shadow-raised)'
              : 'text-content-muted hover:text-content'
          "
          @click="active = currency.code"
        >
          {{ currency.code }}
          <span class="text-content-subtle text-label ml-1 tabular-nums">
            {{ soldCount(currency.code) }}
          </span>
        </button>
      </div>

      <div class="border-line overflow-x-auto rounded-[var(--radius-lg)] border">
        <table class="w-full text-left text-sm">
          <thead class="bg-surface-secondary text-content-muted">
            <tr>
              <th scope="col" class="px-4 py-2.5 text-xs font-medium">Billing cycle</th>
              <th scope="col" class="w-40 px-4 py-2.5 text-xs font-medium">Recurring</th>
              <th scope="col" class="w-40 px-4 py-2.5 text-xs font-medium">Setup fee</th>
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
                  :aria-label="`${cycle.label} recurring price`"
                />
              </td>
              <td class="px-4 py-2.5">
                <MoneyInput
                  v-model="cell(cycle.value).setupMinor"
                  :exponent="activeCurrency?.exponent ?? 2"
                  :symbol="activeCurrency?.symbol ?? activeCurrency?.code"
                  :disabled="!cell(cycle.value).enabled"
                  :allow-negative="allowNegative"
                  :aria-label="`${cycle.label} setup fee`"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="text-content-subtle text-xs">
        Unticked cycles are not sold in {{ active }}. Zero means free.
      </p>
    </template>
  </section>
</template>
