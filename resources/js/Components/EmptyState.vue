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
  <div class="flex flex-col items-start gap-3 py-2">
    <div>
      <p class="text-sm font-medium">{{ title }}</p>
      <p class="text-content-muted mt-1 max-w-[48ch] text-sm leading-relaxed">
        {{ description }}
      </p>
    </div>
    <slot />
  </div>
</template>
