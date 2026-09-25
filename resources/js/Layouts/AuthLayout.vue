<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Sign-in chrome, shared by both guards.
 *
 * A single column, centred, with nothing else on the page. Someone arriving
 * here is doing exactly one thing.
 *
 * `wide` is for the one form that is not four fields: opening an account asks
 * for a name, a company and an address, and a two-column grid squeezed into
 * 24rem is a form nobody finishes. The prop rather than a second layout,
 * because everything else about the page is identical.
 */
withDefaults(defineProps<{ heading: string; subheading?: string; wide?: boolean }>(), {
  subheading: undefined,
  wide: false,
})

const page = usePage()
const brand = computed(() => page.props.brand?.name ?? 'InfraCMS')
</script>

<template>
  <div class="flex min-h-[100dvh] flex-col">
    <main
      id="main"
      class="mx-auto flex w-full flex-1 flex-col justify-center px-6 py-16"
      :class="wide ? 'max-w-2xl' : 'max-w-sm'"
    >
      <p class="text-body mb-10 font-semibold tracking-tight">{{ brand }}</p>

      <h1 class="text-page font-semibold tracking-tight">{{ heading }}</h1>
      <p v-if="subheading" class="text-content-muted text-body mt-2 leading-relaxed">
        {{ subheading }}
      </p>

      <div class="mt-8">
        <slot />
      </div>
    </main>
  </div>
</template>
