<script setup lang="ts">
/**
 * What is selected, and what can be done to it.
 *
 * It sits **above** the table rather than floating over the bottom of the
 * page: a floating bar covers the last rows of the list, which are the rows
 * somebody scrolled down to select. Above the table it pushes the list down
 * by 44px and covers nothing.
 *
 * The count is first and it is a number, not "some rows". A bulk action goes
 * wrong when the operator's idea of the selection and the platform's differ,
 * and the count is the only thing that can disagree out loud.
 *
 * "Clear" is always present and always last on the left, because the way out
 * of an accidental select-all has to be in the same place every time.
 */
import { useTranslations } from '../composables/useTranslations'

defineProps<{ count: number; noun: string; pluralNoun?: string }>()

const emit = defineEmits<{ clear: [] }>()

const { t } = useTranslations()
</script>

<template>
  <div
    class="border-brand/35 bg-brand/8 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border px-3 py-2"
    role="region"
    aria-label="Selection"
  >
    <p class="text-body font-medium" aria-live="polite">
      {{
        t(
          'ui.selection.selected',
          { count, noun: count === 1 ? noun : (pluralNoun ?? `${noun}s`) },
          `${count} ${count === 1 ? noun : (pluralNoun ?? `${noun}s`)} selected`,
        )
      }}
    </p>

    <button
      type="button"
      class="pressable text-content-muted hover:text-content text-chrome underline-offset-4 hover:underline"
      @click="emit('clear')"
    >
      {{ t('ui.common.clear', {}, 'Clear') }}
    </button>

    <!-- The actions belong to the screen: only it knows what they do, and
         which of them needs a confirmation before it happens. -->
    <div class="ml-auto flex flex-wrap items-center gap-2"><slot /></div>
  </div>
</template>
