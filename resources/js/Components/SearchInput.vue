<script setup lang="ts">
/**
 * The search box at the head of a list.
 *
 * The one field allowed to go without a visible label: the magnifier and
 * the placeholder together say what it is, the way every search box does,
 * and the `label` prop is its accessible name so a screen reader hears
 * "Search clients" rather than "edit text". Every *other* field keeps its
 * label above it (accessibility skill, "Forms").
 *
 * `type="search"` gives Escape-to-clear and the right keyboard on a phone.
 * Enter submits the surrounding form; that is the caller's.
 */
import AppIcon from './AppIcon.vue'

withDefaults(
  defineProps<{
    label: string
    placeholder?: string
    /** Width, as a Tailwind class, because a list knows what it searches. */
    width?: string
  }>(),
  { placeholder: undefined, width: 'w-72' },
)

const model = defineModel<string>({ required: true })
</script>

<template>
  <div class="relative max-w-full" :class="width">
    <span
      class="text-content-subtle pointer-events-none absolute inset-y-0 left-2.5 flex items-center"
      aria-hidden="true"
    >
      <AppIcon name="search" :size="14" />
    </span>
    <input
      v-model="model"
      type="search"
      :aria-label="label"
      :placeholder="placeholder ?? label"
      class="border-line bg-surface-primary text-content placeholder:text-content-subtle focus:border-brand text-body h-(--control-h) w-full rounded-md border pr-3 pl-8 transition-colors duration-(--duration-fast) ease-(--ease-out)"
    />
  </div>
</template>
