<script setup lang="ts">
import { computed } from 'vue'

import AppInput from './AppInput.vue'
import AppSelect from './AppSelect.vue'
import AppTextarea from './AppTextarea.vue'

export interface CustomFieldDefinition {
  key: string
  label: string
  type: string
  options: string[] | null
  required: boolean
  helpText: string | null
  value: unknown
}

/**
 * Renders one operator-defined field.
 *
 * The indexing into the values record lives here rather than in every form
 * that has custom fields, which is what keeps those forms strongly typed
 * without a cast at each call site.
 */
const props = defineProps<{
  field: CustomFieldDefinition
  error?: string
}>()

// The whole record is the model, so the component can write back through it
// rather than mutating a prop.
const values = defineModel<Record<string, string>>({ required: true })

const value = computed({
  get: () => values.value[props.field.key] ?? '',
  set: (next: string) => {
    values.value = { ...values.value, [props.field.key]: next }
  },
})

const inputType = computed(() => {
  if (props.field.type === 'number') return 'number'
  if (props.field.type === 'date') return 'date'
  return 'text'
})

const selectOptions = computed(() =>
  (props.field.options ?? []).map((option) => ({ value: option, label: option })),
)
</script>

<template>
  <AppTextarea
    v-if="field.type === 'textarea'"
    v-model="value"
    :label="field.label"
    :hint="field.helpText ?? undefined"
    :error="error"
  />

  <AppSelect
    v-else-if="field.type === 'select'"
    v-model="value"
    :label="field.label"
    :options="selectOptions"
    :hint="field.helpText ?? undefined"
    :error="error"
  />

  <AppInput
    v-else
    v-model="value"
    :label="field.label"
    :type="inputType"
    :hint="field.helpText ?? undefined"
    :required="field.required"
    :error="error"
  />
</template>
