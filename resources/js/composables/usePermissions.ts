import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Permission checks for the UI layer.
 *
 * These control what is *shown*. They are never the authorization decision:
 * every action is re-checked server side by the gate of the same name.
 */
export function usePermissions() {
  const page = usePage()

  const permissions = computed<string[]>(() => page.props.auth?.permissions ?? [])

  function can(permission: string): boolean {
    return permissions.value.includes(permission)
  }

  function canAny(...candidates: string[]): boolean {
    return candidates.some(can)
  }

  return { permissions, can, canAny }
}
