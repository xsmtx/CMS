<script setup lang="ts">
/**
 * The language somebody reads the panel in.
 *
 * Beside the theme switch, because they are the same kind of decision — how
 * this looks to me — and because a preference buried in a settings screen is
 * a preference nobody finds. The difference is where it is kept: the theme
 * is a per-viewer convenience in `localStorage`, and the language is on the
 * person's own row, so it follows them to another machine and is the
 * language their emails already arrive in.
 *
 * The write is followed by a **full document reload**, not an Inertia visit.
 * Half the words on a page come back from the server with the new language
 * and the other half do not: `useTranslations()` reads a JSON block rendered
 * into the document, and Inertia replaces the page component without
 * touching the document around it. Switching without reloading left a
 * Turkish heading over an English table — the exact half-translated screen
 * all of this exists to prevent.
 *
 * Drawn only when there is a choice to make: an installation shipping one
 * language gets no control at all.
 */
import { router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppSegmented from './AppSegmented.vue'
import { useTranslations } from '../composables/useTranslations'

const props = defineProps<{ url: string }>()

const page = usePage()
const { t } = useTranslations()

const locales = computed(() => page.props.locales ?? [])
const current = computed({
  get: () => page.props.locale,
  set: (locale: string) => {
    if (locale === page.props.locale) return

    router.put(
      props.url,
      { locale },
      { preserveScroll: true, onSuccess: () => window.location.reload() },
    )
  },
})

const segments = computed(() =>
  locales.value.map((locale) => ({ value: locale.value, label: locale.label })),
)
</script>

<template>
  <AppSegmented
    v-if="segments.length > 1"
    v-model="current"
    :segments="segments"
    :label="t('ui.shell.language', {}, 'Language')"
  />
</template>
