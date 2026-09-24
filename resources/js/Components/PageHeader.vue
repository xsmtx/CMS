<script setup lang="ts">
/**
 * The top of a screen: what it is, what state it is in, and what can be
 * done — in one band, in that order (enterprise-cms-ux, "Page structure").
 *
 * - **title** — one `h1` per screen, at `text-page`. Never larger: hierarchy
 *   comes from position and weight, not from a 32px heading.
 * - **status** slot — beside the title, for a resource that has one
 *   (`<AppStatus>`).
 * - **meta** slot — the one-line summary under the title: counts on a list
 *   (`128 servers · 3 warning`), identifiers on a detail page. Separate the
 *   facts with `<span aria-hidden="true">·</span>`.
 * - **actions** slot — at most one primary button, then secondaries, then an
 *   overflow `AppMenu`. A destructive action is never here; it lives in the
 *   Danger Zone at the foot of the page.
 *
 * `AdminLayout` renders one of these from its `heading` prop, so most
 * screens never import it. A detail page that needs status and identity
 * passes its own through the layout's `#header` slot.
 */
withDefaults(defineProps<{ title: string; description?: string }>(), {
  description: undefined,
})
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
    <div class="min-w-0">
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 class="text-page min-w-0 truncate font-semibold">{{ title }}</h1>
        <slot name="status" />
      </div>
      <div
        v-if="$slots.meta"
        class="text-content-muted text-chrome mt-1 flex flex-wrap items-center gap-x-2 gap-y-1"
      >
        <slot name="meta" />
      </div>
      <p v-if="description" class="text-content-muted text-chrome mt-1 max-w-[90ch]">
        {{ description }}
      </p>
    </div>

    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
