<script setup lang="ts">
/**
 * A bar chart, drawn in SVG by this file and nothing else.
 *
 * No charting library. One would be three hundred kilobytes and a
 * dependency for a product that needs bars with numbers on them — and the
 * day it needs a candlestick chart is the day to reconsider, not before.
 *
 * Accessible by construction: the same numbers are in a table underneath
 * for anybody who cannot see the bars, hidden visually rather than hidden
 * from the accessibility tree. A chart that is only a picture is a chart
 * half the audience cannot read.
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    rows: { label: string; value: number }[]
    /** Horizontal bars read better when the labels are words. */
    horizontal?: boolean
    unit?: string
  }>(),
  { horizontal: false, unit: '' },
)

const max = computed(() => Math.max(1, ...props.rows.map((row) => row.value)))
const total = computed(() => props.rows.reduce((sum, row) => sum + row.value, 0))

function percent(value: number): number {
  return Math.round((value / max.value) * 100)
}
</script>

<template>
  <div>
    <div class="mb-3 flex items-baseline justify-between gap-4">
      <h3 class="text-[0.8125rem] font-semibold tracking-tight">{{ title }}</h3>
      <span class="text-content-muted text-xs tabular-nums">{{ total }} {{ unit }}</span>
    </div>

    <p v-if="total === 0" class="text-content-muted text-sm">Nothing in this period.</p>

    <!-- Horizontal: a row per label, the bar as a width. -->
    <ul v-else-if="horizontal" class="flex flex-col gap-2" aria-hidden="true">
      <li
        v-for="row in rows"
        :key="row.label"
        class="grid grid-cols-[10rem_1fr_3rem] items-center gap-3"
      >
        <span class="text-content-muted truncate text-xs">{{ row.label }}</span>
        <span class="bg-surface-sunken h-2 overflow-hidden rounded-full">
          <span
            class="bg-accent block h-full rounded-full transition-[width] duration-(--duration-base) ease-(--ease-out)"
            :style="{ width: `${percent(row.value)}%` }"
          />
        </span>
        <span class="text-right text-xs tabular-nums">{{ row.value }}</span>
      </li>
    </ul>

    <!-- Vertical: one column per day, which is how a period reads. -->
    <div v-else class="flex h-32 items-end gap-1" aria-hidden="true">
      <div
        v-for="row in rows"
        :key="row.label"
        class="flex min-w-0 flex-1 flex-col items-center gap-1"
      >
        <span
          class="bg-accent w-full rounded-t-[3px] transition-[height] duration-(--duration-base) ease-(--ease-out)"
          :class="row.value === 0 ? 'bg-surface-sunken' : ''"
          :style="{ height: `${Math.max(2, percent(row.value))}%` }"
          :title="`${row.label}: ${row.value}`"
        />
        <span class="text-content-subtle w-full truncate text-center text-[10px]">
          {{ row.label }}
        </span>
      </div>
    </div>

    <!-- The same numbers, for anybody who cannot see the bars. -->
    <table class="sr-only">
      <caption>
        {{
          title
        }}
      </caption>
      <tbody>
        <tr v-for="row in rows" :key="row.label">
          <th scope="row">{{ row.label }}</th>
          <td>{{ row.value }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
