<script setup lang="ts">
/**
 * The right-side context drawer (§8).
 *
 * It exists for one reason: an operator scanning a list of two hundred rows
 * wants to check one of them without losing the list. A full page navigation
 * costs them their scroll position, their filter and their selection, and
 * they get all three back by pressing Back and waiting. So the record comes
 * to them.
 *
 * **Inspection, not management.** Anything that takes more than a glance —
 * editing, a settings screen, a form with more than one decision in it —
 * belongs on its own page, and the drawer says so by always offering the way
 * there. A drawer that grew into a second version of the detail page would
 * be two screens to keep in step.
 *
 * It is teleported and `fixed`, which is the only placement no ancestor's
 * overflow can clip, and it slides from the edge it is attached to. On a
 * phone it is the full width, because 420px of a 390px screen is a drawer
 * with a sliver of list beside it that nobody can read.
 *
 * Focus moves in on open and back to whatever opened it on close: a panel
 * somebody tabbed into from the row behind it is a panel that reads its own
 * content and the list's at once.
 */
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

import AppIcon from './AppIcon.vue'

withDefaults(
  defineProps<{
    title: string
    /** The line under the title: what this record is, not what to do with it. */
    subtitle?: string
    /** Where the whole record lives. Always offered — see above. */
    href?: string
    /** Waiting for the record to arrive. */
    loading?: boolean
  }>(),
  { subtitle: undefined, href: undefined, loading: false },
)

const open = defineModel<boolean>('open', { required: true })

const panel = ref<HTMLElement | null>(null)
const titleId = useId()

/** Whatever had focus when this opened, so it can be given back. */
let opener: HTMLElement | null = null

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') open.value = false
}

/**
 * `immediate`, because a drawer can mount already open — a deep link, or a
 * screen that decides server-side that one is showing. Without it that
 * drawer has no Escape and never takes focus, and nothing says so.
 */
watch(
  open,
  async (isOpen) => {
    if (!isOpen) {
      document.removeEventListener('keydown', onKeydown)
      opener?.focus()
      opener = null

      return
    }

    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null

    document.addEventListener('keydown', onKeydown)

    await nextTick()
    panel.value?.focus()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <Transition name="drawer">
      <div v-if="open" class="fixed inset-0 z-50 flex justify-end">
        <!-- The scrim closes it for the pointer. Escape is the keyboard's
             way out and it is already listening. -->
        <div class="bg-background/60 absolute inset-0" aria-hidden="true" @click="open = false" />

        <aside
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          class="drawer-panel border-line bg-surface-primary relative flex h-full w-full max-w-full flex-col border-l shadow-(--shadow-panel) outline-none sm:max-w-[26rem]"
        >
          <header class="border-line flex items-start gap-3 border-b px-4 py-3">
            <div class="min-w-0 flex-1">
              <h2 :id="titleId" class="text-title truncate font-semibold">{{ title }}</h2>
              <p v-if="subtitle" class="text-content-muted text-chrome mt-0.5 truncate">
                {{ subtitle }}
              </p>
            </div>

            <button
              type="button"
              class="pressable text-content-subtle hover:bg-surface-hover hover:text-content shrink-0 rounded-[var(--radius-sm)] p-1.5 transition-colors duration-(--duration-fast)"
              aria-label="Close"
              @click="open = false"
            >
              <AppIcon name="close" :size="16" />
            </button>
          </header>

          <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
            <!-- The skeleton is three bars rather than a spinner, for the
                 same reason the tables use one: the panel keeps its size. -->
            <div v-if="loading" class="flex flex-col gap-2.5" aria-busy="true">
              <p class="sr-only" role="status">Loading</p>
              <span class="skeleton block h-3 w-1/3 rounded-full" aria-hidden="true" />
              <span class="skeleton block h-3 w-3/4 rounded-full" aria-hidden="true" />
              <span class="skeleton block h-3 w-2/3 rounded-full" aria-hidden="true" />
            </div>

            <slot v-else />
          </div>

          <!--
            The way to the whole record, always. The drawer is for a glance;
            anything that takes a decision belongs on a page of its own.
          -->
          <footer v-if="href || $slots.actions" class="border-line border-t px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
              <slot name="actions" />
              <a
                v-if="href"
                :href="href"
                class="text-brand text-chrome ml-auto inline-flex items-center gap-1.5 underline-offset-4 hover:underline"
              >
                Open the full record
                <AppIcon name="chevronRight" :size="12" />
              </a>
            </div>
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/*
 * The scrim fades and the panel slides. `transform` and `opacity` only, so
 * it stays on the GPU — a drawer that animated `width` would repaint the
 * list behind it on every frame.
 *
 * `ease-out` and 220ms: entering, so it starts fast, and short enough that
 * somebody opening four rows in a row is not waiting on it.
 */
.drawer-enter-active,
.drawer-leave-active {
  transition: opacity 180ms var(--ease-out);
}

.drawer-enter-active .drawer-panel,
.drawer-leave-active .drawer-panel {
  transition: transform 220ms var(--ease-out);
}

.drawer-enter-from,
.drawer-leave-to {
  opacity: 0;
}

.drawer-enter-from .drawer-panel,
.drawer-leave-to .drawer-panel {
  transform: translateX(100%);
}

@media (prefers-reduced-motion: reduce) {
  .drawer-enter-from .drawer-panel,
  .drawer-leave-to .drawer-panel {
    transform: none;
  }
}
</style>
