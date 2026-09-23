<script setup lang="ts">
/**
 * A table's shape, before its rows arrive.
 *
 * Not a spinner. A spinner says "wait" and nothing else; a skeleton that
 * matches the table it is standing in for says how many columns there are
 * and how wide they will be, so the page does not jump when the data lands.
 * The jump is the actual complaint people have about loading states.
 *
 * The bar widths vary per cell, seeded from the row and column rather than
 * random: identical bars read as a progress bar, and a different layout on
 * every render is a page that looks broken while it loads.
 */
import { computed } from 'vue'

import { type TableColumn } from './tableContext'

const props = withDefaults(
  defineProps<{
    /** Either shape the real table takes, so the two cannot disagree. */
    headers?: string[]
    columns?: TableColumn[]
    rows?: number
    selectable?: boolean
  }>(),
  { headers: undefined, columns: undefined, rows: 8, selectable: false },
)

const labels = computed(() => props.columns?.map((column) => column.label) ?? props.headers ?? [])

/** Deterministic, so the same table always loads looking the same way. */
const WIDTHS = ['38%', '72%', '55%', '84%', '46%', '64%']

function width(row: number, column: number): string {
  return WIDTHS[(row * 3 + column * 2) % WIDTHS.length] ?? '60%'
}
</script>

<template>
  <div
    class="border-line bg-surface-primary overflow-x-auto rounded-[var(--radius-lg)] border shadow-(--shadow-raised)"
    aria-busy="true"
  >
    <!-- One announcement, not one per bar: a screen reader reading eighty
         placeholder cells is worse than silence. -->
    <p class="sr-only" role="status">Loading</p>

    <table class="data-table text-body w-full text-left">
      <thead>
        <tr>
          <th v-if="selectable" scope="col" class="w-0 px-4 py-2.5" />
          <th
            v-for="label in labels"
            :key="label"
            scope="col"
            class="text-content-subtle text-label px-4 py-2.5 font-medium uppercase"
          >
            {{ label }}
          </th>
        </tr>
      </thead>
      <tbody class="divide-line divide-y" aria-hidden="true">
        <tr v-for="row in rows" :key="row">
          <td v-if="selectable" class="px-4 py-2.5">
            <span class="skeleton block size-3.5 rounded-[3px]" />
          </td>
          <td v-for="(label, column) in labels" :key="label" class="px-4 py-2.5">
            <span class="skeleton block h-3 rounded-full" :style="{ width: width(row, column) }" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
