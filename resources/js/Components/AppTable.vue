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
 * without the page feeling cramped — a hairline between rows, and a header
 * that stays put when the list is two hundred long.
 *
 * Two ways to declare the columns, and the older one still works:
 *
 * - `headers` — a list of strings, with `numeric` naming the indexes that
 *   hold figures. Every list written before §7 uses this.
 * - `columns` — `TableColumn[]`, which adds a `key`. A cell carrying
 *   `data-col="<key>"` can then be hidden, which is the only way to offer
 *   column visibility over rows the caller wrote by hand.
 *
 * Column visibility is `localStorage`, deliberately: which columns fit is a
 * fact about the window somebody is looking at, so an operator on a laptop
 * and on a 34-inch monitor wants a different answer on each and neither is
 * "the setting". A **saved view** is the opposite — a named question about
 * the data — and that lives on the server.
 */
import { computed, provide, ref, toRef, useId, useSlots, watch } from 'vue'

import AppIcon from './AppIcon.vue'
import AppMenu from './AppMenu.vue'
import { useTranslations } from '../composables/useTranslations'
import { TABLE_CONTEXT, type TableColumn, type TableSort } from './tableContext'

const props = withDefaults(
  defineProps<{
    /** The older shape. Kept because most of the panel is written against it. */
    headers?: string[]
    numeric?: number[]
    /** The §7 shape: keyed columns, some of which the operator may hide. */
    columns?: TableColumn[]
    /**
     * A stable name for this table, so hidden columns are remembered.
     *
     * Without one the columns control is not offered at all: a preference
     * with nowhere to live is a control that forgets on every navigation,
     * which is worse than no control.
     */
    name?: string
    /**
     * Drop the frame. For a table that sits inside one already — a portal
     * card, where the card is the rectangle and the table is its body.
     */
    flush?: boolean
    /** Offer a checkbox per row. */
    selectable?: boolean
    /** Every row on this page, in order — what "select all" means. */
    rowIds?: string[]
    /** The word for one row, for the checkboxes' accessible names. */
    noun?: string
  }>(),
  {
    headers: undefined,
    numeric: undefined,
    columns: undefined,
    name: undefined,
    flush: false,
    selectable: false,
    rowIds: undefined,
    noun: 'row',
  },
)

/** The ids of the selected rows. Owned by the caller, because the actions are. */
const selected = defineModel<string[]>('selected', { default: () => [] })

/**
 * The sort, owned by the caller because the server does the sorting. A
 * header press cycles ascending → descending → the server's default.
 */
const sort = defineModel<TableSort | null>('sort', { default: null })

function cycleSort(key: string): void {
  if (sort.value?.key !== key) sort.value = { key, direction: 'asc' }
  else if (sort.value.direction === 'asc') sort.value = { key, direction: 'desc' }
  else sort.value = null
}

function ariaSort(key: string): 'ascending' | 'descending' | undefined {
  if (sort.value?.key !== key) return undefined

  return sort.value.direction === 'asc' ? 'ascending' : 'descending'
}

interface ResolvedColumn {
  key: string
  label: string
  numeric: boolean
  optional: boolean
  sortable: boolean
}

const declared = computed<ResolvedColumn[]>(() =>
  props.columns === undefined
    ? (props.headers ?? []).map((label, index) => ({
        // Index-based, because a legacy table has no keys and two of its
        // headers are often the empty string.
        key: `c${index}`,
        label,
        numeric: (props.numeric ?? []).includes(index),
        optional: false,
        sortable: false,
      }))
    : props.columns.map((column) => ({
        key: column.key,
        label: column.label,
        numeric: column.numeric === true,
        optional: column.optional === true,
        sortable: column.sortable === true,
      })),
)

const optionalColumns = computed(() => declared.value.filter((column) => column.optional))

const storageKey = computed(() => (props.name === undefined ? null : `table.${props.name}.hidden`))

function initialHidden(): string[] {
  const off = (props.columns ?? [])
    .filter((column) => column.optional === true && column.offByDefault === true)
    .map((column) => column.key)

  const key = storageKey.value

  if (key === null) return off

  try {
    const stored = window.localStorage.getItem(key)

    // No stored answer is different from an empty stored answer: the first
    // means "never asked", the second means "they turned everything on".
    return stored === null ? off : (JSON.parse(stored) as string[])
  } catch {
    // Storage blocked, or a stored value somebody else's version wrote.
    return off
  }
}

const hidden = ref<string[]>(initialHidden())

watch(hidden, (value) => {
  const key = storageKey.value

  if (key === null) return

  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // Losing the preference is not worth an error.
  }
})

/** Offered only when there is a preference to remember and something to hide. */
const canChooseColumns = computed(
  () => storageKey.value !== null && optionalColumns.value.length > 0,
)

const { t } = useTranslations()

const slots = useSlots()

/** The columns control lives in the header row when there is no toolbar. */
const inlineColumns = computed(() => canChooseColumns.value && slots.toolbar === undefined)

const visibleColumns = computed(() =>
  declared.value.filter((column) => !hidden.value.includes(column.key)),
)

function isShown(key: string): boolean {
  return !hidden.value.includes(key)
}

function toggleColumn(key: string): void {
  hidden.value = hidden.value.includes(key)
    ? hidden.value.filter((entry) => entry !== key)
    : [...hidden.value, key]
}

/**
 * The rule that hides a cell the caller wrote.
 *
 * A `<td>` is slot content, so no class of ours is on it and no scoped
 * style reaches it. One stylesheet, scoped to this table's own id, is what
 * makes `data-col` work as a seam.
 */
const tableId = useId()

/** Tailwind's breakpoints, as the widths a priority column disappears below. */
const BREAKPOINTS = { md: 768, lg: 1024, xl: 1280 } as const

const SAFE_KEY = /^[\w-]+$/

const columnCss = computed(() => {
  const rules: string[] = []

  const keys = hidden.value
    // Our own keys, but a generated stylesheet takes nothing on trust.
    .filter((key) => SAFE_KEY.test(key))
    .map((key) => `#${tableId} [data-col="${key}"]`)

  if (keys.length > 0) rules.push(`${keys.join(',')}{display:none}`)

  for (const column of props.columns ?? []) {
    if (!SAFE_KEY.test(column.key)) continue

    const cell = `#${tableId} [data-col="${column.key}"]`

    // Priority columns: gone below a width, so the ones that identify the
    // row and say its state are what a laptop keeps.
    if (column.hideBelow !== undefined) {
      rules.push(
        `@media (max-width:${BREAKPOINTS[column.hideBelow] - 0.02}px){${cell}{display:none}}`,
      )
    }

    // The identity column stays put while the rest scrolls sideways. It
    // needs its own background or the scrolled cells show through it.
    if (column.sticky === true) {
      rules.push(
        `${cell}{position:sticky;left:0;z-index:1;background:var(--surface-primary);box-shadow:inset -1px 0 0 var(--border-subtle)}`,
        `#${tableId} thead [data-col="${column.key}"]{z-index:2}`,
        // An opaque cell would otherwise hide the row's hover and selection.
        `#${tableId} tbody tr:hover [data-col="${column.key}"]{background:var(--surface-hover)}`,
      )
    }
  }

  return rules.join('')
})

// --- selection --------------------------------------------------------------

const pageIds = computed(() => props.rowIds ?? [])

const allSelected = computed(
  () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
)

const someSelected = computed(
  () => !allSelected.value && pageIds.value.some((id) => selected.value.includes(id)),
)

function toggle(id: string): void {
  selected.value = selected.value.includes(id)
    ? selected.value.filter((entry) => entry !== id)
    : [...selected.value, id]
}

/**
 * Select-all means this page, and says so.
 *
 * A checkbox that quietly selected two hundred rows an operator has not
 * seen is how a bulk action goes wrong; the count next to it is the whole
 * safeguard. Rows selected on an earlier page are kept rather than dropped,
 * because somebody paging through a list is still choosing.
 */
function toggleAll(): void {
  selected.value = allSelected.value
    ? selected.value.filter((id) => !pageIds.value.includes(id))
    : [...new Set([...selected.value, ...pageIds.value])]
}

provide(TABLE_CONTEXT, {
  selectable: toRef(props, 'selectable'),
  isSelected: (id: string) => selected.value.includes(id),
  toggle,
  noun: toRef(props, 'noun'),
})
</script>

<template>
  <div class="flex flex-col gap-3">
    <!--
      A strip above the table, and only when something is on it. An empty
      toolbar is 40px of nothing above every list in the panel.
    -->
    <!--
      A strip above the table, and only when the caller has something to put
      on it. Without a toolbar the columns control sits in the header row
      itself (below): a row of height spent on one small menu is a row of
      data an operator does not see.
    -->
    <div v-if="$slots.toolbar" class="flex flex-wrap items-center gap-x-3 gap-y-2">
      <slot name="toolbar" />

      <span v-if="canChooseColumns" class="ml-auto">
        <AppMenu :label="t('ui.common.columns', {}, 'Columns')" align="end" width="14rem">
          <p class="text-content-subtle text-label px-2 pt-1 pb-1.5 uppercase">
            {{ t('ui.common.show_columns', {}, 'Show') }}
          </p>
          <label
            v-for="column in optionalColumns"
            :key="column.key"
            class="hover:bg-surface-secondary text-body flex cursor-pointer items-center gap-2.5 rounded-sm px-2 py-1.5"
          >
            <input
              type="checkbox"
              class="border-line-strong accent-brand size-3.5 rounded-[3px] border"
              :checked="isShown(column.key)"
              @change="toggleColumn(column.key)"
            />
            <span class="truncate">{{ column.label }}</span>
          </label>
        </AppMenu>
      </span>
    </div>

    <!-- The selection bar. The caller owns the actions, because only the
         caller knows what they do and which of them is dangerous. -->
    <slot
      v-if="selectable && selected.length > 0"
      name="bulk"
      :selected="selected"
      :count="selected.length"
      :clear="() => (selected = [])"
    />

    <component :is="'style'" v-if="columnCss">{{ columnCss }}</component>

    <div class="relative">
      <div
        :id="tableId"
        class="bg-surface-primary overflow-x-auto"
        :class="flush ? '' : 'border-line rounded-lg border'"
      >
        <table class="data-table text-body w-full text-left">
          <thead>
            <tr>
              <th v-if="selectable" scope="col" class="w-0">
                <input
                  type="checkbox"
                  class="border-line-strong accent-brand size-3.5 rounded-[3px] border"
                  :checked="allSelected"
                  :indeterminate="someSelected"
                  :aria-label="`Select every ${noun} on this page`"
                  @change="toggleAll"
                />
              </th>
              <th
                v-for="(column, index) in visibleColumns"
                :key="column.key"
                scope="col"
                :data-col="column.key"
                :aria-sort="column.sortable ? ariaSort(column.key) : undefined"
                class="text-content-subtle text-label font-medium whitespace-nowrap uppercase"
                :class="[
                  column.numeric ? 'numeric' : '',
                  inlineColumns && index === visibleColumns.length - 1 ? 'pr-11' : '',
                ]"
              >
                <!-- A sortable header is a button inside the th, so the th keeps
                   its header semantics and the control is a real control. -->
                <button
                  v-if="column.sortable"
                  type="button"
                  class="hover:text-content inline-flex items-center gap-1 uppercase"
                  :class="sort?.key === column.key ? 'text-content' : ''"
                  @click="cycleSort(column.key)"
                >
                  {{ column.label }}
                  <AppIcon
                    :name="
                      sort?.key === column.key && sort.direction === 'desc'
                        ? 'chevronDown'
                        : 'chevronUp'
                    "
                    :size="12"
                    :class="sort?.key === column.key ? '' : 'opacity-0'"
                  />
                </button>
                <template v-else>{{ column.label }}</template>
              </th>
            </tr>
          </thead>
          <tbody class="divide-line divide-y">
            <slot />
          </tbody>
        </table>
      </div>

      <!-- Over the header's right end, outside the scrolling box so it stays
         put when the table scrolls sideways. -->
      <span v-if="inlineColumns" class="absolute top-0.5 right-1.5 z-[2]">
        <AppMenu
          :label="t('ui.common.choose_columns', {}, 'Choose columns')"
          icon="settings"
          align="end"
          width="14rem"
        >
          <p class="text-content-subtle text-label px-2 pt-1 pb-1.5 uppercase">
            {{ t('ui.common.show_columns', {}, 'Show') }}
          </p>
          <label
            v-for="column in optionalColumns"
            :key="column.key"
            class="hover:bg-surface-secondary text-body flex cursor-pointer items-center gap-2.5 rounded-sm px-2 py-1.5"
          >
            <input
              type="checkbox"
              class="border-line-strong accent-brand size-3.5 rounded-[3px] border"
              :checked="isShown(column.key)"
              @change="toggleColumn(column.key)"
            />
            <span class="truncate">{{ column.label }}</span>
          </label>
        </AppMenu>
      </span>
    </div>
  </div>
</template>
