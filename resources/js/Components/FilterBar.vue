<script setup lang="ts">
/**
 * The strip between a page header and its table: search, filters, and on
 * the right what the result is (a count, the columns control).
 *
 * One line at desktop widths, wrapping rather than stacking below them.
 * **Not a form of labelled fields in a grid** — that is a search *page*, and
 * an operator filtering a list wants the filters to cost one row of height,
 * not four. Anything rarely used goes behind a "More filters" toggle, whose
 * panel is given through the `more` slot and opens under the bar.
 *
 * The caller owns the `<form>` and what submitting means; this is layout.
 */
defineProps<{
  /** The "More filters" panel is open. Controlled by the caller. */
  expanded?: boolean
}>()
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-2">
      <slot />

      <div v-if="$slots.end" class="ml-auto flex items-center gap-3">
        <slot name="end" />
      </div>
    </div>

    <div
      v-if="expanded && $slots.more"
      class="border-line bg-surface-primary rounded-lg border p-4"
    >
      <slot name="more" />
    </div>
  </div>
</template>
