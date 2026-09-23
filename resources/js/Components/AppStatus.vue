<script setup lang="ts">
/**
 * An operational status, said three ways at once.
 *
 * Handoff #3 §7: **status always uses icon/shape + text + colour, never
 * colour alone.** That is not a preference. Roughly one man in twelve cannot
 * tell this product's green from its amber, and a hosting panel is read on
 * projectors, through remote-desktop sessions that crush colour, and by
 * people who are tired at three in the morning. A green dot beside an amber
 * dot is one bit of information for most readers and none for the rest.
 *
 * So each status carries a **shape**: `●` healthy, `▲` warning, `■` critical,
 * `◆` maintenance, `○` unknown. Shape survives greyscale, a bad projector
 * and a photocopy of a screenshot in a post-mortem.
 *
 * **Maintenance and unknown are statuses, not shades of warning.** "We took
 * it down on purpose" and "the check did not answer" are the two things an
 * operator most needs to tell apart from "it is broken". Every panel that
 * coloured them amber taught its users to ignore amber.
 */
import { computed } from 'vue'

export type StatusTone = 'healthy' | 'warning' | 'critical' | 'maintenance' | 'unknown' | 'info'

const props = withDefaults(
  defineProps<{
    tone: StatusTone
    label: string
    /**
     * Just the mark, for a dense table where the column header already says
     * what it is. The label still reaches a screen reader.
     */
    compact?: boolean
  }>(),
  { compact: false },
)

const SHAPES: Record<StatusTone, string> = {
  healthy: '●',
  warning: '▲',
  critical: '■',
  maintenance: '◆',
  unknown: '○',
  info: '●',
}

const COLOURS: Record<StatusTone, string> = {
  healthy: 'text-success',
  warning: 'text-warning',
  critical: 'text-danger',
  maintenance: 'text-maintenance',
  unknown: 'text-unknown',
  info: 'text-info',
}

const shape = computed(() => SHAPES[props.tone])
const colour = computed(() => COLOURS[props.tone])
</script>

<template>
  <span class="text-body inline-flex items-center gap-1.5 whitespace-nowrap">
    <!-- The mark is aria-hidden and the text carries the meaning, so a
         screen reader hears "Healthy" once rather than "black circle
         Healthy". -->
    <span :class="colour" class="text-[0.7em] leading-none" aria-hidden="true">{{ shape }}</span>
    <span v-if="!compact">{{ label }}</span>
    <span v-else class="sr-only">{{ label }}</span>
  </span>
</template>
