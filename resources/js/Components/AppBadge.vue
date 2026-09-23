<script setup lang="ts">
import { computed } from 'vue'

/**
 * A status chip.
 *
 * **Not a pill.** A rounded rectangle at the small radius sits in a table
 * cell without drawing a shape of its own, and a column of pills reads as
 * decoration. The pill was the first thing that made this look like a
 * consumer dashboard.
 *
 * Every tone carries a tint of its own colour rather than sharing one grey
 * chip. Status is the column an operator scans down, and a row of
 * identically-shaped grey pills makes them read the words instead of
 * seeing the shape — which is the whole reason a badge exists. The tint is
 * mixed from the semantic token, so it follows the theme and a rebrand
 * without a second palette to maintain.
 */
const props = withDefaults(
  defineProps<{
    tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info' | 'automation'
  }>(),
  { tone: 'neutral' },
)

const classes = computed(
  () =>
    ({
      neutral: 'bg-surface-sunken text-content-muted ring-line',
      accent:
        'bg-[color-mix(in_oklab,var(--color-accent)_14%,transparent)] text-accent ring-[color-mix(in_oklab,var(--color-accent)_30%,transparent)]',
      success:
        'bg-[color-mix(in_oklab,var(--color-success)_14%,transparent)] text-success ring-[color-mix(in_oklab,var(--color-success)_30%,transparent)]',
      warning:
        'bg-[color-mix(in_oklab,var(--color-warning)_16%,transparent)] text-warning ring-[color-mix(in_oklab,var(--color-warning)_32%,transparent)]',
      danger:
        'bg-[color-mix(in_oklab,var(--color-danger)_14%,transparent)] text-danger ring-[color-mix(in_oklab,var(--color-danger)_30%,transparent)]',
      // Neither a success nor a warning. "This ran" and "this is fine" are
      // different sentences, and a grey pill said neither.
      info: 'bg-[color-mix(in_oklab,var(--color-info)_14%,transparent)] text-info ring-[color-mix(in_oklab,var(--color-info)_30%,transparent)]',
      automation:
        'bg-[color-mix(in_oklab,var(--color-automation)_14%,transparent)] text-automation ring-[color-mix(in_oklab,var(--color-automation)_30%,transparent)]',
    })[props.tone],
)
</script>

<template>
  <span
    class="text-label inline-flex items-center rounded-[var(--radius-sm)] px-1.5 py-0.5 font-medium ring-1 ring-inset"
    :class="classes"
  >
    <slot />
  </span>
</template>
