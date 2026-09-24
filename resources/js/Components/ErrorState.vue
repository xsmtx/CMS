<script setup lang="ts">
/**
 * A region that could not load — distinct from one that is empty.
 *
 * "No servers" and "the server list did not answer" must never look alike:
 * the first is a fact, the second is an incident, and an operator who reads
 * an outage as an empty list acts on it. So this is framed in the danger
 * edge, says what failed in words, carries the correlation ID when there is
 * one (that is what support asks for), and offers the retry through the
 * default slot.
 *
 * `permission` is the other failure: the region exists and this person may
 * not see it. That is neutral, not red — nothing is broken.
 */
import AppCopy from './AppCopy.vue'
import AppIcon from './AppIcon.vue'
import { useTranslations } from '../composables/useTranslations'

withDefaults(
  defineProps<{
    title: string
    description?: string
    correlationId?: string
    kind?: 'error' | 'permission'
  }>(),
  { description: undefined, correlationId: undefined, kind: 'error' },
)

const { t } = useTranslations()
</script>

<template>
  <div
    :role="kind === 'error' ? 'alert' : 'status'"
    class="bg-surface-primary flex items-start gap-3 rounded-lg border px-4 py-4"
    :class="kind === 'error' ? 'border-danger/40' : 'border-line'"
  >
    <span :class="kind === 'error' ? 'text-danger' : 'text-content-subtle'" aria-hidden="true">
      <AppIcon :name="kind === 'error' ? 'warning' : 'security'" :size="16" />
    </span>
    <div class="min-w-0">
      <p class="text-body font-medium">{{ title }}</p>
      <p v-if="description" class="text-content-muted text-body mt-0.5 max-w-[70ch]">
        {{ description }}
      </p>
      <p v-if="correlationId" class="text-content-muted text-chrome mt-2 flex items-center gap-2">
        {{ t('ui.common.correlation_id', {}, 'Correlation ID') }}
        <AppCopy :value="correlationId" />
      </p>
      <div v-if="$slots.default" class="mt-3 flex gap-2"><slot /></div>
    </div>
  </div>
</template>
