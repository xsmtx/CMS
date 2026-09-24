<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppIcon from './AppIcon.vue'
import { type IconName } from '../icons'

/**
 * The only pressable primitive in the product.
 *
 * `:active` scales the element down. It is the cheapest thing that makes an
 * interface feel like it heard the click, and its absence is felt even
 * though nobody names it. Only `transform` animates, so it stays on the GPU.
 */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-subtle'
    size?: 'sm' | 'md'
    href?: string
    type?: 'button' | 'submit'
    disabled?: boolean
    loading?: boolean
    /** A leading glyph. The label stays — an icon supports a word here, it does not replace it. */
    icon?: IconName
  }>(),
  {
    variant: 'secondary',
    size: 'md',
    href: undefined,
    type: 'button',
    disabled: false,
    loading: false,
    icon: undefined,
  },
)

const component = computed(() => (props.href ? Link : 'button'))

const classes = computed(() => [
  // Height is the control token, not padding: a button, an input and a
  // select in one row are the same height because they read one number,
  // and `data-density` moves all three together.
  'pressable inline-flex items-center justify-center gap-1.5 rounded-md',
  // A border on every variant (transparent where the variant has none), so
  // a button is exactly as tall as the input beside it. The colour belongs
  // to the variant: a `border-transparent` here in the base used to win over
  // the secondary variant's `border-line`, and secondary buttons had no edge.
  //
  // Medium, not semibold: a panel with forty controls on it does not need
  // forty of them shouting, and weight is the first thing that makes an
  // operator tool look like a consumer app.
  'border font-medium whitespace-nowrap',
  'transition-[color,background-color,border-color,opacity] duration-(--duration-fast) ease-(--ease-out)',
  'disabled:pointer-events-none disabled:opacity-55',
  props.size === 'sm' ? 'text-chrome h-(--control-h-sm) px-2.5' : 'text-body h-(--control-h) px-3',
  {
    primary: 'bg-brand text-content-inverse hover:bg-brand-hover border-transparent',
    // The line, not the strong line: a secondary button outlined in the
    // heavier border reads as a text field, which is the thing beside it.
    secondary: 'border-line bg-surface-primary text-content hover:border-line-strong',
    ghost: 'text-content-muted hover:bg-surface-secondary hover:text-content border-transparent',
    danger: 'bg-danger text-content-inverse hover:opacity-90 border-transparent',
    // The way *into* a destructive action: a Danger Zone row, a menu. Red
    // words on an outline, so a page with a delete on it is not a page with
    // a red block on it. The solid `danger` is the last press, in the
    // confirmation — the one moment the colour should be loud.
    'danger-subtle': 'border-danger/40 text-danger hover:bg-danger/10 bg-transparent',
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
    <AppIcon v-else-if="icon" :name="icon" :size="size === 'sm' ? 14 : 16" />
    <slot />
  </component>
</template>
