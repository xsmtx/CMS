<script setup lang="ts">
/**
 * A row of a table that may be selected.
 *
 * It exists because the checkbox cell cannot be injected: the rows of every
 * list here are markup the caller wrote, and a component cannot reach into
 * a slot to add a cell in front of the first one. So the caller writes
 * `<AppTableRow :id="invoice.id">` and the cell arrives with it.
 *
 * Whether the table is selectable at all, and what is selected, come from
 * the table through `provide`. Passing them per row would mean every list
 * repeating `:selected="selected.includes(row.id)"` on every row, and the
 * list that got it wrong would be a list whose rows silently cannot be
 * picked.
 *
 * A row with no `id` is an ordinary row, which is what keeps this usable in
 * a table that has no selection.
 */
import { computed, inject } from 'vue'

import { TABLE_CONTEXT } from './tableContext'

const props = defineProps<{
  /** The row's identity. Omitted on a table without selection. */
  id?: string
  /**
   * What this row is, for the checkbox's name. "Select invoice INV-1043"
   * rather than "Select row 4" — the second is a position, and a position
   * changes when the sort does.
   */
  label?: string
}>()

const table = inject(TABLE_CONTEXT, null)

const selectable = computed(() => table?.selectable.value === true && props.id !== undefined)

const selected = computed(() => props.id !== undefined && table?.isSelected(props.id) === true)

function toggle(): void {
  if (props.id !== undefined) table?.toggle(props.id)
}
</script>

<template>
  <!--
    `data-selected` rather than a class: the tint and the inset edge that
    mark a selected row are one rule in the stylesheet, next to the hover
    rule they have to stay distinguishable from.
  -->
  <tr :data-selected="selected ? '' : undefined">
    <td v-if="table?.selectable.value" class="w-0 px-4 py-2.5">
      <input
        v-if="selectable"
        type="checkbox"
        class="border-line-strong accent-brand size-3.5 rounded-[3px] border"
        :checked="selected"
        :aria-label="`Select ${label ?? table.noun.value}`"
        @change="toggle"
      />
    </td>
    <slot />
  </tr>
</template>
