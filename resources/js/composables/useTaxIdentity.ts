import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import type { ComputedRef } from 'vue'

import type { TaxIdentityProps } from '../types/inertia'

/**
 * What this seller calls a tax id, and whether a business must give one.
 *
 * Shared with every page rather than passed to each form, because five forms ask
 * for the field — checkout, the client's billing details, their profile, and both
 * admin customer forms — and a label that is right on four of them is a label
 * somebody will trust on the fifth.
 *
 * "VAT number" is wrong in most of the world: it is Vergi Numarası in Turkey, an
 * ABN in Australia, a GSTIN in India. The server falls back to a neutral "Tax ID"
 * rather than to a European word, so an unconfigured installation is merely
 * generic rather than confidently wrong.
 */
export function useTaxIdentity(): {
  label: ComputedRef<string>
  requiredForBusiness: ComputedRef<boolean>
} {
  const page = usePage()

  const identity = computed<TaxIdentityProps>(
    () => page.props.taxIdentity ?? { label: 'Tax ID', requiredForBusiness: false },
  )

  return {
    label: computed(() => identity.value.label),
    requiredForBusiness: computed(() => identity.value.requiredForBusiness),
  }
}
