<script setup lang="ts">
/**
 * A handful of headline figures, in **one** strip.
 *
 * This is what the design system has instead of a row of KPI cards. Four
 * cards read as four unrelated claims and invite a fifth; one strip divided
 * by hairlines reads as one sentence about the system. The figure is
 * `text-page` — the size of a page title, not a billboard — because the
 * number matters and its font size does not.
 *
 * A figure with an `href` is a way in: the count of failed runs opens the
 * failed runs. A `tone` colours the figure only when the figure *is* a
 * status (three critical alerts); revenue is never green.
 *
 * A slot named after a metric's `key` replaces its value, for the case this
 * product has everywhere: **money is a list, not a number**. A reseller
 * selling in lira and euros has two MRRs and there is no rate here to make
 * them one, so the cell stacks them rather than printing a total that means
 * nothing.
 */
import { Link } from '@inertiajs/vue3'

import { type StatusTone } from './AppStatus.vue'

export interface Metric {
  key: string
  label: string
  value: string | number
  hint?: string
  href?: string
  tone?: StatusTone
}

defineProps<{ items: Metric[] }>()

const TONE: Partial<Record<StatusTone, string>> = {
  critical: 'text-danger',
  warning: 'text-warning',
  healthy: 'text-success',
  maintenance: 'text-maintenance',
  info: 'text-info',
}
</script>

<template>
  <div
    class="border-line bg-surface-primary grid grid-cols-2 overflow-hidden rounded-lg border lg:flex"
  >
    <component
      :is="metric.href ? Link : 'div'"
      v-for="(metric, index) in items"
      :key="metric.key"
      :href="metric.href"
      class="border-line flex min-w-0 flex-1 flex-col gap-0.5 px-4 py-3"
      :class="[
        metric.href ? 'hover:bg-surface-hover transition-colors duration-(--duration-fast)' : '',
        // Hairlines between figures: left edge on every odd cell of the
        // two-up grid, and on every cell but the first once it is one row.
        index % 2 === 1 ? 'border-l' : '',
        index > 1 ? 'border-t lg:border-t-0' : '',
        index > 0 ? 'lg:border-l' : '',
      ]"
    >
      <span class="text-content-subtle text-label truncate uppercase">{{ metric.label }}</span>
      <span
        class="text-page font-semibold tabular-nums"
        :class="metric.tone ? TONE[metric.tone] : ''"
      >
        <slot :name="metric.key" :metric="metric">{{ metric.value }}</slot>
      </span>
      <span v-if="metric.hint" class="text-content-subtle text-chrome truncate">
        {{ metric.hint }}
      </span>
    </component>
  </div>
</template>
