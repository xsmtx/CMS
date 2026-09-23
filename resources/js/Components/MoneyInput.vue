<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { toDecimal, toMinor } from '../types/catalog'

/**
 * An amount, held as minor units and shown as a decimal.
 *
 * The model is an integer count of minor units all the way through. The
 * decimal only ever exists as the text in this field, which is why a yen
 * price shows no decimal point and a dinar shows three.
 */
const props = withDefaults(
  defineProps<{
    label?: string
    exponent: number
    symbol?: string | null
    disabled?: boolean
    allowNegative?: boolean
    ariaLabel?: string
  }>(),
  {
    label: undefined,
    symbol: null,
    disabled: false,
    allowNegative: false,
    ariaLabel: undefined,
  },
)

const model = defineModel<number>({ required: true })

const text = ref(toDecimal(model.value, props.exponent))

// Re-render when the value changes from elsewhere (a currency switch, a
// reset), but never while the operator is mid-edit in this field.
const focused = ref(false)

watch(
  () => [model.value, props.exponent] as const,
  ([value, exponent]) => {
    if (!focused.value) text.value = toDecimal(value, exponent)
  },
)

const placeholder = computed(() => toDecimal(0, props.exponent))

function onInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value
  text.value = raw

  const minor = toMinor(raw, props.exponent)
  if (minor === null) return

  model.value = props.allowNegative ? minor : Math.max(minor, 0)
}

function onBlur(): void {
  focused.value = false
  // Snapping back to the canonical form is how the operator sees that
  // "19.999" was stored as 19.99 rather than silently changed.
  text.value = toDecimal(model.value, props.exponent)
}
</script>

<template>
  <label class="flex flex-col gap-1.5">
    <span v-if="label" class="text-content-muted text-xs font-medium">{{ label }}</span>

    <span class="relative flex items-center">
      <span
        v-if="symbol"
        class="text-content-subtle pointer-events-none absolute left-3 text-xs"
        aria-hidden="true"
      >
        {{ symbol }}
      </span>

      <input
        :value="text"
        type="text"
        inputmode="decimal"
        :disabled="disabled"
        :placeholder="placeholder"
        :aria-label="ariaLabel"
        class="border-line bg-surface-primary text-content placeholder:text-content-subtle focus:border-brand w-full rounded-[var(--radius-sm)] border py-2 text-right text-sm tabular-nums transition-colors duration-(--duration-fast) ease-(--ease-out) disabled:opacity-60"
        :class="symbol ? 'pr-3 pl-9' : 'px-3'"
        @focus="focused = true"
        @input="onInput"
        @blur="onBlur"
      />
    </span>
  </label>
</template>
