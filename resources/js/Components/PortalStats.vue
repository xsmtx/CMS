<script setup lang="ts">
/**
 * The count strip across the top of a customer's overview.
 *
 * `whmcs-clientarea.png` opens with four of these: the figure large, the noun
 * under it, a glyph to its right and a coloured rule along the bottom. It is
 * the shape a customer reads before anything else, and it answers the only
 * four questions they arrive with - how much am I running, what do I owe, is
 * anybody dealing with my ticket.
 *
 * **Not the admin's `StatBlocks`.** Those are solid colour tiles meant to be
 * read from across a room by somebody running the platform. A customer is
 * reading their own account on a laptop, and four saturated blocks there
 * would shout at them about their own three services. Same idea, quieter
 * register: white panel, one coloured rule.
 *
 * A figure with nothing behind it is still drawn. "0 domains" is an answer,
 * and a strip that changed shape depending on what somebody owned would be a
 * strip nobody could learn.
 */
import { Link } from '@inertiajs/vue3'

import { type IconName } from '../icons'
import AppIcon from './AppIcon.vue'

interface Stat {
  key: string
  label: string
  value: string
  href?: string
  icon: IconName
  tone?: 'brand' | 'success' | 'warning' | 'danger'
}

defineProps<{ items: Stat[] }>()

/** Written out, not interpolated: Tailwind generates what it can see. */
const RULES: Record<string, string> = {
  brand: 'border-b-brand',
  success: 'border-b-success',
  warning: 'border-b-warning',
  danger: 'border-b-danger',
}
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <component
      :is="item.href ? Link : 'div'"
      v-for="item in items"
      :key="item.key"
      :href="item.href"
      class="border-line bg-surface-primary flex items-center justify-between gap-3 rounded-lg border border-b-2 px-5 py-4"
      :class="[RULES[item.tone ?? 'brand'], item.href ? 'pressable hover:bg-surface-hover' : '']"
    >
      <span class="min-w-0">
        <span class="text-page block font-semibold tabular-nums">{{ item.value }}</span>
        <span class="text-content-muted text-chrome block truncate">{{ item.label }}</span>
      </span>

      <!-- Decorative: the noun beside it already says what the figure counts,
           and a screen reader repeating "services, services" is noise. -->
      <AppIcon :name="item.icon" :size="28" class="text-content-subtle shrink-0 opacity-40" />
    </component>
  </div>
</template>
