<script setup lang="ts">
import { computed } from 'vue'

/**
 * The one place a full-pill radius is used. Everything else follows the
 * 6/10/14 radius scale.
 */
const props = withDefaults(
  defineProps<{ tone?: 'neutral' | 'accent' | 'success' | 'warning' | 'danger' }>(),
  { tone: 'neutral' },
)

const classes = computed(
  () =>
    ({
      neutral: 'bg-surface-sunken text-content-muted',
      accent: 'bg-accent-subtle text-accent',
      success: 'bg-surface-sunken text-success',
      warning: 'bg-surface-sunken text-warning',
      danger: 'bg-surface-sunken text-danger',
    })[props.tone],
)
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium tracking-tight"
    :class="classes"
  >
    <slot />
  </span>
</template>
