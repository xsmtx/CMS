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
 *
 * Two series are drawn against a **shared** maximum. Scaling each to its
 * own would make a day of a hundred lira and a day of ten thousand look
 * identical, which is the one thing a money chart must never do.
 */
import { computed } from 'vue'

interface Row {
  label: string
  value: number
}

const props = withDefaults(
  defineProps<{
    title: string
    rows: Row[]
    /** Horizontal bars read better when the labels are words. */
    horizontal?: boolean
    unit?: string
    /** A second series, drawn beside the first against the same scale. */
    compare?: Row[]
    seriesLabel?: string
    compareLabel?: string
    /** How a value is written out. Minor units are not a number anybody reads. */
    format?: (value: number) => string
  }>(),
  {
    horizontal: false,
    unit: '',
    compare: undefined,
    seriesLabel: undefined,
    compareLabel: undefined,
    format: undefined,
  },
)

const max = computed(() =>
  Math.max(1, ...props.rows.map((row) => row.value), ...(props.compare ?? []).map((r) => r.value)),
)

const total = computed(() => props.rows.reduce((sum, row) => sum + row.value, 0))
const compareTotal = computed(() => (props.compare ?? []).reduce((sum, row) => sum + row.value, 0))

function percent(value: number): number {
  return Math.round((value / max.value) * 100)
}

function write(value: number): string {
  return props.format ? props.format(value) : String(value)
}

/** The two series zipped, so one column holds both days. */
const columns = computed(() =>
  props.rows.map((row, index) => ({
    label: row.label,
    value: row.value,
    compare: props.compare?.[index]?.value ?? null,
  })),
)
</script>

<template>
  <div>
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
      <h3 class="text-body font-semibold tracking-tight">{{ title }}</h3>

      <div class="flex items-center gap-3 text-xs">
        <span class="flex items-center gap-1.5">
          <span v-if="compare" class="bg-brand size-2 rounded-full" aria-hidden="true" />
          <span class="text-content-muted tabular-nums">
            <template v-if="seriesLabel">{{ seriesLabel }} </template>{{ write(total) }}
            {{ unit }}
          </span>
        </span>
        <span v-if="compare" class="flex items-center gap-1.5">
          <span class="bg-content-subtle size-2 rounded-full" aria-hidden="true" />
          <span class="text-content-muted tabular-nums">
            <template v-if="compareLabel">{{ compareLabel }} </template>{{ write(compareTotal) }}
            {{ unit }}
          </span>
        </span>
      </div>
    </div>

    <p v-if="total === 0 && compareTotal === 0" class="text-content-muted text-sm">
      Nothing in this period.
    </p>

    <!-- Horizontal: a row per label, the bar as a width. -->
    <ul v-else-if="horizontal" class="flex flex-col gap-2" aria-hidden="true">
      <li
        v-for="row in rows"
        :key="row.label"
        class="grid grid-cols-[10rem_1fr_3rem] items-center gap-3"
      >
        <span class="text-content-muted truncate text-xs">{{ row.label }}</span>
        <span class="bg-surface-secondary h-2 overflow-hidden rounded-full">
          <span
            class="bg-brand block h-full rounded-full transition-[width] duration-(--duration-base) ease-(--ease-out)"
            :style="{ width: `${percent(row.value)}%` }"
          />
        </span>
        <span class="text-right text-xs tabular-nums">{{ write(row.value) }}</span>
      </li>
    </ul>

    <!-- Vertical: one column per day, which is how a period reads. -->
    <div v-else class="flex h-32 items-end gap-1" aria-hidden="true">
      <div
        v-for="column in columns"
        :key="column.label"
        class="flex min-w-0 flex-1 flex-col items-center gap-1"
      >
        <span class="flex h-full w-full items-end justify-center gap-px">
          <span
            class="bg-brand w-full rounded-t-[3px] transition-[height] duration-(--duration-base) ease-(--ease-out)"
            :class="column.value === 0 ? 'bg-surface-secondary' : ''"
            :style="{ height: `${Math.max(2, percent(column.value))}%` }"
            :title="`${column.label}: ${write(column.value)}`"
          />
          <span
            v-if="column.compare !== null"
            class="bg-content-subtle w-full rounded-t-[3px] transition-[height] duration-(--duration-base) ease-(--ease-out)"
            :class="column.compare === 0 ? 'bg-surface-secondary' : ''"
            :style="{ height: `${Math.max(2, percent(column.compare))}%` }"
            :title="`${column.label}: ${write(column.compare)}`"
          />
        </span>
        <span class="text-content-subtle w-full truncate text-center text-[10px]">
          {{ column.label }}
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
        <tr v-for="column in columns" :key="column.label">
          <th scope="row">{{ column.label }}</th>
          <td>{{ write(column.value) }}</td>
          <td v-if="column.compare !== null">{{ write(column.compare) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
