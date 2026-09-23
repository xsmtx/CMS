<script setup lang="ts">
/**
 * Table chrome.
 *
 * Rows are plain markup rather than a column API: every list in this product
 * needs different cell content, and a generic column abstraction would be
 * configured around rather than used.
 *
 * Dense on purpose. This is a panel somebody keeps open all day, and the
 * measure of a good operator table is how many rows fit above the fold
 * without the page feeling cramped — 36px rows, a hairline between them, and
 * a header that stays put when the list is two hundred long.
 *
 * A header ending in `#` is understood to be numeric and is right-aligned
 * with tabular figures, so a column of totals reads as a column. Pass
 * `numeric` on the cell to match it.
 */
const props = defineProps<{ headers: string[]; numeric?: number[] }>()

function isNumeric(index: number): boolean {
  return (props.numeric ?? []).includes(index)
}
</script>

<template>
  <div
    class="border-line bg-surface-raised overflow-x-auto rounded-[var(--radius-lg)] border shadow-(--shadow-raised)"
  >
    <table class="data-table text-body w-full text-left">
      <thead>
        <tr>
          <th
            v-for="(header, index) in headers"
            :key="header"
            scope="col"
            class="text-content-subtle text-label px-4 py-2.5 font-medium uppercase"
            :class="isNumeric(index) ? 'numeric' : ''"
          >
            {{ header }}
          </th>
        </tr>
      </thead>
      <tbody class="divide-line divide-y">
        <slot />
      </tbody>
    </table>
  </div>
</template>
