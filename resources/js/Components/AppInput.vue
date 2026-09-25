<script setup lang="ts">
import { computed, useId } from 'vue'

/**
 * Text input with its label above and its error below.
 *
 * Never a placeholder as a label: the placeholder disappears the moment
 * someone types, taking the only description of the field with it.
 */
const props = withDefaults(
  defineProps<{
    label: string
    type?: string
    error?: string
    hint?: string
    autocomplete?: string
    /**
     * The keyboard a phone offers. A six-digit code typed on a letter
     * keyboard is a code typed twice.
     */
    inputmode?: 'text' | 'numeric' | 'tel' | 'email'
    required?: boolean
    disabled?: boolean
    placeholder?: string
  }>(),
  {
    type: 'text',
    error: undefined,
    hint: undefined,
    autocomplete: undefined,
    inputmode: undefined,
    required: false,
    disabled: false,
    placeholder: undefined,
  },
)

// A number input genuinely holds a number, and a form that binds one
// should not have to stringify it on the way in and parse it on the way
// out.
const model = defineModel<string | number>({ required: true })

const id = useId()
const hintId = computed(() => `${id}-hint`)
const errorId = computed(() => `${id}-error`)

const describedBy = computed(() => {
  const ids = []
  if (props.hint) ids.push(hintId.value)
  if (props.error) ids.push(errorId.value)
  return ids.length > 0 ? ids.join(' ') : undefined
})
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-body font-medium">
      {{ label }}
      <span v-if="required" class="text-content-subtle" aria-hidden="true">*</span>
    </label>

    <input
      :id="id"
      v-model="model"
      :type="type"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :required="required"
      :disabled="disabled"
      :placeholder="placeholder"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="describedBy"
      class="bg-surface-primary text-content placeholder:text-content-subtle text-body h-(--control-h) w-full rounded-md border px-3 transition-colors duration-(--duration-fast) ease-(--ease-out) disabled:opacity-60"
      :class="error ? 'border-danger' : 'border-line focus:border-brand'"
    />

    <p v-if="hint && !error" :id="hintId" class="text-content-muted text-chrome">{{ hint }}</p>
    <p v-if="error" :id="errorId" class="text-danger text-chrome">{{ error }}</p>
  </div>
</template>
