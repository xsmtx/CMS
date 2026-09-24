<script setup lang="ts">
import { computed } from 'vue'

import AppIcon from './AppIcon.vue'
import { type IconName } from '../icons'

/**
 * `warning` exists because "something will break later" is neither
 * information nor an error, and drawing it as one of those is how a warning
 * stops being read: as info it is ignored, as danger it is panicked over. A
 * licence inside its grace period is the worked example — everything works,
 * and somebody should look before it stops.
 */
const props = withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger' }>(), {
  tone: 'info',
})

// The tone is carried by the edge and the glyph; the sentence stays in the
// body colour. A whole paragraph in amber or red is hard to read, and the
// alert that matters most is the one somebody has to read to the end.
const classes = computed(
  () =>
    ({
      info: 'border-info/35',
      success: 'border-success/35',
      warning: 'border-warning/40',
      danger: 'border-danger/45',
    })[props.tone],
)

const icon = computed<{ name: IconName; colour: string }>(
  () =>
    ({
      info: { name: 'info' as const, colour: 'text-info' },
      success: { name: 'ok' as const, colour: 'text-success' },
      warning: { name: 'warning' as const, colour: 'text-warning' },
      danger: { name: 'warning' as const, colour: 'text-danger' },
    })[props.tone],
)

// A warning is announced politely, like info: `alert` interrupts whatever a
// screen reader was saying, and interrupting somebody to tell them a licence
// expires in three weeks is not proportionate.
const role = computed(() => (props.tone === 'danger' ? 'alert' : 'status'))
</script>

<template>
  <div
    :role="role"
    class="bg-surface-primary text-content text-body flex items-start gap-2.5 rounded-md border px-3.5 py-2.5"
    :class="classes"
  >
    <span class="mt-0.5" :class="icon.colour" aria-hidden="true">
      <AppIcon :name="icon.name" :size="16" />
    </span>
    <div class="min-w-0 flex-1"><slot /></div>
  </div>
</template>
