<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    options: { value: string; label: string }[]
    error?: string
    hint?: string
    disabled?: boolean
  }>(),
  { error: undefined, hint: undefined, disabled: false },
)

const model = defineModel<string>({ required: true })

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
    <label :for="id" class="text-body font-medium">{{ label }}</label>

    <select
      :id="id"
      v-model="model"
      :disabled="disabled"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="describedBy"
      class="border-line bg-surface-primary text-content text-body h-(--control-h) w-full rounded-md border px-3 transition-colors duration-(--duration-fast) ease-(--ease-out) disabled:opacity-60"
      :class="error ? 'border-danger' : 'focus:border-brand'"
    >
      <option v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </option>
    </select>

    <p v-if="hint && !error" :id="hintId" class="text-content-muted text-chrome">{{ hint }}</p>
    <p v-if="error" :id="errorId" class="text-danger text-chrome">{{ error }}</p>
  </div>
</template>
