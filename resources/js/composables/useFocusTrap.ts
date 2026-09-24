import { type Ref } from 'vue'

/**
 * Keep Tab inside a modal surface.
 *
 * `aria-modal="true"` tells a screen reader the rest of the page is inert;
 * it does nothing for a keyboard. Without this, Tab from a dialog's last
 * button walks out into the page behind the scrim — onto controls the
 * operator cannot see, one of which may be the button that opened a
 * destructive confirmation in the first place.
 *
 * Returns a keydown handler: bind it with `@keydown="trap"` on the modal
 * element. Shift+Tab from the first focusable wraps to the last and Tab from
 * the last wraps to the first; anything else is left alone.
 */
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

export function useFocusTrap(container: Ref<HTMLElement | null>) {
  return function trap(event: KeyboardEvent): void {
    if (event.key !== 'Tab' || container.value === null) return

    const focusable = [...container.value.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
      (element) => !element.hasAttribute('inert') && element.getClientRects().length > 0,
    )

    if (focusable.length === 0) {
      event.preventDefault()
      container.value.focus()
      return
    }

    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    const current = document.activeElement

    if (event.shiftKey && (current === first || current === container.value)) {
      event.preventDefault()
      last?.focus()
    } else if (!event.shiftKey && current === last) {
      event.preventDefault()
      first?.focus()
    }
  }
}
