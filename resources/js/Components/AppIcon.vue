<script setup lang="ts">
/**
 * The one place an icon is drawn.
 *
 * A control panel without iconography reads as a prototype. A row of
 * fourteen text links gives an operator nothing to aim at, and after a week
 * they are still reading labels instead of recognising shapes.
 *
 * The set lives in `../icons.ts` — a screen asks for a *concept*
 * (`clients`, `billing`) rather than for a drawing, so changing the drawing
 * later is one line. This component exists so the weight, the size default
 * and the accessibility decision are made once rather than forty times.
 */
import { computed, type Component } from 'vue'

import { ICONS, type IconName } from '../icons'

const props = withDefaults(
  defineProps<{
    name: IconName
    /**
     * In pixels, because an icon is measured against the cap height of the
     * text beside it rather than against a rem scale. 15 sits with body
     * text, 13 with chrome, 18 with a title.
     */
    size?: number
    /**
     * An icon that is the *only* content of a control needs a name; one
     * beside a label must not have one, or a screen reader says it twice.
     */
    label?: string
  }>(),
  { size: 16, label: undefined },
)

const component = computed<Component>(() => ICONS[props.name])
</script>

<template>
  <component
    :is="component"
    :size="props.size"
    weight="regular"
    :aria-hidden="props.label === undefined ? 'true' : undefined"
    :aria-label="props.label"
    :role="props.label === undefined ? undefined : 'img'"
    class="shrink-0"
  />
</template>
