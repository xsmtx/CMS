export interface AuthUser {
  id: string
  name: string
  email: string
}

export interface AuthProps {
  user: AuthUser | null
  permissions: string[]
  /** Who somebody is, not what they may do. See `usePermissions`. */
  isSuperAdmin?: boolean
}

/** A menu row an enabled module contributed. */
export interface ModuleNavItem {
  label: string
  href: string
  permission?: string
}

export interface BrandLink {
  label: string
  url: string
}

/**
 * Whose name is on this page. Resolved per request from the organization
 * the viewer belongs to, never from a build-time constant — a reseller and
 * the provider behind it share one deployment.
 *
 * `css` is the design tokens a brand overrides. Applied to the document
 * root, so everything already built on those tokens follows without a
 * stylesheet being regenerated.
 */
export interface BrandProps {
  name: string
  legalName: string | null
  portalName: string
  supportEmail: string | null
  supportPhone: string | null
  websiteUrl: string | null
  logoUrl: string | null
  logoDarkUrl: string | null
  faviconUrl: string | null
  legalLinks: BrandLink[]
  hideVendorMark: boolean
  css: Record<string, string>
}

export interface ImpersonationProps {
  active: boolean
  subjectName: string | null
  impersonatorName: string | null
}

export interface FlashProps {
  success: string | null
  error: string | null
  status: string | null
  /**
   * Two-factor recovery codes, flashed once after enrolment or
   * regeneration. They are never persisted and never sent again.
   */
  recoveryCodes?: string[] | null
  /**
   * A service's provider credentials, flashed once when an operator asks
   * for them. Never persisted and never sent again.
   */
  credentials?: { username: string | null; password: string | null } | null
}

/**
 * Props shared with every Inertia page by HandleInertiaRequests.
 *
 * Keep this in step with that middleware: it is the contract between the
 * server and the first-party front end.
 */
declare module '@inertiajs/core' {
  interface PageProps {
    auth: AuthProps
    brand: BrandProps
    moduleNavigation?: ModuleNavItem[]
    help?: Record<string, string>
    impersonation: ImpersonationProps | null
    locale: string
    flash: FlashProps
    correlationId: string | null
  }
}
