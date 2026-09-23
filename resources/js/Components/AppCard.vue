<script setup lang="ts">
/**
 * Surface primitive.
 *
 * A card is used only where elevation communicates real hierarchy. Grouping
 * that does not need elevation uses a divider or spacing instead, which is
 * why this component has no "flat" variant to reach for by reflex.
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
      class="flex items-start justify-between gap-4 px-6 pt-6"
    >
      <div>
        <h2 v-if="title" class="text-[0.9375rem] font-semibold tracking-tight">{{ title }}</h2>
        <p
          v-if="description"
          class="text-content-muted mt-1.5 max-w-[62ch] text-sm leading-relaxed"
        >
          {{ description }}
        </p>
      </div>
      <slot name="actions" />
    </header>

    <div class="px-6 py-6">
      <slot />
    </div>
  </component>
</template>
