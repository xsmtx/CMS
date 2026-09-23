<script setup lang="ts">
/**
 * One module's settings form.
 *
 * Its own component because it owns its own form state. The page used to
 * keep a record of forms keyed by slug, which meant every read was
 * `Form | undefined` and, worse, one module's draft lived in the same
 * object graph as another's — including its secrets.
 */
import { useForm } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'

export interface ConfigFieldProps {
  key: string
  label: string
  type: string
  required: boolean
  hint: string | null
  options: { value: string; label: string }[]
  secret: boolean
  value: string | number | boolean | null
  isSet: boolean
}

const props = defineProps<{ slug: string; fields: ConfigFieldProps[] }>()

/**
 * A secret starts blank and means "leave it alone" when it is still blank
 * on save. The server never sends one back, so the form has nothing to
 * echo — and an operator editing the endpoint of a gateway must not clear
 * its API key by saving the form.
 */
const form = useForm<{ config: Record<string, string | number | boolean> }>({
  config: Object.fromEntries(
    props.fields.map((field) => [
      field.key,
      field.secret ? '' : (field.value ?? (field.type === 'boolean' ? false : '')),
    ]),
  ),
})

function submit(): void {
  form.put(`/admin/apps/modules/${props.slug}/config`, { preserveScroll: true })
}

function text(key: string): string {
  return String(form.config[key] ?? '')
}

function setText(key: string, value: string): void {
  form.config[key] = value
}

function flag(key: string): boolean {
  return form.config[key] === true
}

function setFlag(key: string, value: boolean): void {
  form.config[key] = value
}

function hintFor(field: ConfigFieldProps): string | undefined {
  if (!field.secret) return field.hint ?? undefined

  return field.isSet ? 'Set. Leave blank to keep it.' : 'Not set.'
}
</script>

<template>
  <form class="border-line mt-5 border-t pt-5" @submit.prevent="submit">
    <p class="mb-3 text-sm font-medium">Settings</p>

    <div class="grid max-w-xl gap-5">
      <template v-for="field in fields" :key="field.key">
        <AppCheckbox
          v-if="field.type === 'boolean'"
          :model-value="flag(field.key)"
          :label="field.label"
          :description="field.hint ?? undefined"
          @update:model-value="(value: boolean) => setFlag(field.key, value)"
        />
        <AppSelect
          v-else-if="field.type === 'select'"
          :model-value="text(field.key)"
          :label="field.label"
          :options="field.options"
          :hint="field.hint ?? undefined"
          @update:model-value="(value: string) => setText(field.key, value)"
        />
        <AppInput
          v-else
          :model-value="text(field.key)"
          :label="field.label"
          :type="field.secret ? 'password' : 'text'"
          :hint="hintFor(field)"
          @update:model-value="(value: string | number) => setText(field.key, String(value))"
        />
      </template>
    </div>

    <div class="mt-5">
      <AppButton type="submit" size="sm" variant="primary" :loading="form.processing">
        Save settings
      </AppButton>
    </div>
  </form>
</template>
