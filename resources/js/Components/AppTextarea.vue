<script setup lang="ts">
import { useId } from 'vue'

withDefaults(defineProps<{ label: string; error?: string; hint?: string; rows?: number }>(), {
  error: undefined,
  hint: undefined,
  rows: 4,
})

const model = defineModel<string>({ required: true })

const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium">{{ label }}</label>

    <textarea
      :id="id"
      v-model="model"
      :rows="rows"
      :aria-invalid="error ? true : undefined"
      class="border-line bg-surface-raised text-content placeholder:text-content-subtle w-full rounded-[var(--radius-sm)] border px-3.5 py-2.5 text-sm transition-colors duration-(--duration-fast) ease-(--ease-out)"
      :class="error ? 'border-danger' : 'focus:border-accent'"
    />

    <p v-if="hint && !error" class="text-content-muted text-xs">{{ hint }}</p>
    <p v-if="error" class="text-danger text-xs">{{ error }}</p>
  </div>
</template>
