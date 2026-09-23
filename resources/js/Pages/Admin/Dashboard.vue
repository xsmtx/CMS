<script setup lang="ts">
/**
 * The first screen of the day.
 *
 * **Grouped, not a card per metric** (Handoff #3 §3). Twelve equal tiles is a
 * layout where nothing is more important than anything else, and the reason
 * people stop opening a dashboard is that it never tells them what to do.
 *
 * So the order is what an operator acts on, top to bottom:
 *
 * 1. **Attention Required** — the rows that are somebody's job today. First,
 *    because it is the only block that asks for anything.
 * 2. **The headline strip** — four figures, in one strip rather than four
 *    cards, so they read as a sentence about the business instead of four
 *    unrelated claims.
 * 3. **Infrastructure and revenue**, side by side: is it up, and is it
 *    growing.
 * 4. **What just happened** — the audit trail, last eight.
 *
 * Every block is `null` when the operator may not see it, rather than empty:
 * a support agent gets tickets and no revenue, and the layout closes up
 * around what is missing instead of leaving a hole where a permission was.
 */
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBarChart from '../../Components/AppBarChart.vue'
import AppCard from '../../Components/AppCard.vue'
import AppIcon from '../../Components/AppIcon.vue'
import AppStatus, { type StatusTone } from '../../Components/AppStatus.vue'
import EmptyState from '../../Components/EmptyState.vue'
import AdminLayout from '../../Layouts/AdminLayout.vue'
import { type IconName } from '../../icons'

interface AttentionRow {
  key: string
  count: number
  label: string
  tone: StatusTone
  href: string
  icon: IconName
}

interface Figure {
  key: string
  label: string
  value: string
  href: string
  hint?: string
}

const props = defineProps<{
  attention: AttentionRow[]
  headline: Figure[]
  infrastructure: {
    servers: number
    active: number
    unavailable: number
    provisioning: number
    suspended: number
  } | null
  revenue: { months: { label: string; value: number }[]; currency: string; total: string } | null
  activity:
    | {
        id: string
        action: string
        actor: string | null
        target: string | null
        reason: string | null
        at: string
      }[]
    | null
  environment: string
  version: string | null
}>()

/**
 * Minor units into money. The decimal point exists in this one function,
 * because the chart is drawn from integers — which is what money is here.
 */
const money = computed(
  () =>
    new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: props.revenue?.currency ?? 'USD',
      maximumFractionDigits: 0,
    }),
)

function formatMinor(value: number): string {
  return money.value.format(value / 100)
}

/**
 * `billing.invoice.issued` reads as "Invoice issued".
 *
 * The audit trail stores what the code calls the action, which is right for
 * a record and wrong for a line somebody reads. Turned into words here
 * rather than translated: an audit action is not a fixed vocabulary, so a
 * language file would go stale silently.
 */
function readAction(action: string): string {
  const parts = action.split('.')
  const verb = parts.at(-1)?.replace(/_/g, ' ') ?? action
  const subject = parts.at(-2)?.replace(/_/g, ' ') ?? ''

  return `${subject} ${verb}`.trim().replace(/^./, (first) => first.toUpperCase())
}

function formatTime(value: string): string {
  return new Date(value).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const serverTone = computed<StatusTone>(() => {
  const infrastructure = props.infrastructure

  if (infrastructure === null || infrastructure.servers === 0) return 'unknown'

  return infrastructure.unavailable > 0 ? 'warning' : 'healthy'
})
</script>

<template>
  <AdminLayout
    heading="Dashboard"
    description="What needs attention, what the business is doing, and what just happened."
  >
    <div class="flex flex-col gap-5">
      <!--
        First, because it is the only block that asks for anything.

        Rows appear only when they are not zero: a list padded with zeroes is
        a list an operator learns to skim, and then the one row that mattered
        is skimmed with it.
      -->
      <AppCard title="Attention required">
        <ul v-if="attention.length > 0" class="divide-line -my-1 divide-y">
          <li v-for="row in attention" :key="row.key">
            <Link
              :href="row.href"
              class="hover:bg-surface-hover group -mx-2 flex items-center gap-3 rounded-[var(--radius-sm)] px-2 py-2.5 transition-colors duration-(--duration-fast)"
            >
              <span
                class="bg-surface-secondary text-content-subtle grid size-7 shrink-0 place-items-center rounded-[var(--radius-sm)]"
                aria-hidden="true"
              >
                <AppIcon :name="row.icon" :size="15" />
              </span>

              <span class="text-body min-w-0 flex-1 truncate">{{ row.label }}</span>

              <AppStatus :tone="row.tone" :label="row.label" compact />
              <span class="text-title font-semibold tabular-nums">{{ row.count }}</span>

              <AppIcon
                name="chevronRight"
                :size="13"
                class="text-content-subtle group-hover:text-content"
              />
            </Link>
          </li>
        </ul>

        <!-- An empty list is a good morning, and the screen says so rather
             than showing an empty box. -->
        <div v-else class="flex items-center gap-3">
          <span class="text-success" aria-hidden="true"><AppIcon name="ok" :size="20" /></span>
          <div>
            <p class="text-body font-medium">Nothing needs attention</p>
            <p class="text-content-muted text-chrome mt-0.5">
              No overdue invoices, no broken promises, nothing stuck.
            </p>
          </div>
        </div>
      </AppCard>

      <!--
        Four figures in one strip, not four cards. They read as a sentence
        about the business; four cards read as four unrelated claims, and a
        fifth would have to displace one of these.
      -->
      <div
        v-if="headline.length > 0"
        class="border-line bg-surface-primary [&>*]:border-line grid divide-y rounded-[var(--radius-lg)] border sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 sm:[&>*+*]:border-l"
      >
        <Link
          v-for="figure in headline"
          :key="figure.key"
          :href="figure.href"
          class="hover:bg-surface-hover flex flex-col gap-1 px-5 py-4 transition-colors duration-(--duration-fast)"
        >
          <span class="text-content-subtle text-label uppercase">{{ figure.label }}</span>
          <span class="text-[1.625rem] leading-none font-semibold tabular-nums">
            {{ figure.value }}
          </span>
          <span v-if="figure.hint" class="text-content-subtle text-chrome">{{ figure.hint }}</span>
        </Link>
      </div>

      <div class="grid gap-5 lg:grid-cols-3">
        <!--
          Money in, by month. Monthly rather than daily: the daily shape is
          already on the Transactions screen, and what a dashboard is asked is
          "are we growing", which a day cannot answer.
        -->
        <AppCard
          v-if="revenue"
          class="lg:col-span-2"
          title="Money in"
          :description="`${revenue.total} over twelve months.`"
        >
          <AppBarChart title="Money in, by month" :rows="revenue.months" :format="formatMinor" />
        </AppCard>

        <AppCard v-if="infrastructure" title="Infrastructure">
          <dl class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
              <dt class="text-content-muted text-body">Servers</dt>
              <dd>
                <AppStatus
                  :tone="serverTone"
                  :label="
                    infrastructure.servers === 0
                      ? 'None registered'
                      : `${infrastructure.active} of ${infrastructure.servers} available`
                  "
                />
              </dd>
            </div>
            <div class="flex items-center justify-between gap-4">
              <dt class="text-content-muted text-body">Setting up</dt>
              <dd class="text-body tabular-nums">{{ infrastructure.provisioning }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4">
              <dt class="text-content-muted text-body">Suspended</dt>
              <dd class="text-body tabular-nums">{{ infrastructure.suspended }}</dd>
            </div>
          </dl>

          <div class="border-line mt-4 border-t pt-3">
            <!-- The health page runs the checks. This block reads rows, so
                 that a dashboard stays openable during the incident it is
                 wanted for. -->
            <Link
              href="/admin/health"
              class="text-brand text-chrome inline-flex items-center gap-1.5 underline-offset-4 hover:underline"
            >
              Run the health checks
              <AppIcon name="chevronRight" :size="12" />
            </Link>
          </div>
        </AppCard>
      </div>

      <AppCard
        v-if="activity"
        title="What just happened"
        description="Every sensitive action writes a record as it happens, with the actor and the reason."
      >
        <ul v-if="activity.length > 0" class="divide-line -my-1 divide-y">
          <li
            v-for="entry in activity"
            :key="entry.id"
            class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-2"
          >
            <span class="text-body font-medium">{{ readAction(entry.action) }}</span>
            <span v-if="entry.target" class="text-content-muted text-body truncate">
              {{ entry.target }}
            </span>
            <span class="text-content-subtle text-chrome ml-auto whitespace-nowrap tabular-nums">
              {{ formatTime(entry.at) }}
            </span>
            <span v-if="entry.actor" class="text-content-subtle text-chrome w-full">
              {{ entry.actor }}<template v-if="entry.reason"> — {{ entry.reason }}</template>
            </span>
          </li>
        </ul>

        <EmptyState
          v-else
          icon="history"
          title="Nothing has happened yet"
          description="Suspensions, refunds, credential rotations and permission changes appear here with the actor and the reason."
        />
      </AppCard>

      <p class="text-content-subtle text-label">{{ environment }} · {{ version ?? 'dev' }}</p>
    </div>
  </AdminLayout>
</template>
