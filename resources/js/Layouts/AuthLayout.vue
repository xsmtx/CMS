<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Sign-in chrome, shared by both guards.
 *
 * A single column, centred, with nothing else on the page. Someone arriving
 * here is doing exactly one thing.
 */
defineProps<{ heading: string; subheading?: string }>()

const page = usePage()
const brand = computed(() => page.props.brand?.name ?? 'InfraCMS')
</script>

<template>
  <div class="flex min-h-[100dvh] flex-col">
    <main id="main" class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center px-6 py-16">
      <p class="text-body mb-10 font-semibold tracking-tight">{{ brand }}</p>

      <h1 class="text-2xl font-semibold tracking-tight">{{ heading }}</h1>
      <p v-if="subheading" class="text-content-muted text-body mt-2 leading-relaxed">
        {{ subheading }}
      </p>

      <div class="mt-8">
        <slot />
      </div>
    </main>
  </div>
</template>
