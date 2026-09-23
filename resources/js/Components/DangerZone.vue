<script setup lang="ts">
/**
 * The Danger Zone (§8): separated, and at the bottom.
 *
 * The separation is the whole point. A Terminate button sitting in the same
 * row as Suspend and Sync is a button somebody presses by muscle memory on a
 * Friday afternoon, and the only reliable defence is distance plus a
 * different-looking surface — the eye has to notice it has arrived somewhere
 * else before the hand gets there.
 *
 * It is the last thing on the page, always. Anything below it would be a
 * reason to scroll past it, and a thing people scroll past is a thing they
 * stop reading.
 *
 * **Every action here is a sentence about what it destroys, not a verb.**
 * "Terminate" tells an operator what the button is called; "This destroys the
 * account at the provider and cannot be undone" tells them what happens. The
 * confirmation that follows is `AppConfirm` at the level the screen chooses,
 * and it is the screen's choice because only the screen knows what the action
 * costs.
 */
import AppIcon from './AppIcon.vue'

withDefaults(defineProps<{ title?: string; description?: string }>(), {
  title: 'Danger zone',
  description: undefined,
})
</script>

<template>
  <!--
    `border-danger` at a third, not at full strength: a section outlined in
    solid red is a section that reads as an error that has already happened.
    This one is a warning about what could.
  -->
  <section
    class="border-danger/35 bg-danger/[0.04] mt-8 rounded-[var(--radius-lg)] border"
    aria-labelledby="danger-zone"
  >
    <header class="border-danger/25 flex items-start gap-2.5 border-b px-4 py-3">
      <span class="text-danger mt-0.5 shrink-0" aria-hidden="true">
        <AppIcon name="warning" :size="17" />
      </span>
      <div class="min-w-0">
        <h2 id="danger-zone" class="text-title text-danger font-semibold">{{ title }}</h2>
        <p v-if="description" class="text-content-muted text-chrome mt-0.5">{{ description }}</p>
      </div>
    </header>

    <!--
      One row per action, divided. Each row is what happens on the left and
      the button on the right, so the sentence is read before the control is
      reached — which is the order somebody's eye moves in.
    -->
    <div class="divide-danger/20 divide-y">
      <slot />
    </div>
  </section>
</template>
