<script setup lang="ts">
import { useTheme } from '../composables/useTheme'

/**
 * Three states in a strip, not a toggle.
 *
 * A two-state toggle cannot say "follow the system", which is what most
 * people actually want — and the only way to get back to it once they have
 * clicked once would be to clear site data.
 */
const { choice, set, options } = useTheme()
</script>

<template>
  <div
    class="border-line bg-surface-secondary inline-flex gap-0.5 rounded-sm border p-0.5"
    role="radiogroup"
    aria-label="Colour theme"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="choice === option.value"
      class="pressable text-label rounded-[calc(var(--radius-sm)-2px)] px-2 py-1 font-medium transition-colors duration-(--duration-fast) ease-(--ease-out)"
      :class="
        choice === option.value
          ? 'bg-surface-primary text-content shadow-(--shadow-raised)'
          : 'text-content-muted hover:text-content'
      "
      @click="set(option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>
