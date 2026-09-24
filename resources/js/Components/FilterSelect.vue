<script setup lang="ts">
/**
 * A filter in a `FilterBar`: the label *inside* the control, as a prefix.
 *
 * `Status: Any ▾` is one control a row high, where `AppSelect` is a label
 * above a field — two rows. The label is a real `<label>` wrapping the
 * select, so it is still the accessible name and clicking the word opens
 * the list. Native `<select>`: it is keyboard- and screen-reader-complete
 * for free, which a hand-rolled listbox is not.
 *
 * A filter that is set gets the brand edge, so a list that is filtered
 * looks filtered — the commonest confusion on a list screen is "where did
 * my rows go".
 */
import { useId } from 'vue'

defineProps<{
  label: string
  options: { value: string; label: string }[]
}>()

const model = defineModel<string>({ required: true })

const id = useId()
</script>

<template>
  <label
    :for="id"
    class="bg-surface-primary text-body inline-flex h-(--control-h) max-w-full items-center rounded-md border pl-2.5 transition-colors duration-(--duration-fast) ease-(--ease-out) has-[select:focus-visible]:outline-2 has-[select:focus-visible]:outline-offset-2 has-[select:focus-visible]:outline-(--focus-ring)"
    :class="model !== '' ? 'border-brand/60' : 'border-line hover:border-line-strong'"
  >
    <span class="text-content-muted shrink-0 whitespace-nowrap">{{ label }}:</span>
    <select
      :id="id"
      v-model="model"
      class="text-content field-sizing-content h-full min-w-0 cursor-pointer bg-transparent pr-2 pl-1 font-medium focus-visible:outline-none"
    >
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>
  </label>
</template>
