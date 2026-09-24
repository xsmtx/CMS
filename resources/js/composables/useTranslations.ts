/**
 * The same strings the server renders, in the browser.
 *
 * Every user-facing string in this product lives in `lang/en` and `lang/tr`,
 * including the ones a Vue page draws. The document carries the current
 * locale's groups in a `<script type="application/json">` tag, which is read
 * once here and shared by every component.
 *
 * `t()` returns the key when a string is missing rather than an empty span.
 * A visible `billing.portal.outstanding` in the interface is a bug report
 * from the page itself; blank space is a bug nobody files.
 */
type Messages = Record<string, unknown>

let messages: Messages | null = null

function load(): Messages {
  if (messages !== null) {
    return messages
  }

  const element = document.getElementById('translations')

  if (element === null) {
    messages = {}

    return messages
  }

  try {
    messages = JSON.parse(element.textContent ?? '{}') as Messages
  } catch {
    // A malformed payload is not worth taking the page down for; every
    // key will render as itself, which is loud enough.
    messages = {}
  }

  return messages
}

function lookup(key: string): string | null {
  let current: unknown = load()

  for (const segment of key.split('.')) {
    if (typeof current !== 'object' || current === null) {
      return null
    }

    current = (current as Record<string, unknown>)[segment]
  }

  return typeof current === 'string' ? current : null
}

export function useTranslations(): {
  t: (key: string, replacements?: Record<string, string | number>, fallback?: string) => string
} {
  /**
   * `fallback` is for primitives only: a shared component can be mounted
   * where no translations were rendered (a unit test, a module's own page),
   * and there it should say "Choose columns", not `ui.common.choose_columns`.
   * A page never passes one — a missing key on a page is a bug to see.
   */
  function t(
    key: string,
    replacements: Record<string, string | number> = {},
    fallback?: string,
  ): string {
    let value = lookup(key) ?? fallback ?? key

    for (const [name, replacement] of Object.entries(replacements)) {
      value = value.replaceAll(`:${name}`, String(replacement))
    }

    return value
  }

  return { t }
}
