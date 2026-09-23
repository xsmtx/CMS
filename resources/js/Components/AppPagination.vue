<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

defineProps<{ links: PaginationLink[]; total: number }>()

/**
 * Laravel ships previous/next labels containing HTML entities. Rendering
 * them with `v-html` to get an arrow is not worth an injection sink on every
 * paginated page, so they are mapped to plain words instead.
 */
function readable(label: string): string {
  if (label.includes('Previous')) return 'Previous'
  if (label.includes('Next')) return 'Next'
  return label.replace(/&hellip;/g, '…')
}
</script>

<template>
  <nav
    v-if="links.length > 3"
    aria-label="Pagination"
    class="mt-5 flex items-center justify-between gap-4"
  >
    <p class="text-content-muted text-xs">{{ total }} result(s)</p>

    <ul class="flex flex-wrap items-center gap-1">
      <li v-for="link in links" :key="link.label">
        <Link
          v-if="link.url"
          :href="link.url"
          :aria-current="link.active ? 'page' : undefined"
          class="pressable border-line block rounded-[var(--radius-sm)] border px-2.5 py-1 text-xs transition-colors duration-(--duration-fast) ease-(--ease-out)"
          :class="
            link.active
              ? 'bg-surface-secondary text-content font-medium'
              : 'text-content-muted hover:bg-surface-secondary hover:text-content'
          "
        >
          {{ readable(link.label) }}
        </Link>
        <span v-else class="text-content-subtle px-2.5 py-1 text-xs">
          {{ readable(link.label) }}
        </span>
      </li>
    </ul>
  </nav>
</template>
