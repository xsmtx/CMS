import { nextTick, onBeforeUnmount, ref, watch, type Ref } from 'vue'

/**
 * A panel that escapes whatever is clipping it.
 *
 * Every dropdown in this product has the same problem and it is worth
 * stating once: a panel positioned `absolute` inside a container with
 * `overflow` set is clipped by that container. A row menu opens *into* the
 * table and scrolls with it; a flyout in the sidebar opens *inside the bar*
 * and is cut off at 72px. Both were real bugs, found in that order, and both
 * are the same bug.
 *
 * So the panel is teleported to `<body>` and positioned `fixed` against the
 * trigger's own rectangle. `fixed` takes it out of every ancestor's overflow
 * and every ancestor's `transform`, which is the only placement that cannot
 * be clipped by something a caller did not know about.
 *
 * The cost is that a fixed panel does not move when something scrolls, so
 * this listens — in capture, because the scroll that matters is usually a
 * container's rather than the window's — and repositions.
 *
 * @param side Where the panel sits relative to the trigger. `bottom` for a
 *   dropdown, `right` for a sidebar flyout.
 * @param align Which edge the panel lines up with.
 */
export function useAnchoredPanel(options: {
  side?: 'bottom' | 'right'
  align?: 'start' | 'end'
  width?: string
  gap?: number
}) {
  const side = options.side ?? 'bottom'
  const align = options.align ?? 'start'
  const gap = options.gap ?? 4

  const open = ref(false)
  const trigger = ref<HTMLElement | null>(null)
  const panel = ref<HTMLElement | null>(null)
  const style = ref<Record<string, string>>({})

  function place(): void {
    const anchor = trigger.value

    if (anchor === null) return

    const rect = anchor.getBoundingClientRect()
    const height = panel.value?.offsetHeight ?? 0
    const width = panel.value?.offsetWidth ?? 0

    if (side === 'right') {
      // A sidebar flyout: beside the row, and nudged up only far enough to
      // fit. Aligning it to the row's top is what makes it read as
      // belonging to that row rather than to the bar.
      const top = Math.min(rect.top, Math.max(8, window.innerHeight - height - 8))

      style.value = {
        position: 'fixed',
        top: `${Math.max(8, top)}px`,
        left: `${rect.right + gap}px`,
        transformOrigin: 'left top',
        ...(options.width === undefined ? {} : { width: options.width }),
      }

      return
    }

    const below = window.innerHeight - rect.bottom
    // Flip up only when the panel genuinely does not fit below, so a menu
    // near the bottom of a long list still opens in the usual direction.
    const flip = height > 0 && below < height + gap && rect.top > below

    const left = align === 'end' ? rect.right - width : rect.left
    const clamped = Math.min(Math.max(8, left), Math.max(8, window.innerWidth - width - 8))

    style.value = {
      position: 'fixed',
      top: flip ? `${rect.top - height - gap}px` : `${rect.bottom + gap}px`,
      left: `${clamped}px`,
      // The panel scales out of the corner it is anchored to rather than out
      // of its own middle, which is what makes it read as belonging to the
      // thing that opened it.
      transformOrigin: `${align === 'end' ? 'right' : 'left'} ${flip ? 'bottom' : 'top'}`,
      ...(options.width === undefined ? {} : { width: options.width }),
    }
  }

  function onDocumentPointerDown(event: MouseEvent): void {
    const target = event.target

    if (!(target instanceof Node)) return
    if (trigger.value?.contains(target) === true) return
    if (panel.value?.contains(target) === true) return

    open.value = false
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      open.value = false
      trigger.value?.focus()
    }
  }

  function detach(): void {
    document.removeEventListener('mousedown', onDocumentPointerDown)
    document.removeEventListener('keydown', onKeydown)
    window.removeEventListener('scroll', place, true)
    window.removeEventListener('resize', place)
  }

  watch(open, async (isOpen) => {
    if (!isOpen) {
      detach()

      return
    }

    // Twice: once to place it before it is painted, once after the panel has
    // a measured height so the flip decision is made on real numbers.
    place()
    await nextTick()
    place()

    document.addEventListener('mousedown', onDocumentPointerDown)
    document.addEventListener('keydown', onKeydown)
    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)
  })

  onBeforeUnmount(detach)

  return { open, trigger, panel, style, place } as {
    open: Ref<boolean>
    trigger: Ref<HTMLElement | null>
    panel: Ref<HTMLElement | null>
    style: Ref<Record<string, string>>
    place: () => void
  }
}
