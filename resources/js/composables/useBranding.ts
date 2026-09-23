import { usePage } from '@inertiajs/vue3'
import { computed, onMounted, watch } from 'vue'
import type { ComputedRef } from 'vue'

import type { BrandProps } from '../types/inertia'

/**
 * The brand on this page, and its colours applied to the document.
 *
 * The colours are written as **CSS custom properties on `:root`**, not as a
 * generated stylesheet. The design system is already built on those tokens,
 * so a brand overrides three of them and every button, focus ring, badge
 * and link follows — with no build step between an operator picking a
 * colour and seeing it.
 *
 * Written on mount and again whenever the brand changes, because an
 * operator moving between two resellers in one session would otherwise keep
 * the first one's colours until a full page load.
 */
export function useBranding(): { brand: ComputedRef<BrandProps> } {
  const page = usePage()

  const brand = computed(() => page.props.brand)

  function apply(values: Record<string, string>): void {
    const root = document.documentElement

    for (const [property, value] of Object.entries(values)) {
      root.style.setProperty(property, value)
    }
  }

  onMounted(() => apply(brand.value.css))

  watch(
    () => brand.value.css,
    (values) => apply(values),
  )

  return { brand }
}
