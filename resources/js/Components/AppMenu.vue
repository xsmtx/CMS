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

const props = withDefaults(
  defineProps<{
    label: string
    /** Which edge of the trigger the panel lines up with. */
    align?: 'start' | 'end'
    width?: string
  }>(),
  { align: 'end', width: '15rem' },
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
    ref="trigger"
    type="button"
    class="pressable text-content-muted hover:text-content inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2 py-1 text-sm"
    :aria-expanded="open"
    aria-haspopup="menu"
    @click="toggle"
  >
    {{ label }}
    <svg
      class="size-3 transition-transform duration-150"
      :class="open ? 'rotate-180' : ''"
      viewBox="0 0 12 12"
      fill="none"
      aria-hidden="true"
    >
      <path
        d="M3 4.5 6 7.5 9 4.5"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </svg>
  </button>

  <Teleport to="body">
    <Transition name="menu">
      <div
        v-if="open"
        ref="panel"
        role="menu"
        class="border-line bg-surface-raised z-50 rounded-[var(--radius-lg)] border p-1 text-left shadow-(--shadow-panel)"
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
