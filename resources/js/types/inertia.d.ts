export interface AuthUser {
  id: string
  name: string
  email: string
}

export interface AuthProps {
  user: AuthUser | null
  permissions: string[]
}

export interface BrandProps {
  name: string
}

export interface FlashProps {
  success: string | null
  error: string | null
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
    locale: string
    flash: FlashProps
    correlationId: string | null
  }
}
