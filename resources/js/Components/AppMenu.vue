<script setup lang="ts">
/**
 * A row action menu that is not trapped inside the table.
 *
 * A panel positioned `absolute` inside a cell is clipped by the table's
 * own `overflow-x-auto` wrapper — the menu opens *into* the table, scrolls
 * with it, and on a short list is cut off entirely. Every dropdown in a
 * list has this problem and every one of them has to solve it the same
 * way, so it is solved once here.
 *
 * The panel is teleported to `<body>` and positioned `fixed` against the
 * trigger's own rectangle, which takes it out of every ancestor's overflow
 * and every ancestor's stacking context. It flips above the trigger when
 * there is no room below, and is clamped to the viewport rather than
 * disappearing off the right edge on a narrow window.
 *
 * Closing is deliberately generous: a click anywhere else, Escape, a
 * resize, or a scroll of any container. A menu that stays open over the
 * next row is how an operator resets the wrong person's password.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

import AppIcon from './AppIcon.vue'
import { type IconName } from '../icons'

const props = withDefaults(
  defineProps<{
    label: string
    /** Which edge of the trigger the panel lines up with. */
    align?: 'start' | 'end'
    width?: string
    /**
     * Draw the label as a round placeholder face instead of a word. Used
     * by the account menu, where an email read across the top of every
     * page is somebody's identifier on a screen other people walk past.
     */
    avatar?: boolean
    /**
     * Draw the trigger as an icon button. The label stays, as the
     * accessible name — an icon with no name is a button screen readers
     * announce as "button".
     */
    icon?: IconName | null
  }>(),
  { align: 'end', width: '15rem', avatar: false, icon: null },
)

const open = ref(false)
const trigger = ref<HTMLButtonElement | null>(null)
const panel = ref<HTMLDivElement | null>(null)
const style = ref<Record<string, string>>({})

const GAP = 4

function place(): void {
  const button = trigger.value
  const box = panel.value

  if (button === null) return

  const rect = button.getBoundingClientRect()
  const height = box?.offsetHeight ?? 0
  const width = box?.offsetWidth ?? 0

  const below = window.innerHeight - rect.bottom
  // Flip up only when the panel genuinely does not fit below, so a menu
  // near the bottom of a long list still opens in the usual direction.
  const flip = height > 0 && below < height + GAP && rect.top > below

  const left = props.align === 'end' ? rect.right - width : rect.left
  const clamped = Math.min(Math.max(8, left), Math.max(8, window.innerWidth - width - 8))

  style.value = {
    position: 'fixed',
    top: flip ? `${rect.top - height - GAP}px` : `${rect.bottom + GAP}px`,
    left: `${clamped}px`,
    width: props.width,
    // The panel scales out of the corner it is anchored to rather than
    // out of its own middle, which is what makes it read as belonging to
    // the button that opened it.
    transformOrigin: `${props.align === 'end' ? 'right' : 'left'} ${flip ? 'bottom' : 'top'}`,
  }
}

function toggle(): void {
  open.value = !open.value
}

function close(): void {
  open.value = false
}

function onDocumentPointerDown(event: MouseEvent): void {
  const target = event.target

  if (!(target instanceof Node)) return
  if (trigger.value?.contains(target) === true) return
  if (panel.value?.contains(target) === true) return

  close()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
    trigger.value?.focus()
  }
}

watch(open, async (isOpen) => {
  if (isOpen) {
    // Twice: once to place it before it is painted, once after the panel
    // has a measured height so the flip decision is made on real numbers.
    place()
    await nextTick()
    place()

    document.addEventListener('mousedown', onDocumentPointerDown)
    document.addEventListener('keydown', onKeydown)
    // Capture, because the scroll that matters is usually a container's
    // rather than the window's.
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)

    return
  }

  document.removeEventListener('mousedown', onDocumentPointerDown)
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentPointerDown)
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})

defineExpose({ close })
</script>

<template>
  <button
    v-if="icon"
    ref="trigger"
    type="button"
    class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] p-1.5 transition-colors duration-(--duration-fast)"
    :aria-expanded="open"
    aria-haspopup="menu"
    :aria-label="label"
    @click="toggle"
  >
    <AppIcon :name="icon ?? 'more'" :size="17" />
  </button>

  <button
    v-else-if="avatar"
    ref="trigger"
    type="button"
    class="pressable bg-surface-secondary text-content-muted hover:text-content border-line hover:border-line-strong inline-flex size-8 items-center justify-center rounded-full border text-xs font-semibold transition-colors duration-(--duration-fast)"
    :aria-expanded="open"
    aria-haspopup="menu"
    aria-label="Account"
    @click="toggle"
  >
    {{ label }}
  </button>

  <button
    v-else
    ref="trigger"
    type="button"
    class="pressable text-content-muted hover:text-content inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2 py-1 text-sm"
    :aria-expanded="open"
    aria-haspopup="menu"
    @click="toggle"
  >
    {{ label }}
    <span
      class="text-content-subtle transition-transform duration-150"
      :class="open ? 'rotate-180' : ''"
    >
      <AppIcon name="chevronDown" :size="12" />
    </span>
  </button>

  <Teleport to="body">
    <Transition name="menu">
      <div
        v-if="open"
        ref="panel"
        role="menu"
        class="panel-enter border-line bg-surface-primary z-50 origin-top-right rounded-[var(--radius-lg)] border p-1 text-left shadow-(--shadow-panel)"
        :style="style"
      >
        <slot :close="close" />
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.menu-enter-active {
  transition:
    opacity 150ms cubic-bezier(0.23, 1, 0.32, 1),
    transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
}

.menu-leave-active {
  transition:
    opacity 100ms cubic-bezier(0.23, 1, 0.32, 1),
    transform 100ms cubic-bezier(0.23, 1, 0.32, 1);
}

/* Never from scale(0): nothing in the world appears out of nothing. */
.menu-enter-from,
.menu-leave-to {
  opacity: 0;
  transform: scale(0.97);
}

@media (prefers-reduced-motion: reduce) {
  .menu-enter-active,
  .menu-leave-active {
    transition-duration: 1ms;
  }
}
</style>
