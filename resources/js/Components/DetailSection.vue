<script setup lang="ts">
/**
 * A titled region of a page that is **not** a card.
 *
 * Most grouping on an operator screen does not need a box: a heading and a
 * hairline say "this is a new subject" without drawing a rectangle around
 * it, and a page of rectangles is a page where nothing is more important
 * than anything else. Reach for this before `AppCard`; reach for `AppCard`
 * only when the region is a separate surface (a panel beside the main
 * column, a chart that needs a frame).
 *
 * The heading level is a prop because a section can sit under a page `h1`
 * or under another section, and a skipped level is a broken outline for a
 * screen reader.
 */
withDefaults(
  defineProps<{
    title: string
    description?: string
    level?: 2 | 3
    /** A hairline under the header. Off when the content is itself a table. */
    divided?: boolean
  }>(),
  { description: undefined, level: 2, divided: true },
)
</script>

<template>
  <section class="min-w-0">
    <header
      class="flex items-end justify-between gap-4 pb-2"
      :class="divided ? 'border-line mb-3 border-b' : ''"
    >
      <div class="min-w-0">
        <component :is="`h${level}`" class="text-title font-semibold">{{ title }}</component>
        <p v-if="description" class="text-content-muted text-chrome mt-0.5 max-w-[80ch]">
          {{ description }}
        </p>
      </div>
      <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
        <slot name="actions" />
      </div>
    </header>

    <slot />
  </section>
</template>
