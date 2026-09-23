<script setup lang="ts">
/**
 * A value you can take with you: an id, an IP, a correlation id, a hostname.
 *
 * §7 asks for these to be easy to copy, and the reason is specific — an
 * operator on the phone to a customer, or pasting a correlation id into a
 * ticket, selects a monospace string by hand and gets the whitespace with
 * it. One press instead.
 *
 * The confirmation is the button's own label changing, and it changes back.
 * A toast for something this small is a notification about a click.
 *
 * `navigator.clipboard` needs a secure context, so it is absent over plain
 * HTTP on a staging box. The button disappears rather than failing silently
 * when pressed — the value is still selectable, which is what it was before.
 */
import { computed, onBeforeUnmount, ref } from 'vue'

import AppIcon from './AppIcon.vue'

const props = withDefaults(
  defineProps<{
    value: string
    /** Shown instead of the value. A truncated id, usually. */
    label?: string
    /** What it is, for the button's accessible name. */
    noun?: string
    mono?: boolean
  }>(),
  { label: undefined, noun: 'value', mono: true },
)

const copied = ref(false)

let timer: ReturnType<typeof setTimeout> | null = null

const supported = computed(
  () => typeof navigator !== 'undefined' && navigator.clipboard !== undefined,
)

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.value)
  } catch {
    // Denied by the browser, or no permission. Saying nothing is right:
    // the operator can still select the text, which is where they started.
    return
  }

  copied.value = true

  if (timer !== null) clearTimeout(timer)

  timer = setTimeout(() => {
    copied.value = false
  }, 1400)
}

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <span class="inline-flex items-center gap-1">
    <span :class="mono ? 'font-mono text-xs' : ''">{{ label ?? value }}</span>

    <button
      v-if="supported"
      type="button"
      class="pressable text-content-subtle hover:text-content rounded-[4px] p-0.5 transition-colors duration-(--duration-fast)"
      :aria-label="copied ? `${noun} copied` : `Copy ${noun}`"
      :title="copied ? 'Copied' : 'Copy'"
      @click="copy"
    >
      <AppIcon :name="copied ? 'ok' : 'copy'" :size="12" />
    </button>
  </span>
</template>
