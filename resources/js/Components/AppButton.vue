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
  //
  // **Shape carries meaning here, and the rule is DESIGN.md's own.** That
  // document gives `button-primary` and `button-secondary-pill` a pill and
  // `button-dark-utility` an 8px corner, which is not an inconsistency: the
  // pill is the thing the page is asking you to do, and the 8px square is a
  // control in a row of controls. So primary and danger are pills; the
  // toolbar variants keep the control radius that the input beside them has.
  'pressable inline-flex items-center justify-center gap-1.5',
  props.variant === 'primary' || props.variant === 'danger' ? 'rounded-full' : 'rounded-md',
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
  props.size === 'sm' ? 'text-chrome h-(--control-h-sm)' : 'text-body h-(--control-h)',
  // 22px against 15px, which is what the spec pads a pill and a utility
  // button: a pill with square padding reads as a squashed capsule.
  props.variant === 'primary' || props.variant === 'danger'
    ? props.size === 'sm'
      ? 'px-4'
      : 'px-5'
    : props.size === 'sm'
      ? 'px-2.5'
      : 'px-3',
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
