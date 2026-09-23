<script setup lang="ts">
/**
 * A number an operator opens a screen to see.
 *
 * Loose text above a table reads as a caption nobody looks at. Given its
 * own surface, the same number becomes the first thing on the page — which
 * is what "how many are stuck" deserves to be.
 *
 * Pressable when it filters: a count you can act on should say so by
 * behaving like a control, not by being explained in a sentence below it.
 */
withDefaults(
  defineProps<{
    label: string
    value: number | string
    tone?: 'neutral' | 'danger' | 'warning' | 'success'
    active?: boolean
  }>(),
  { tone: 'neutral', active: false },
)

const emit = defineEmits<{ select: [] }>()
</script>

<template>
  <button
    type="button"
    class="pressable border-line bg-surface-primary hover:border-line-strong flex min-w-[8.5rem] flex-col items-start gap-0.5 rounded-[var(--radius-md)] border px-3.5 py-2.5 text-left transition-colors duration-(--duration-fast) ease-(--ease-out)"
    :class="active ? 'border-brand' : ''"
    :aria-pressed="active"
    @click="emit('select')"
  >
    <span class="text-content-muted text-label uppercase">{{ label }}</span>
    <span
      class="text-[1.375rem] leading-tight font-semibold tabular-nums"
      :class="
        {
          neutral: '',
          danger: 'text-danger',
          warning: 'text-warning',
          success: 'text-success',
        }[tone]
      "
    >
      {{ value }}
    </span>
  </button>
</template>
