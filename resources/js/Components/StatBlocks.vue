<script setup lang="ts">
/**
 * The row of solid colour blocks across the top of the dashboard.
 *
 * This is the one thing an operator arriving from WHMCS looks for first, and
 * it is the shape `whmcs-admin-dashboard.png` opens with: a saturated tile per
 * figure, a darker square at its left carrying the glyph, the count large and
 * the noun small underneath it.
 *
 * It is deliberately **not** `MetricStrip`, which stays the restrained answer
 * for every other screen. A dashboard is the one page whose job is to be read
 * from across a room, and four colour blocks there do not license a fifth
 * somewhere else.
 *
 * **The colour is positional, not semantic.** These are counts, not statuses:
 * nothing about "50 tickets waiting" is a warning until somebody decides it
 * is, and colouring it amber would be this platform inventing an opinion. So
 * the tones cycle by position, which is what the reference does, and the one
 * status vocabulary keeps its meaning everywhere else.
 */
import { Link } from '@inertiajs/vue3'

import { type IconName } from '../icons'
import AppIcon from './AppIcon.vue'

interface Block {
  key: string
  label: string
  value: string
  href: string
  hint?: string
  icon?: IconName
}

defineProps<{ items: Block[] }>()

/**
 * Four tones, cycled. The reference uses green, magenta, orange and teal;
 * these are the same four hues from the palette this product already has, so
 * a brand that moves them moves these too.
 */
const TONES = [
  'bg-success text-content-inverse',
  'bg-automation text-content-inverse',
  'bg-warning text-content-inverse',
  'bg-infrastructure text-content-inverse',
]

const GLYPHS = [
  'servers',
  'billing',
  'support',
  'automation',
] as const satisfies readonly IconName[]

/**
 * Never `undefined`: the modulo keeps the lookup in range, which TypeScript
 * cannot see - so the fallback says it rather than an assertion hiding it.
 */
function glyph(item: Block, index: number): IconName {
  return item.icon ?? GLYPHS[index % GLYPHS.length] ?? 'dashboard'
}
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <Link
      v-for="(item, index) in items"
      :key="item.key"
      :href="item.href"
      class="pressable flex items-stretch overflow-hidden rounded-lg transition-opacity duration-(--duration-fast) hover:opacity-90"
      :class="TONES[index % TONES.length]"
    >
      <!-- The glyph square: the same colour a shade down, which is how the
           reference separates it rather than with a border. -->
      <span class="grid w-16 shrink-0 place-items-center bg-black/15" aria-hidden="true">
        <AppIcon :name="glyph(item, index)" :size="24" />
      </span>

      <span class="flex min-w-0 flex-col justify-center px-4 py-5">
        <span class="text-page font-semibold tabular-nums">{{ item.value }}</span>
        <span class="text-chrome truncate opacity-90">{{ item.label }}</span>
      </span>
    </Link>
  </div>
</template>
