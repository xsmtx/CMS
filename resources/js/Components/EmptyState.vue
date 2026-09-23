<script setup lang="ts">
/**
 * Empty is a state, not an absence.
 *
 * Every list in this product ships one of these: it says what will appear
 * here and what has to happen first. A blank panel reads as a bug.
 */
defineProps<{
  title: string
  description: string
}>()
</script>

<template>
  <div
    class="border-line bg-surface-raised flex flex-col items-start gap-3 rounded-[var(--radius-lg)] border border-dashed px-6 py-10"
  >
    <div>
      <p class="text-sm font-medium">{{ title }}</p>
      <p class="text-content-muted mt-1 max-w-[48ch] text-sm leading-relaxed">
        {{ description }}
      </p>
    </div>
    <slot />
  </div>
</template>
