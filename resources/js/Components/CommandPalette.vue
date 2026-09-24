<script setup lang="ts">
/**
 * ⌘K.
 *
 * A panel with forty destinations has two kinds of user: the one who learned
 * the menu, and the one who knows the name of the thing they want. The menu
 * serves the first. This serves the second, and it is what separates a
 * control panel somebody chose from one they were given — after a week an
 * operator stops using the menu for anything they can name.
 *
 * **Two lists, one box.** Destinations are matched locally and appear
 * instantly, because a screen is a thing the operator already knows the name
 * of and a round trip to confirm it is latency with no information in it.
 * Records — a client, an invoice, a domain — are fetched, debounced, from the
 * search endpoint that already exists. The local list never waits for the
 * remote one.
 *
 * The match is a subsequence, not a substring: `adnc` finds "Add New Client",
 * which is how anybody who uses one of these actually types. Ranking prefers
 * a prefix hit, then a shorter label, so "Orders" beats "List All Orders"
 * when somebody types `ord`.
 *
 * No animation on open. This is opened by a keystroke, hundreds of times a
 * week, and a 200ms entrance on a keyboard action is 200ms of nothing —
 * Raycast has no open animation and that is the correct answer for something
 * used that often.
 */
import { router } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import AppIcon from './AppIcon.vue'
import { useTranslations } from '../composables/useTranslations'
import { type IconName } from '../icons'

export interface Destination {
  label: string
  href: string
  /** The group it lives under, shown as the row's context. */
  group: string
  icon: IconName
}

interface RemoteRow {
  title: string
  subtitle: string
  href: string
}

interface RemoteGroup {
  key: string
  label: string
  rows: RemoteRow[]
}

const props = defineProps<{ destinations: Destination[] }>()

const { t } = useTranslations()

const open = ref(false)
const term = ref('')
const active = ref(0)
const field = ref<HTMLInputElement | null>(null)
const records = ref<RemoteGroup[]>([])
const loading = ref(false)

/**
 * A subsequence match, scored.
 *
 * Lower is better. A prefix beats a scattered hit and a short label beats a
 * long one, which is the whole of what ranking needs to do here: the list is
 * forty items, not forty thousand.
 */
function score(label: string, query: string): number | null {
  const haystack = label.toLowerCase()
  const needle = query.toLowerCase()

  if (needle === '') return label.length

  if (haystack.startsWith(needle)) return label.length - 1000

  let at = 0
  let gaps = 0

  for (const character of needle) {
    const found = haystack.indexOf(character, at)

    if (found === -1) return null

    gaps += found - at
    at = found + 1
  }

  return gaps + label.length
}

const matches = computed(() => {
  const query = term.value.trim()

  return props.destinations
    .map((destination) => ({ destination, rank: score(destination.label, query) }))
    .filter((row): row is { destination: Destination; rank: number } => row.rank !== null)
    .sort((a, b) => a.rank - b.rank)
    .slice(0, 8)
    .map((row) => row.destination)
})

/** Every selectable row, flattened, because ↑↓ crosses the groups. */
const rows = computed(() => [
  ...matches.value.map((destination) => ({
    kind: 'destination' as const,
    href: destination.href,
    title: destination.label,
    subtitle: destination.group,
    icon: destination.icon,
  })),
  ...records.value.flatMap((group) =>
    group.rows.slice(0, 4).map((row) => ({
      kind: 'record' as const,
      href: row.href,
      title: row.title,
      subtitle: row.subtitle || group.label,
      icon: iconFor(group.key),
    })),
  ),
])

function iconFor(key: string): IconName {
  return (
    (
      {
        clients: 'clients',
        services: 'services',
        domains: 'domains',
        invoices: 'invoice',
        orders: 'orders',
        tickets: 'ticket',
      } as Record<string, IconName>
    )[key] ?? 'search'
  )
}

let timer: ReturnType<typeof setTimeout> | undefined

/**
 * Records are fetched, debounced. Two characters is the floor: one character
 * matches most of a database and none of it is useful.
 */
watch(term, (value) => {
  active.value = 0

  if (timer) clearTimeout(timer)

  if (value.trim().length < 2) {
    records.value = []
    loading.value = false

    return
  }

  loading.value = true

  timer = setTimeout(() => {
    router.get(
      '/admin/search',
      { q: value },
      {
        only: ['groups'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: (page) => {
          records.value = (page.props.groups ?? []) as RemoteGroup[]
        },
        onFinish: () => (loading.value = false),
      },
    )
  }, 220)
})

async function show(): Promise<void> {
  open.value = true
  term.value = ''
  records.value = []
  active.value = 0

  await nextTick()
  field.value?.focus()
}

function hide(): void {
  open.value = false
  if (timer) clearTimeout(timer)
}

function go(index: number): void {
  const row = rows.value[index]

  if (!row) return

  hide()
  router.visit(row.href)
}

function move(by: number): void {
  const count = rows.value.length

  if (count === 0) return

  // Wraps, because a list that stops at the bottom makes somebody press ↑
  // eight times to reach the first row.
  active.value = (active.value + by + count) % count
}

/**
 * ⌘K, Ctrl+K, and `/` when nothing else has the keyboard.
 *
 * `/` is the one every operator tries first and it must not fire while they
 * are typing in a filter, so it is ignored whenever a field is focused.
 */
function onKey(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null
  const typing =
    target !== null &&
    (target.tagName === 'INPUT' ||
      target.tagName === 'TEXTAREA' ||
      target.tagName === 'SELECT' ||
      target.isContentEditable)

  if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
    event.preventDefault()

    if (open.value) {
      hide()
    } else {
      void show()
    }

    return
  }

  if (event.key === '/' && !typing && !open.value) {
    event.preventDefault()
    void show()
  }
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  if (timer) clearTimeout(timer)
})

defineExpose({ show })
</script>

<template>
  <!-- The trigger reads as a field rather than a button, because that is
       what it becomes. Showing the shortcut is how anybody learns it. -->
  <button
    type="button"
    class="pressable border-line bg-background text-content-subtle hover:border-line-strong text-body hidden items-center gap-2 rounded-sm border py-1.5 pr-1.5 pl-2.5 transition-colors duration-(--duration-fast) sm:flex sm:w-64"
    @click="show"
  >
    <AppIcon name="search" :size="15" />
    <span class="flex-1 text-left">{{ t('ui.palette.search', {}, 'Search') }}</span>
    <kbd
      class="border-line bg-surface-secondary text-content-subtle text-label rounded-[4px] border px-1.5 py-0.5 font-sans"
    >
      ⌘K
    </kbd>
  </button>

  <button
    type="button"
    class="pressable text-content-muted hover:text-content rounded-sm p-1.5 transition-colors duration-(--duration-fast) sm:hidden"
    @click="show"
  >
    <AppIcon name="search" :size="16" :label="t('ui.palette.search', {}, 'Search')" />
  </button>

  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]"
      role="dialog"
      aria-modal="true"
      :aria-label="t('ui.palette.dialog', {}, 'Search and go')"
    >
      <!-- A scrim, not a blur: a blurred page behind a palette is a frame
           the browser repaints on every keystroke. -->
      <div class="bg-background/70 absolute inset-0" @click="hide" />

      <div
        class="border-line bg-surface-primary relative w-full max-w-xl overflow-hidden rounded-lg border shadow-(--shadow-panel)"
      >
        <div class="border-line flex items-center gap-2.5 border-b px-3.5 py-2.5">
          <AppIcon name="search" :size="16" class="text-content-subtle" />
          <input
            ref="field"
            v-model="term"
            type="text"
            :placeholder="
              t(
                'ui.palette.placeholder',
                {},
                'Go to a screen, or find a client, invoice or domain…',
              )
            "
            class="text-content placeholder:text-content-subtle text-body w-full bg-transparent outline-none"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="go(active)"
            @keydown.escape="hide"
          />
          <span
            v-if="loading"
            class="border-content-subtle size-3.5 animate-spin rounded-full border-2 border-t-transparent"
            aria-hidden="true"
          />
        </div>

        <ul v-if="rows.length > 0" class="max-h-[22rem] overflow-y-auto p-1.5">
          <li v-for="(row, index) in rows" :key="row.kind + row.href + row.title">
            <button
              type="button"
              class="flex w-full items-center gap-2.5 rounded-sm px-2.5 py-2 text-left transition-colors duration-(--duration-fast)"
              :class="index === active ? 'bg-surface-secondary text-content' : 'text-content-muted'"
              @click="go(index)"
              @mousemove="active = index"
            >
              <AppIcon :name="row.icon" :size="15" />
              <span class="min-w-0 flex-1">
                <span class="text-body block truncate">{{ row.title }}</span>
                <span v-if="row.subtitle" class="text-content-subtle text-chrome block truncate">
                  {{ row.subtitle }}
                </span>
              </span>
              <AppIcon
                v-if="index === active"
                name="chevronRight"
                :size="13"
                class="text-content-subtle"
              />
            </button>
          </li>
        </ul>

        <p v-else class="text-content-muted text-body px-3.5 py-6 text-center">
          <!-- The term first, so neither language needs an empty half-key to
               put its words on the other side of it. -->
          <span class="text-content">{{ term }}</span>
          {{ t('ui.palette.nothing', {}, 'does not match anything yet.') }}
        </p>

        <div
          class="border-line bg-surface-secondary text-content-subtle text-label flex items-center gap-4 border-t px-3.5 py-2"
        >
          <span><kbd class="font-sans">↑↓</kbd> {{ t('ui.palette.move', {}, 'move') }}</span>
          <span><kbd class="font-sans">↵</kbd> {{ t('ui.palette.open', {}, 'open') }}</span>
          <span><kbd class="font-sans">esc</kbd> {{ t('ui.palette.close', {}, 'close') }}</span>
          <span class="ml-auto">
            <kbd class="font-sans">/</kbd> {{ t('ui.palette.anywhere', {}, 'anywhere') }}
          </span>
        </div>
      </div>
    </div>
  </Teleport>
</template>
