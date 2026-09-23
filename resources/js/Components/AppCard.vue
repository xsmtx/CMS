<script setup lang="ts">
/**
 * Surface primitive.
 *
 * A card is used only where elevation communicates real hierarchy. Grouping
 * that does not need elevation uses a divider or spacing instead, which is
 * why this component has no "flat" variant to reach for by reflex.
 *
 * The header is separated from the body by a hairline rather than by
 * whitespace. On a dark panel a rule is what reads as structure; padding
 * alone reads as a gap, and an operator scanning six cards sees six gaps.
 */
withDefaults(
  defineProps<{
    title?: string
    description?: string
    as?: 'section' | 'article' | 'div'
  }>(),
  { title: undefined, description: undefined, as: 'section' },
)
</script>

<template>
  <component
    :is="as"
    class="border-line bg-surface-raised rounded-[var(--radius-lg)] border shadow-(--shadow-raised)"
  >
    <header
      v-if="title || description || $slots.actions"
      class="border-line flex items-start justify-between gap-4 border-b px-5 py-3.5"
    >
      <div class="min-w-0">
        <h2 v-if="title" class="text-title font-semibold">{{ title }}</h2>
        <p v-if="description" class="text-content-muted text-chrome mt-1 max-w-[70ch]">
          {{ description }}
        </p>
      </div>
      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </header>

    <div class="px-5 py-4">
      <slot />
    </div>
  </component>
</template>
