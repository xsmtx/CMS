<script setup lang="ts">
/**
 * A row action menu that is not trapped inside the table.
 *
 * A panel positioned `absolute` inside a cell is clipped by the table's own
 * `overflow-x-auto` wrapper — the menu opens *into* the table, scrolls with
 * it, and on a short list is cut off entirely.
 *
 * `useAnchoredPanel` is where that is solved, once, for every panel in the
 * product that has to escape something: the panel is teleported to `<body>`
 * and positioned `fixed` against the trigger's own rectangle. This component
 * is the menu-shaped use of it; the collapsed sidebar's flyout is the other,
 * and it had the identical bug for the identical reason.
 *
 * Closing is deliberately generous: a click anywhere else, or Escape. A menu
 * that stays open over the next row is how an operator resets the wrong
 * person's password.
 */

import { useTranslations } from '../composables/useTranslations'
import AppIcon from './AppIcon.vue'
import { useAnchoredPanel } from '../composables/useAnchoredPanel'
import { type IconName } from '../icons'

const props = withDefaults(
  defineProps<{
    label: string
    /** Which edge of the trigger the panel lines up with. */
    align?: 'start' | 'end'
    width?: string
    /**
     * Draw the label as a round placeholder face instead of a word. Used
     * by the account menu, where an email read across the top of every
     * page is somebody's identifier on a screen other people walk past.
     */
    avatar?: boolean
    /**
     * Draw the trigger as an icon button. The label stays, as the
     * accessible name — an icon with no name is a button screen readers
     * announce as "button".
     */
    icon?: IconName | null
    /**
     * Draw the trigger as a row in a tab bar rather than as a button.
     *
     * The portal's nav is underlined tabs, and a section with several screens
     * is a dropdown - so one of those tabs opens a menu. Without this it
     * would be a button of a different height and weight sitting in a row of
     * tabs, which is the thing a design system exists to stop.
     */
    tab?: boolean
    /** Whether that tab is the section being looked at. */
    current?: boolean
  }>(),
  { align: 'end', width: '15rem', avatar: false, icon: null, tab: false, current: false },
)

const { t } = useTranslations()

const { open, trigger, panel, style } = useAnchoredPanel({
  align: props.align,
  width: props.width,
})

function toggle(): void {
  open.value = !open.value
}

function close(): void {
  open.value = false
}

defineExpose({ close })
</script>

<template>
  <button
    v-if="icon"
    ref="trigger"
    type="button"
    class="pressable text-content-muted hover:text-content rounded-sm p-1.5 transition-colors duration-(--duration-fast)"
    :aria-expanded="open"
    aria-haspopup="menu"
    :aria-label="label"
    @click="toggle"
  >
    <AppIcon :name="icon ?? 'more'" :size="16" />
  </button>

  <button
    v-else-if="avatar"
    ref="trigger"
    type="button"
    class="pressable bg-surface-secondary text-content-muted hover:text-content border-line hover:border-line-strong text-chrome inline-flex size-8 items-center justify-center rounded-full border font-semibold transition-colors duration-(--duration-fast)"
    :aria-expanded="open"
    aria-haspopup="menu"
    :aria-label="t('ui.shell.account', {}, 'Account')"
    @click="toggle"
  >
    {{ label }}
  </button>

  <button
    v-else-if="tab"
    ref="trigger"
    type="button"
    class="pressable text-body -mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-3 transition-colors duration-(--duration-fast) ease-(--ease-out)"
    :class="
      current
        ? 'border-brand text-content font-medium'
        : 'text-content-muted hover:border-line-strong hover:text-content border-transparent'
    "
    :aria-expanded="open"
    aria-haspopup="menu"
    @click="toggle"
  >
    {{ label }}
    <span
      class="text-content-subtle transition-transform duration-150"
      :class="open ? 'rotate-180' : ''"
    >
      <AppIcon name="chevronDown" :size="12" />
    </span>
  </button>

  <button
    v-else
    ref="trigger"
    type="button"
    class="pressable text-content-muted hover:text-content text-body inline-flex items-center gap-1.5 rounded-sm px-2 py-1"
    :aria-expanded="open"
    aria-haspopup="menu"
    @click="toggle"
  >
    {{ label }}
    <span
      class="text-content-subtle transition-transform duration-150"
      :class="open ? 'rotate-180' : ''"
    >
      <AppIcon name="chevronDown" :size="12" />
    </span>
  </button>

  <Teleport to="body">
    <Transition name="menu">
      <div
        v-if="open"
        ref="panel"
        role="menu"
        class="panel-enter floating z-50 origin-top-right rounded-lg p-1 text-left"
        :style="style"
      >
        <slot :close="close" />
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.menu-enter-active {
  transition:
    opacity 150ms cubic-bezier(0.23, 1, 0.32, 1),
    transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
}

.menu-leave-active {
  transition:
    opacity 100ms cubic-bezier(0.23, 1, 0.32, 1),
    transform 100ms cubic-bezier(0.23, 1, 0.32, 1);
}

/* Never from scale(0): nothing in the world appears out of nothing. */
.menu-enter-from,
.menu-leave-to {
  opacity: 0;
  transform: scale(0.97);
}

@media (prefers-reduced-motion: reduce) {
  .menu-enter-active,
  .menu-leave-active {
    transition-duration: 1ms;
  }
}
</style>
