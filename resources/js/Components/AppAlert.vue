<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ tone?: 'info' | 'success' | 'danger' }>(), {
  tone: 'info',
})

const classes = computed(
  () =>
    ({
      info: 'border-line bg-surface-sunken text-content',
      success: 'border-success/30 bg-surface-sunken text-success',
      danger: 'border-danger/30 bg-surface-sunken text-danger',
    })[props.tone],
)

const role = computed(() => (props.tone === 'danger' ? 'alert' : 'status'))
</script>

<template>
  <div
    :role="role"
    class="rounded-[var(--radius-sm)] border px-3 py-2 text-sm leading-relaxed"
    :class="classes"
  >
    <slot />
  </div>
</template>
