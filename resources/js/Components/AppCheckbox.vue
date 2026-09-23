<script setup lang="ts">
import { useId } from 'vue'

defineProps<{ label: string; description?: string; disabled?: boolean }>()

const model = defineModel<boolean>({ required: true })

const id = useId()
</script>

<template>
  <div class="flex items-start gap-3">
    <input
      :id="id"
      v-model="model"
      type="checkbox"
      :disabled="disabled"
      class="border-line-strong text-brand accent-brand mt-0.5 size-4 shrink-0 rounded-[4px] border disabled:opacity-60"
    />
    <label :for="id" class="text-sm leading-tight select-none">
      {{ label }}
      <span v-if="description" class="text-content-muted mt-0.5 block text-xs">{{
        description
      }}</span>
    </label>
  </div>
</template>
