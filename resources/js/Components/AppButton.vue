<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * The only pressable primitive in the product.
 *
 * `:active` scales the element down. It is the cheapest thing that makes an
 * interface feel like it heard the click, and its absence is felt even
 * though nobody names it. Only `transform` animates, so it stays on the GPU.
 */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md'
    href?: string
    type?: 'button' | 'submit'
    disabled?: boolean
    loading?: boolean
  }>(),
  {
    variant: 'secondary',
    size: 'md',
    href: undefined,
    type: 'button',
    disabled: false,
    loading: false,
  },
)

const component = computed(() => (props.href ? Link : 'button'))

const classes = computed(() => [
  'pressable inline-flex items-center justify-center gap-2 rounded-[var(--radius-sm)]',
  'font-semibold whitespace-nowrap',
  'transition-[color,background-color,border-color,opacity] duration-(--duration-fast) ease-(--ease-out)',
  'disabled:pointer-events-none disabled:opacity-55',
  props.size === 'sm' ? 'px-3.5 py-2 text-[13px]' : 'px-4.5 py-2.5 text-sm',
  {
    primary: 'bg-accent text-accent-content shadow-(--shadow-raised) hover:bg-accent-hover',
    secondary: 'border border-line-strong text-content hover:bg-surface-sunken',
    ghost: 'text-content-muted hover:bg-surface-sunken hover:text-content',
    danger: 'bg-danger text-accent-content hover:opacity-90',
  }[props.variant],
])
</script>

<template>
  <component
    :is="component"
    :href="href"
    :type="href ? undefined : type"
    :disabled="href ? undefined : disabled || loading"
    :aria-busy="loading || undefined"
    :class="classes"
  >
    <span
      v-if="loading"
      class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    <slot />
  </component>
</template>
