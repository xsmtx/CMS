<script setup lang="ts">
/**
 * Tabs for the facets of one resource: Overview, Contacts, Network, Events.
 *
 * The WAI-ARIA tabs pattern, whole: a `tablist`, one `tab` per facet with
 * `aria-selected` and `aria-controls`, one `tabpanel` labelled by its tab.
 * Arrow keys move between tabs and select them (automatic activation — the
 * panels here are already loaded, so there is nothing to wait for), Home and
 * End jump to the ends, and only the selected tab is in the Tab order, so
 * Tab goes from the tab strip straight into the panel.
 *
 * `query` writes the selected tab into the URL (`?tab=contacts`) with
 * `replaceState`, so a link to a facet can be shared and Back does not walk
 * through every tab somebody clicked.
 *
 * **Do not manufacture tabs.** A resource with two short facets is one page
 * with two sections. Tabs earn their place when a facet is long enough, or
 * rarely enough needed, that showing it inline would bury the overview.
 */
import { nextTick, onMounted, ref, useId } from 'vue'

export interface Tab {
  key: string
  label: string
  /** A count beside the label — contacts, open alerts. Zero is shown. */
  count?: number
}

const props = withDefaults(
  defineProps<{
    tabs: Tab[]
    /** Accessible name for the tab strip. */
    label: string
    /** The query parameter that remembers the tab, or none. */
    query?: string
  }>(),
  { query: undefined },
)

const active = defineModel<string>({ required: true })

const id = useId()
const buttons = ref<HTMLButtonElement[]>([])

onMounted(() => {
  if (props.query === undefined) return

  const wanted = new URLSearchParams(window.location.search).get(props.query)

  if (wanted !== null && props.tabs.some((tab) => tab.key === wanted)) active.value = wanted
})

function select(key: string): void {
  active.value = key

  if (props.query === undefined) return

  const url = new URL(window.location.href)

  if (key === props.tabs[0]?.key) url.searchParams.delete(props.query)
  else url.searchParams.set(props.query, key)

  // Inertia keeps its page object in history.state; carry it over so Back
  // still restores the page rather than a blank state.
  window.history.replaceState(window.history.state, '', url)
}

function onKeydown(event: KeyboardEvent, index: number): void {
  const last = props.tabs.length - 1
  const target = {
    ArrowRight: index === last ? 0 : index + 1,
    ArrowLeft: index === 0 ? last : index - 1,
    Home: 0,
    End: last,
  }[event.key]

  if (target === undefined) return

  event.preventDefault()

  const tab = props.tabs[target]

  if (tab === undefined) return

  select(tab.key)
  void nextTick(() => buttons.value[target]?.focus())
}
</script>

<template>
  <div>
    <div role="tablist" :aria-label="label" class="tab-list flex gap-1 overflow-x-auto">
      <button
        v-for="(tab, index) in tabs"
        :id="`${id}-tab-${tab.key}`"
        :key="tab.key"
        ref="buttons"
        type="button"
        role="tab"
        :aria-selected="active === tab.key"
        :aria-controls="`${id}-panel`"
        :tabindex="active === tab.key ? 0 : -1"
        class="text-body inline-flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 pt-1.5 pb-2 font-medium whitespace-nowrap transition-colors duration-(--duration-fast) ease-(--ease-out)"
        :class="
          active === tab.key
            ? 'border-brand text-content'
            : 'text-content-muted hover:text-content hover:border-line-strong border-transparent'
        "
        @click="select(tab.key)"
        @keydown="onKeydown($event, index)"
      >
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="text-content-subtle text-chrome tabular-nums">
          {{ tab.count }}
        </span>
      </button>
    </div>

    <div
      :id="`${id}-panel`"
      role="tabpanel"
      :aria-labelledby="`${id}-tab-${active}`"
      tabindex="0"
      class="pt-5 focus-visible:outline-offset-4"
    >
      <slot :active="active" />
    </div>
  </div>
</template>
