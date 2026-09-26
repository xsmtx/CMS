import { computed, onMounted, ref } from 'vue'

import { useTranslations } from './useTranslations'

/**
 * Light, dark, or whatever the operating system says.
 *
 * Every colour in the product is already a semantic token, and the token
 * layer defines a dark set both for `prefers-color-scheme` and for an
 * explicit `data-theme`. All this does is set the attribute, which is why a
 * theme switch costs nothing: nothing else in the interface knows it
 * happened.
 *
 * The choice is a per-browser convenience, so `localStorage` is the right
 * home for it — and every access is guarded, because it throws in a private
 * window with site data blocked.
 */
export type ThemeChoice = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'infracms.theme'

/** Read by `app.blade.php` before the first paint. */
const COOKIE_KEY = 'infracms_theme'

const choice = ref<ThemeChoice>('system')

function isChoice(value: unknown): value is ThemeChoice {
  return value === 'system' || value === 'light' || value === 'dark'
}

function read(): ThemeChoice {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)

    return isChoice(stored) ? stored : 'system'
  } catch {
    return 'system'
  }
}

function apply(value: ThemeChoice): void {
  const root = document.documentElement

  if (value === 'system') {
    root.removeAttribute('data-theme')
  } else {
    root.dataset.theme = value
  }
}

export function useTheme() {
  onMounted(() => {
    choice.value = read()
    apply(choice.value)
  })

  function set(value: ThemeChoice): void {
    choice.value = value
    apply(value)

    try {
      localStorage.setItem(STORAGE_KEY, value)
    } catch {
      // A browser that refuses to remember it still honours it for this
      // page, which is better than refusing to switch at all.
    }

    /*
     * And a cookie, which is the half the server can read.
     *
     * The document used to apply the theme from an inline script so that a
     * dark-theme operator never saw a white flash. It never ran: this
     * product's CSP is `script-src 'self'` with no exceptions, so the
     * browser refused it on every load and the flash happened anyway - for
     * as long as both have existed, with only the console saying so.
     *
     * A cookie needs no script and no exception. `SameSite=Lax` because it
     * is a display preference, not a credential.
     */
    try {
      document.cookie = `${COOKIE_KEY}=${value}; path=/; max-age=31536000; samesite=lax`
    } catch {
      // Same as above: the page still switched.
    }
  }

  const { t } = useTranslations()

  const options = computed<{ value: ThemeChoice; label: string }[]>(() => [
    { value: 'system', label: t('ui.theme.system', {}, 'System') },
    { value: 'light', label: t('ui.theme.light', {}, 'Light') },
    { value: 'dark', label: t('ui.theme.dark', {}, 'Dark') },
  ])

  return { choice, set, options }
}
