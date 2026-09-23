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

  /**
   * Who somebody is, not what they may do.
   *
   * Apps and Integrations is the only area gated this way: an Administrator
   * holds every staff permission by design, so no permission could describe
   * "super administrator only".
   */
  const isSuperAdmin = computed<boolean>(() => page.props.auth?.isSuperAdmin === true)

  return { permissions, can, canAny, isSuperAdmin }
}
