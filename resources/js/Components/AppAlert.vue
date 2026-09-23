<script setup lang="ts">
import { computed } from 'vue'

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

const classes = computed(
  () =>
    ({
      info: 'border-line bg-surface-secondary text-content',
      success: 'border-success/30 bg-surface-secondary text-success',
      warning: 'border-warning/30 bg-surface-secondary text-warning',
      danger: 'border-danger/30 bg-surface-secondary text-danger',
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
    class="rounded-[var(--radius-md)] border px-4 py-3 text-sm leading-relaxed"
    :class="classes"
  >
    <slot />
  </div>
</template>
