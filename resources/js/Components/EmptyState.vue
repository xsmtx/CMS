<script setup lang="ts">
/**
 * Empty is a state, not an absence.
 *
 * Every list in this product ships one of these: it says what will appear
 * here and what has to happen first. A blank panel reads as a bug.
 *
 * Composed rather than centred. A centred empty state in a panel is a
 * marketing pattern — it pulls the eye to the middle of a screen the
 * operator is going to fill with rows, and then everything shifts when the
 * first row arrives. This sits where the table will sit, aligned to the
 * same left edge, so arriving data does not move the page.
 *
 * The icon is in a tinted square rather than loose: an outline glyph on a
 * dark panel disappears, and the square is what gives it a footprint.
 */
import AppIcon from './AppIcon.vue'
import { type IconName } from '../icons'

withDefaults(
  defineProps<{
    title: string
    description: string
    icon?: IconName
    /**
     * `boxed` stands in for a table that has no rows. `plain` sits inside a
     * section that already has a heading and a hairline — a dashed box
     * inside that would be a container inside a container.
     */
    variant?: 'boxed' | 'plain'
  }>(),
  { icon: 'database', variant: 'boxed' },
)
</script>

<template>
  <div
    class="flex items-start gap-3"
    :class="
      variant === 'boxed'
        ? 'border-line bg-surface-primary rounded-lg border border-dashed px-5 py-6'
        : 'py-2'
    "
  >
    <span
      class="bg-surface-secondary text-content-subtle grid size-8 shrink-0 place-items-center rounded-md"
      aria-hidden="true"
    >
      <AppIcon :name="icon" :size="16" />
    </span>

    <div class="min-w-0">
      <p class="text-body font-medium">{{ title }}</p>
      <p class="text-content-muted text-body mt-1 max-w-[56ch]">{{ description }}</p>

      <div v-if="$slots.default" class="mt-3">
        <slot />
      </div>
    </div>
  </div>
</template>
