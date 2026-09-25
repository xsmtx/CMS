<script setup lang="ts">
/**
 * A few mutually exclusive choices, all visible at once.
 *
 * For the small sets where a select would hide the options an operator is
 * comparing: which currency a price matrix is showing, which language a
 * template is being read in. Above about five options it is the wrong
 * control — that is a `FilterSelect`.
 *
 * A group of toggle buttons rather than a tab strip, because there are no
 * panels here: the thing being switched is somewhere else on the page, and
 * `role="tablist"` promises a `tabpanel` that does not exist. `aria-pressed`
 * says which one is on without promising anything.
 *
 * A count is shown when the caller gives one, zero included — "0 sold in
 * USD" is the answer somebody opened the control to find.
 */
export interface Segment {
  value: string
  label: string
  count?: number
}

defineProps<{ segments: Segment[]; label: string }>()

const model = defineModel<string>({ required: true })
</script>

<template>
  <div
    class="border-line bg-surface-secondary inline-flex w-fit max-w-full gap-0.5 overflow-x-auto rounded-sm border p-0.5"
    role="group"
    :aria-label="label"
  >
    <button
      v-for="segment in segments"
      :key="segment.value"
      type="button"
      :aria-pressed="segment.value === model"
      class="pressable text-body rounded-[calc(var(--radius-sm)-2px)] px-3 py-1.5 font-medium whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
      :class="
        segment.value === model
          ? 'bg-surface-primary text-content shadow-(--shadow-raised)'
          : 'text-content-muted hover:text-content'
      "
      @click="model = segment.value"
    >
      {{ segment.label }}
      <span
        v-if="segment.count !== undefined"
        class="text-content-subtle text-label ml-1 tabular-nums"
      >
        {{ segment.count }}
      </span>
    </button>
  </div>
</template>
