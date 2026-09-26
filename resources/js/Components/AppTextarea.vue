<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    error?: string
    hint?: string
    rows?: number
    /**
     * For a field whose content is code: a device configuration, a template.
     *
     * A prop rather than a `class="font-mono"` at the call site, because a
     * class on this component falls through to the **wrapper** and takes the
     * label and the hint with it — which is how the device-change form came
     * to ask for "The configuration it should have" in monospace. The same
     * cascade trap as a utility fighting a primitive's own class: wrap, or
     * give the primitive the prop.
     */
    mono?: boolean
  }>(),
  {
    error: undefined,
    hint: undefined,
    rows: 4,
    mono: false,
  },
)

const model = defineModel<string>({ required: true })

const id = useId()

// The hint and the error are tied to the field the same way `AppInput` ties
// them: an error that is only red text under a box is an error a screen
// reader never says.
const describedBy = computed(() => {
  const ids = []
  if (props.hint && !props.error) ids.push(`${id}-hint`)
  if (props.error) ids.push(`${id}-error`)
  return ids.length > 0 ? ids.join(' ') : undefined
})
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-body font-medium">{{ label }}</label>

    <textarea
      :id="id"
      v-model="model"
      :rows="rows"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="describedBy"
      class="bg-surface-primary text-content placeholder:text-content-subtle text-body w-full rounded-md border px-3 py-2 transition-colors duration-(--duration-fast) ease-(--ease-out)"
      :class="[error ? 'border-danger' : 'border-line focus:border-brand', mono ? 'font-mono' : '']"
    />

    <p v-if="hint && !error" :id="`${id}-hint`" class="text-content-muted text-chrome">
      {{ hint }}
    </p>
    <p v-if="error" :id="`${id}-error`" class="text-danger text-chrome">{{ error }}</p>
  </div>
</template>
