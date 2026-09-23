<script setup lang="ts">
/**
 * The Background Operations drawer (§8).
 *
 * An operation is visible before it finishes (ADR 0032). The Operations
 * screen answers that for somebody who went looking; this answers it for
 * somebody who did not — a provisioning run that failed twenty minutes ago
 * is a thing an operator should trip over, not a thing they should have
 * thought to check.
 *
 * So the trigger lives in the chrome and carries a count. The count is two
 * indexed numbers shared on every admin render, which is a real cost and the
 * only one worth paying: a badge that is always grey is worse than no badge.
 *
 * The rows arrive only when the drawer opens (`only: ['operationQueue']`),
 * and while something is still running it asks again every few seconds —
 * only while open, because a page polling in the background is a page that
 * costs an operator's laptop battery all afternoon.
 *
 * Every row carries its **correlation ID**, which §8 names. It is the one
 * string that ties a failure here to the lines in the log, and the operator
 * pastes it into both.
 */
import { router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import AppCopy from './AppCopy.vue'
import AppDrawer from './AppDrawer.vue'
import AppIcon from './AppIcon.vue'
import AppStatus, { type StatusTone } from './AppStatus.vue'
import EmptyState from './EmptyState.vue'

interface OperationRow {
  id: string
  typeLabel: string
  state: string
  stateLabel: string
  subject: string | null
  attempt: number
  maxAttempts: number
  progress: number
  correlationId: string | null
  error: string | null
  needsAttention: boolean
  startedAt: string | null
  finishedAt: string | null
  createdAt: string | null
}

const page = usePage()

const counts = computed(() => page.props.operations ?? null)
const rows = computed<OperationRow[]>(() => page.props.operationQueue ?? [])

const open = ref(false)
const loading = ref(false)

let timer: ReturnType<typeof setInterval> | null = null

function fetchRows(): void {
  router.reload({
    only: ['operationQueue', 'operations'],
    onFinish: () => {
      loading.value = false
    },
  })
}

function stopPolling(): void {
  if (timer !== null) clearInterval(timer)

  timer = null
}

watch(open, (isOpen) => {
  if (!isOpen) {
    stopPolling()

    return
  }

  loading.value = rows.value.length === 0
  fetchRows()

  // Only while open, and only every six seconds. An operation is a thing
  // that takes seconds to minutes; a faster poll would be a request per
  // scroll and would tell an operator nothing new.
  timer = setInterval(() => {
    if ((counts.value?.active ?? 0) > 0) fetchRows()
  }, 6000)
})

onBeforeUnmount(stopPolling)

/**
 * The tone a state reads as.
 *
 * `manual_intervention` is critical rather than a warning on purpose: it is
 * the state that says no amount of retrying will help and somebody has to
 * look, and a warning is what an operator puts off until tomorrow.
 */
const TONES: Record<string, StatusTone> = {
  pending: 'unknown',
  running: 'info',
  retrying: 'warning',
  failed: 'critical',
  manual_intervention: 'critical',
  completed: 'healthy',
}

function toneOf(state: string): StatusTone {
  return TONES[state] ?? 'unknown'
}

function formatTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <!-- Nothing at all when the viewer may not see operations: the props are
       null rather than empty, so there is no button to press into a 403. -->
  <template v-if="counts">
    <button
      type="button"
      class="pressable text-content-muted hover:text-content relative rounded-[var(--radius-sm)] p-1.5 transition-colors duration-(--duration-fast)"
      :aria-label="
        counts.attention > 0
          ? `Background operations — ${counts.attention} need attention`
          : `Background operations — ${counts.active} running`
      "
      :aria-expanded="open"
      @click="open = true"
    >
      <AppIcon name="sync" :size="17" />

      <!--
        A dot, not a number, unless something needs a person. "3 running" is
        not a thing anybody acts on; "2 failed" is, so that one gets counted.
        The shape differs as well as the colour — a red dot and an amber dot
        are the same dot to a good few operators.
      -->
      <span
        v-if="counts.attention > 0"
        class="bg-danger text-content-inverse absolute -top-0.5 -right-0.5 grid min-w-[1.05rem] place-items-center rounded-full px-1 text-[10px] leading-[1.05rem] font-semibold tabular-nums"
        aria-hidden="true"
      >
        {{ counts.attention > 9 ? '9+' : counts.attention }}
      </span>
      <span
        v-else-if="counts.active > 0"
        class="bg-info absolute top-0.5 right-0.5 size-1.5 rounded-full"
        aria-hidden="true"
      />
    </button>

    <AppDrawer
      v-model:open="open"
      title="Background operations"
      subtitle="What the platform is doing, and what went wrong"
      href="/admin/operations"
      :loading="loading"
    >
      <ul v-if="rows.length > 0" class="divide-line divide-y">
        <li v-for="row in rows" :key="row.id" class="flex flex-col gap-1.5 py-3 first:pt-0">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-body font-medium">{{ row.typeLabel }}</p>
              <p v-if="row.subject" class="text-content-muted text-chrome truncate">
                {{ row.subject }}
              </p>
            </div>
            <AppStatus :tone="toneOf(row.state)" :label="row.stateLabel" compact />
          </div>

          <!-- The attempt counter is never reset by a retry (ADR 0032), so
               somebody retrying a thing that has failed three times can see
               that it is on its fourth. -->
          <p class="text-content-subtle text-label">
            Attempt {{ row.attempt }} of {{ row.maxAttempts }} ·
            {{ formatTime(row.finishedAt ?? row.startedAt ?? row.createdAt) }}
          </p>

          <p v-if="row.error" class="text-danger text-chrome break-words">{{ row.error }}</p>

          <p v-if="row.correlationId" class="text-content-subtle text-label">
            <AppCopy :value="row.correlationId" noun="correlation ID" />
          </p>
        </li>
      </ul>

      <EmptyState
        v-else
        icon="sync"
        title="Nothing is running"
        description="Provisioning runs, domain transfers and gateway captures appear here while they happen, and stay if they fail."
      />
    </AppDrawer>
  </template>
</template>
