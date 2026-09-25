<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

import { useTranslations } from '../composables/useTranslations'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

defineProps<{ links: PaginationLink[]; total: number }>()

const { t } = useTranslations()

/**
 * Laravel ships previous/next labels containing HTML entities. Rendering
 * them with `v-html` to get an arrow is not worth an injection sink on every
 * paginated page, so they are mapped to plain words instead.
 */
function readable(label: string): string {
  if (label.includes('Previous')) return t('ui.pagination.previous', {}, 'Previous')
  if (label.includes('Next')) return t('ui.pagination.next', {}, 'Next')
  return label.replace(/&hellip;/g, '…')
}
</script>

<template>
  <nav
    v-if="links.length > 3"
    :aria-label="t('ui.shell.pagination', {}, 'Pagination')"
    class="mt-5 flex items-center justify-between gap-4"
  >
    <p class="text-content-muted text-chrome">
      {{ t('ui.pagination.results', { count: total }, `${total} result(s)`) }}
    </p>

    <ul class="flex flex-wrap items-center gap-1">
      <li v-for="link in links" :key="link.label">
        <Link
          v-if="link.url"
          :href="link.url"
          :aria-current="link.active ? 'page' : undefined"
          class="pressable border-line text-chrome block rounded-sm border px-2.5 py-1 transition-colors duration-(--duration-fast) ease-(--ease-out)"
          :class="
            link.active
              ? 'bg-surface-secondary text-content font-medium'
              : 'text-content-muted hover:bg-surface-secondary hover:text-content'
          "
        >
          {{ readable(link.label) }}
        </Link>
        <span v-else class="text-content-subtle text-chrome px-2.5 py-1">
          {{ readable(link.label) }}
        </span>
      </li>
    </ul>
  </nav>
</template>
