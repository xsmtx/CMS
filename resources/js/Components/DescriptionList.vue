<script setup lang="ts">
/**
 * Label/value pairs, the way a detail page states facts.
 *
 * Two layouts, because there are two jobs:
 *
 * - `rows` — label column on the left, value on the right, a hairline
 *   between rows. For a panel of properties an operator reads down.
 * - `grid` — label above value, several per row. For the identity strip at
 *   the top of a resource (IP · provider · location · OS), read across.
 *
 * The label column is capped at 12rem: on a wide screen a value floating
 * 500px from its label is a value nobody connects to it.
 *
 * A missing value renders an em dash, never an empty cell: an empty cell
 * reads as "still loading". Identifiers (`mono: true`) get the monospace
 * face. A value that needs markup — a status, a link, a copy button — is
 * given through a slot named after the item's `key`.
 */
export interface DescriptionItem {
  key: string
  label: string
  value?: string | number | null
  mono?: boolean
}

withDefaults(
  defineProps<{
    items: DescriptionItem[]
    layout?: 'rows' | 'grid'
    /** Columns in the `grid` layout at wide widths. */
    columns?: 2 | 3 | 4 | 6
  }>(),
  { layout: 'rows', columns: 4 },
)

const GRID: Record<number, string> = {
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-2 lg:grid-cols-3',
  4: 'sm:grid-cols-2 lg:grid-cols-4',
  6: 'sm:grid-cols-3 xl:grid-cols-6',
}
</script>

<template>
  <dl v-if="layout === 'rows'" class="divide-line-subtle text-body divide-y">
    <div
      v-for="item in items"
      :key="item.key"
      class="grid grid-cols-[minmax(7rem,12rem)_minmax(0,1fr)] gap-4 py-2 first:pt-0 last:pb-0"
    >
      <dt class="text-content-muted">{{ item.label }}</dt>
      <dd class="min-w-0 break-words" :class="item.mono ? 'text-chrome font-mono' : ''">
        <slot :name="item.key" :item="item">{{ item.value ?? '—' }}</slot>
      </dd>
    </div>
  </dl>

  <dl v-else class="text-body grid grid-cols-1 gap-x-8 gap-y-3" :class="GRID[columns]">
    <div v-for="item in items" :key="item.key" class="min-w-0">
      <dt class="text-content-subtle text-label uppercase">{{ item.label }}</dt>
      <dd class="mt-0.5 truncate" :class="item.mono ? 'text-chrome font-mono' : ''">
        <slot :name="item.key" :item="item">{{ item.value ?? '—' }}</slot>
      </dd>
    </div>
  </dl>
</template>
