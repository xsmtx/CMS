<script setup lang="ts">
/**
 * One irreversible thing, and what it costs.
 *
 * The sentence is on the left and the control is on the right, because that
 * is the order an eye moves in: an operator reads what happens before their
 * hand reaches the button. A row that led with the button would be a row
 * where the explanation is something people read afterwards.
 *
 * The title is a statement, not a verb. "Terminate" names the button;
 * "Terminating destroys the account at the provider" names the consequence,
 * and the consequence is the thing somebody has to weigh.
 */
defineProps<{ title: string; description: string }>()
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 px-4 py-3.5">
    <div class="max-w-[48ch] min-w-0 flex-1">
      <p class="text-body font-medium">{{ title }}</p>
      <p class="text-content-muted text-chrome mt-0.5 leading-relaxed">{{ description }}</p>
    </div>

    <div class="flex shrink-0 items-center gap-2"><slot /></div>
  </div>
</template>
