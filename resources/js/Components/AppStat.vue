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
    class="pressable border-line bg-surface-raised hover:border-line-strong flex min-w-[9rem] flex-col items-start gap-1 rounded-[var(--radius-md)] border px-4 py-3.5 text-left shadow-(--shadow-raised) transition-colors duration-(--duration-fast) ease-(--ease-out)"
    :class="active ? 'border-accent' : ''"
    :aria-pressed="active"
    @click="emit('select')"
  >
    <span class="text-content-muted text-xs">{{ label }}</span>
    <span
      class="text-2xl leading-none font-semibold tabular-nums"
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
