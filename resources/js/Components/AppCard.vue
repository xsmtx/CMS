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
 *
 * `flush` drops the body's padding, for the one thing that brings its own:
 * a table. A framed table inside a framed card is the card-in-a-card the
 * design system refuses, so the table is told to give up its frame
 * (`AppTable flush`) and the card is told to give up its padding — one
 * rectangle, with a header above the column names.
 */
import { type IconName } from '../icons'
import AppIcon from './AppIcon.vue'

withDefaults(
  defineProps<{
    title?: string
    description?: string
    as?: 'section' | 'article' | 'div'
    /** The body has no padding of its own. For a flush table. */
    flush?: boolean
    /**
     * A coloured rule along the top, and the glyph beside the title.
     *
     * The portal's reference (`whmcs-clientarea.png`) gives every panel one,
     * and it is not decoration: a customer's overview is eight panels of
     * unrelated things, and the colour is how they tell "you owe money" from
     * "here is some news" before reading either. The admin keeps its
     * hairline - an operator's screen has forty regions and forty colours
     * would be none.
     *
     * The tone is the status vocabulary's, so `danger` on a panel means what
     * `danger` means everywhere else in the product.
     */
    tone?: 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral'
    icon?: IconName
  }>(),
  {
    title: undefined,
    description: undefined,
    as: 'section',
    flush: false,
    tone: 'neutral',
    icon: undefined,
  },
)

/*
 * Written out rather than interpolated. Tailwind generates what it can see
 * in the source, so a `border-t-${tone}` is a class that exists only while
 * some other file happens to use it - which is a panel that loses its rule
 * the day that other file changes.
 */
const RULES: Record<string, string> = {
  brand: 'border-t-brand',
  success: 'border-t-success',
  warning: 'border-t-warning',
  danger: 'border-t-danger',
  info: 'border-t-info',
  neutral: 'border-t-line',
}

const GLYPHS: Record<string, string> = {
  brand: 'text-brand',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
  info: 'text-info',
  neutral: 'text-content-subtle',
}
</script>

<template>
  <component
    :is="as"
    class="border-line bg-surface-primary rounded-lg border border-t-2"
    :class="RULES[tone]"
  >
    <header
      v-if="title || description || $slots.actions"
      class="border-line flex items-start justify-between gap-4 border-b px-5 py-3.5"
    >
      <div class="flex min-w-0 items-start gap-2">
        <AppIcon
          v-if="icon"
          :name="icon"
          :size="16"
          class="mt-0.5 shrink-0"
          :class="GLYPHS[tone]"
        />
        <div class="min-w-0">
          <h2 v-if="title" class="text-title font-semibold">{{ title }}</h2>
          <p v-if="description" class="text-content-muted text-chrome mt-1 max-w-[70ch]">
            {{ description }}
          </p>
        </div>
      </div>
      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </header>

    <div :class="flush ? '' : 'px-5 py-4'">
      <slot />
    </div>
  </component>
</template>
