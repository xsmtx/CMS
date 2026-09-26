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
 * 1. **Attention required** — the rows that are somebody's job today. First,
 *    because it is the only block that asks for anything. When it is empty
 *    it is one line, not a box.
 * 2. **The headline blocks** — four figures as solid colour tiles, which is
 *    read as a sentence about the business instead of four unrelated claims.
 * 3. **Revenue and infrastructure**, side by side: is it growing, and is it up.
 * 4. **Recent activity** — the audit trail as a table, because it is one:
 *    when, what, to what, by whom.
 *
 * **The board is framed and the rest of the admin is not.** Every other
 * operator screen groups with a heading and a hairline, because a page of
 * forty rectangles is a page where nothing is more important than anything
 * else. A dashboard is the one screen that is genuinely a board of unrelated
 * widgets - revenue, fleet, the audit trail - and the frame is how an
 * operator tells where one subject ends and the next begins. It is the same
 * argument the portal's panels won, and `whmcs-admin-dashboard.png` is a grid
 * of exactly these.
 *
 * Attention is the exception and stays a hairline: it is one line when
 * nothing is wrong, and a rectangle drawn around one line is noise.
 *
 * Every block is `null` when the operator may not see it, rather than empty:
 * a support agent gets tickets and no revenue, and the layout closes up
 * around what is missing instead of leaving a hole where a permission was.
 */
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBarChart from '../../Components/AppBarChart.vue'
import AppCard from '../../Components/AppCard.vue'
import AppIcon from '../../Components/AppIcon.vue'
import AppStatus, { type StatusTone } from '../../Components/AppStatus.vue'
import AppTable from '../../Components/AppTable.vue'
import DescriptionList from '../../Components/DescriptionList.vue'
import DetailSection from '../../Components/DetailSection.vue'
import EmptyState from '../../Components/EmptyState.vue'
import StatBlocks from '../../Components/StatBlocks.vue'
import { useTranslations } from '../../composables/useTranslations'
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

const { t } = useTranslations()

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

/**
 * The actor without the address in angle brackets. The audit record keeps
 * the whole string; a dashboard row only needs to say who.
 */
function readActor(actor: string | null): string {
  if (actor === null) return t('ui.common.system')

  return actor.replace(/\s*<[^>]+>\s*$/, '') || actor
}

const serverTone = computed<StatusTone>(() => {
  const infrastructure = props.infrastructure

  if (infrastructure === null || infrastructure.servers === 0) return 'unknown'

  return infrastructure.unavailable > 0 ? 'warning' : 'healthy'
})

const infrastructureFacts = computed(() => [
  { key: 'servers', label: t('ui.dashboard.servers') },
  {
    key: 'provisioning',
    label: t('ui.dashboard.setting_up'),
    value: props.infrastructure?.provisioning ?? 0,
  },
  {
    key: 'suspended',
    label: t('ui.dashboard.suspended'),
    value: props.infrastructure?.suspended ?? 0,
  },
])

const activityColumns = [
  { key: 'at', label: t('ui.dashboard.activity_when') },
  { key: 'action', label: t('ui.dashboard.activity_event') },
  { key: 'target', label: t('ui.dashboard.activity_target') },
  { key: 'actor', label: t('ui.dashboard.activity_actor') },
]
</script>

<template>
  <Head :title="t('ui.dashboard.title')" />

  <AdminLayout :heading="t('ui.dashboard.title')">
    <template #meta>
      <span>{{ environment }}</span>
      <span aria-hidden="true">·</span>
      <span class="font-mono">{{ version ?? 'dev' }}</span>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        First, because it is the only block that asks for anything. Rows
        appear only when they are not zero: a list padded with zeroes is a
        list an operator learns to skim.
      -->
      <DetailSection :title="t('ui.dashboard.attention')" :divided="attention.length > 0">
        <ul v-if="attention.length > 0" class="divide-line-subtle divide-y">
          <li v-for="row in attention" :key="row.key">
            <Link
              :href="row.href"
              class="hover:bg-surface-hover group -mx-2 flex items-center gap-3 rounded-sm px-2 py-2 transition-colors duration-(--duration-fast)"
            >
              <AppIcon :name="row.icon" :size="16" class="text-content-subtle" />
              <span class="min-w-0 flex-1 truncate">{{ row.label }}</span>
              <AppStatus :tone="row.tone" :label="row.label" compact />
              <span class="w-10 text-right font-semibold tabular-nums">{{ row.count }}</span>
              <AppIcon
                name="chevronRight"
                :size="14"
                class="text-content-subtle group-hover:text-content"
              />
            </Link>
          </li>
        </ul>

        <!-- An empty list is a good morning, said in one line. -->
        <p v-else class="text-content-muted flex items-center gap-2">
          <AppIcon name="ok" :size="16" class="text-success" />
          <span>
            <span class="text-content font-medium">{{ t('ui.dashboard.all_clear') }}</span>
            {{ t('ui.dashboard.all_clear_detail') }}
          </span>
        </p>
      </DetailSection>

      <StatBlocks v-if="headline.length > 0" :items="headline" />

      <div
        v-if="revenue || infrastructure"
        class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]"
      >
        <!--
          Money in, by month. Monthly rather than daily: the daily shape is
          on the Transactions screen, and what a dashboard is asked is "are
          we growing", which a day cannot answer.
        -->
        <AppCard
          v-if="revenue"
          :title="t('ui.dashboard.money_in')"
          :description="t('ui.dashboard.money_in_total', { total: revenue.total })"
        >
          <AppBarChart
            :title="t('ui.dashboard.money_in_chart')"
            hide-title
            :rows="revenue.months"
            :format="formatMinor"
          />
        </AppCard>

        <AppCard v-if="infrastructure" :title="t('ui.dashboard.infrastructure')">
          <template #actions>
            <!-- The health page runs the checks. This block reads rows, so
                 that a dashboard stays openable during the incident it is
                 wanted for. -->
            <Link
              href="/admin/health"
              class="text-brand text-chrome inline-flex items-center gap-1 underline-offset-4 hover:underline"
            >
              {{ t('ui.dashboard.run_health_checks') }}
              <AppIcon name="chevronRight" :size="12" />
            </Link>
          </template>

          <DescriptionList :items="infrastructureFacts">
            <template #servers>
              <AppStatus
                :tone="serverTone"
                :label="
                  infrastructure.servers === 0
                    ? t('ui.dashboard.no_servers')
                    : t('ui.dashboard.servers_available', {
                        active: infrastructure.active,
                        total: infrastructure.servers,
                      })
                "
              />
            </template>
          </DescriptionList>
        </AppCard>
      </div>

      <!--
        `AppCard flush` + `AppTable flush`: the card keeps the frame and the
        table gives its own up, so the result is one rectangle with a header
        above the column names rather than the card-in-a-card the design
        system refuses.
      -->
      <AppCard
        v-if="activity"
        :title="t('ui.dashboard.activity')"
        :description="t('ui.dashboard.activity_description')"
        :flush="activity.length > 0"
      >
        <AppTable v-if="activity.length > 0" flush :columns="activityColumns">
          <tr v-for="entry in activity" :key="entry.id">
            <td data-col="at" class="text-content-muted w-0 whitespace-nowrap tabular-nums">
              {{ formatTime(entry.at) }}
            </td>
            <td data-col="action" class="font-medium whitespace-nowrap">
              {{ readAction(entry.action) }}
            </td>
            <td data-col="target" class="text-content-muted max-w-[24rem] truncate">
              {{ entry.target ?? '—' }}
            </td>
            <td data-col="actor" class="text-content-muted">
              {{ readActor(entry.actor) }}
              <span v-if="entry.reason" class="text-content-subtle">— {{ entry.reason }}</span>
            </td>
          </tr>
        </AppTable>

        <EmptyState
          v-else
          icon="history"
          :title="t('ui.dashboard.activity_empty')"
          :description="t('ui.dashboard.activity_empty_detail')"
        />
      </AppCard>
    </div>
  </AdminLayout>
</template>
