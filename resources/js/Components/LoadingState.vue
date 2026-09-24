<script setup lang="ts">
/**
 * Something is on its way, for a region that is not a table.
 *
 * A table loads as `AppTableSkeleton` — its own shape, so nothing jumps when
 * rows land. Everything else (a drawer body, a chart, a panel of facts) uses
 * this: one line, left-aligned where the content will be, announced once.
 */
import { useTranslations } from '../composables/useTranslations'

defineProps<{ label?: string }>()

const { t } = useTranslations()
</script>

<template>
  <div role="status" class="text-content-muted text-body flex items-center gap-2 py-3">
    <span
      class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
      aria-hidden="true"
    />
    {{ label ?? t('ui.common.loading', {}, 'Loading…') }}
  </div>
</template>
