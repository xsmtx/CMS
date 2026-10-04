<script setup lang="ts">
/**
 * One service: where it lives, what it costs, and what has been done to it.
 *
 * Laid out as a resource page (enterprise-cms-ux, "Detail pages"):
 *
 * 1. **Identity** — the name, the status, and the everyday actions. Set up,
 *    Suspend, Unsuspend and Sync are in the header band rather than in a
 *    panel down the side: they are what an operator opens this screen to do.
 * 2. **Where it is** — domain, package, server and account across the top, as
 *    a `DescriptionList` grid. Four facts read across, not down.
 * 3. **The record** — billing, the options that were bought, and every
 *    provisioning attempt with its outcome.
 * 4. **Danger zone** — termination, last on the page, behind a reason and the
 *    service's own name typed out.
 *
 * Changing plan is a panel on this screen rather than a screen of its own: it
 * is a thing somebody does *to a service*, and a second screen would be a
 * second place to look for it. The figure is previewed before anything is
 * charged, by the same arithmetic that charges it.
 *
 * Suspending asks for a reason and will not proceed without one. The server
 * accepts an empty one; this screen does not, because the reason is what the
 * customer reads when they ask why their site stopped answering.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { asTone, statusTone } from '../../../status'

interface ServiceEvent {
  id: string
  operation: string
  outcome: string
  outcomeLabel: string
  actor: string | null
  message: string | null
  occurredAt: string
}

interface UpgradeTarget {
  id: string
  name: string
  current: boolean
  cycles: { value: string; label: string; price: string }[]
}

interface OpenUpgrade {
  id: string
  to: string
  cycleLabel: string
  status: string
  statusLabel: string
  /** Decided by the server, so `asTone` reads it rather than `statusTone`. */
  tone: string
  difference: string
  invoiceId: string | null
  canWithdraw: boolean
}

interface UpgradePreview {
  product: string
  productName: string
  cycle: string
  cycleLabel: string
  credit: string
  charge: string
  difference: string
  differenceMinor: number
  daysRemaining: number
  termDays: number
  restartsTerm: boolean
  isFree: boolean
  isUpgrade: boolean
}

interface PlacementFactor {
  factor: string
  label: string
  /** Whether this came from a monitoring system or from a row this product owns. */
  measured: boolean
  score: number
  weight: number
  measure: number | null
  /** True when nothing reported it and the other candidates' average was used. */
  assumed: boolean
}

const props = defineProps<{
  placement: {
    strategy: string
    strategyLabel: string
    server: string
    score: number | null
    candidates: number
    decidedAt: string
    factors: PlacementFactor[]
  } | null
  service: {
    id: string
    name: string
    status: string
    statusLabel: string
    customer: string | null
    customerId: string | null
    domain: string | null
    server: string | null
    recurring: string
    nextDueOn: string | null
    package: string | null
    module: string | null
    externalId: string | null
    username: string | null
    orderNumber: string | null
    startsOn: string | null
    provisionedAt: string | null
    suspensionReason: string | null
    failureReason: string | null
    syncedAt: string | null
    options: { group: string; label: string }[]
    events: ServiceEvent[]
    transitions: { value: string; label: string }[]
  }
  upgrade: {
    targets: UpgradeTarget[]
    open: OpenUpgrade | null
  }
  can: {
    update: boolean
    upgrade: boolean
    provision: boolean
    suspend: boolean
    unsuspend: boolean
    terminate: boolean
    sync: boolean
  }
}>()

const page = usePage()
const { t } = useTranslations()

const suspending = ref(false)
const terminating = ref(false)
const changingPlan = ref(false)
const withdrawing = ref(false)

const planForm = useForm({ product: '', cycle: '', note: '' })

/** What the server worked out, kept in the flash bag between the two posts. */
const preview = computed<UpgradePreview | null>(
  () =>
    (page.props.flash as { upgradePreview?: UpgradePreview } | undefined)?.upgradePreview ?? null,
)

/** The cycles the chosen plan is actually sold in, in this currency. */
const planCycles = computed(
  () => props.upgrade.targets.find((target) => target.id === planForm.product)?.cycles ?? [],
)

/*
 * Re-point the cycle when the plan changes, or the select keeps a cycle the
 * new plan is not sold in and the server refuses something the form offered.
 */
function choosePlan(value: string): void {
  planForm.product = value
  planForm.cycle = planCycles.value[0]?.value ?? ''
}

function previewPlan(): void {
  planForm.post(`/admin/services/${props.service.id}/upgrade/preview`, { preserveScroll: true })
}

function changePlan(): void {
  planForm.post(`/admin/services/${props.service.id}/upgrade`, {
    preserveScroll: true,
    onSuccess: () => {
      planForm.reset()
      changingPlan.value = false
    },
  })
}

function withdrawPlan(): void {
  const open = props.upgrade.open

  withdrawing.value = false

  if (open === null) return

  router.delete(`/admin/services/upgrades/${open.id}`, { preserveScroll: true })
}
const terminatingBusy = ref(false)
const suspendForm = useForm({ operation: 'suspend', reason: '' })
const statusForm = useForm({
  status: props.service.transitions[0]?.value ?? '',
  reason: '',
})

// Flashed by the credentials endpoint, so reading somebody's control panel
// password is an action in the audit log rather than a side effect of
// opening this page.
const credentials = computed(
  () => page.props.flash.credentials as { username: string | null; password: string | null } | null,
)

/** Where the account lives, read across rather than down. */
const hasAside = computed(
  () =>
    props.can.update && (props.service.username !== null || props.service.transitions.length > 0),
)

const PLACEMENT_COLUMNS: TableColumn[] = [
  { key: 'factor', label: t('provisioning.placement.factor') },
  { key: 'reading', label: t('provisioning.placement.reading') },
  { key: 'weight', label: t('provisioning.placement.weight'), numeric: true },
  { key: 'score', label: t('provisioning.placement.score'), numeric: true },
]

const placementSummary = computed<DescriptionItem[]>(() => [
  {
    key: 'chosen_by',
    label: t('provisioning.placement.chosen_by'),
    value: props.placement?.strategyLabel ?? null,
  },
  {
    key: 'score',
    label: t('provisioning.placement.score'),
    value: props.placement?.score === null ? null : percent(props.placement?.score ?? 0),
  },
  {
    key: 'candidates',
    label: t('provisioning.placement.candidates'),
    value: String(props.placement?.candidates ?? 0),
  },
  {
    key: 'decided_at',
    label: t('provisioning.placement.decided_at'),
    value: formatDateTime(props.placement?.decidedAt ?? null),
  },
])

function percent(ratio: number): string {
  return `${(ratio * 100).toFixed(1)}%`
}

/**
 * The reading in the factor's own units.
 *
 * A ratio is a percentage, days are days, and a count is a count. An operator
 * reading this panel at two in the morning is checking the platform's arithmetic
 * against what they can see in their own monitoring system, so the number has to
 * be the one that system would show them.
 */
function reading(factor: PlacementFactor): string {
  if (factor.assumed) return t('provisioning.placement.assumed_value')
  if (factor.measure === null) return '—'

  if (factor.factor === 'cpu' || factor.factor === 'memory' || factor.factor === 'disk') {
    return percent(factor.measure)
  }

  if (factor.factor === 'growth') {
    return t('infrastructure.capacity.in_days', { days: Math.round(factor.measure) })
  }

  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(factor.measure)
}

const location = computed<DescriptionItem[]>(() => [
  { key: 'domain', label: t('ui.service.domain'), value: props.service.domain },
  { key: 'package', label: t('ui.service.package'), value: props.service.package },
  { key: 'server', label: t('ui.service.server'), value: props.service.server },
  { key: 'account', label: t('ui.service.account'), value: props.service.externalId, mono: true },
])

const billing = computed<DescriptionItem[]>(() => [
  { key: 'recurring', label: t('ui.service.recurring'), value: props.service.recurring },
  {
    key: 'next_due',
    label: t('ui.service.next_due'),
    value: formatDate(props.service.nextDueOn),
  },
  { key: 'order', label: t('ui.service.order'), value: props.service.orderNumber },
  {
    key: 'set_up',
    label: t('ui.service.set_up'),
    value: formatDateTime(props.service.provisionedAt),
  },
  { key: 'synced', label: t('ui.service.synced'), value: formatDateTime(props.service.syncedAt) },
])

function provision(): void {
  router.post(`/admin/services/${props.service.id}/provision`, {}, { preserveScroll: true })
}

function act(operation: string): void {
  router.post(
    `/admin/services/${props.service.id}/actions`,
    { operation },
    { preserveScroll: true },
  )
}

function suspend(reason: string | null): void {
  suspendForm.reason = reason ?? ''
  suspendForm.post(`/admin/services/${props.service.id}/actions`, {
    preserveScroll: true,
    onSuccess: () => {
      suspending.value = false
      suspendForm.reset()
    },
  })
}

/**
 * Level 4 (§8): a reason, and the service's own name typed out.
 *
 * It used to be a sentence and two buttons in the same row as Suspend and
 * Sync. That is a button muscle memory reaches on a Friday afternoon —
 * distance and a different surface are what make the hand stop, and typing
 * the name is the only guard there is nothing to click through.
 *
 * The reason goes to the audit record and to the operation's own row, which
 * is where somebody will look when the customer asks why their account is
 * gone.
 */
function terminate(reason: string | null): void {
  terminatingBusy.value = true

  router.post(
    `/admin/services/${props.service.id}/actions`,
    { operation: 'terminate', reason },
    {
      preserveScroll: true,
      onFinish: () => {
        terminatingBusy.value = false
        terminating.value = false
      },
    },
  )
}

function revealCredentials(): void {
  router.post(`/admin/services/${props.service.id}/credentials`, {}, { preserveScroll: true })
}

function changeStatus(): void {
  statusForm.put(`/admin/services/${props.service.id}/status`, { preserveScroll: true })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="service.name" />

  <AdminLayout :heading="service.name">
    <template #header>
      <PageHeader :title="service.name">
        <template #status>
          <AppStatus :tone="statusTone(service.status)" :label="service.statusLabel" />
        </template>

        <template #meta>
          <Link
            v-if="service.customerId"
            :href="`/admin/customers/${service.customerId}`"
            class="hover:text-content underline underline-offset-4"
          >
            {{ service.customer }}
          </Link>
          <span v-else-if="service.customer">{{ service.customer }}</span>
          <template v-if="service.module">
            <span aria-hidden="true">·</span>
            <span>{{ service.module }}</span>
          </template>
          <span aria-hidden="true">·</span>
          <span class="tabular-nums">{{ service.recurring }}</span>
        </template>

        <!--
          What an operator opens this screen to do. Terminate is deliberately
          absent: it lives in the danger zone at the foot of the page, away
          from the buttons that get pressed every day.
        -->
        <template #actions>
          <AppButton v-if="can.sync" variant="ghost" icon="sync" @click="act('sync')">
            {{ t('ui.service.sync') }}
          </AppButton>
          <AppButton
            v-if="can.unsuspend && service.status === 'suspended'"
            @click="act('unsuspend')"
          >
            {{ t('ui.service.unsuspend') }}
          </AppButton>
          <AppButton v-if="can.suspend && service.status === 'active'" @click="suspending = true">
            {{ t('ui.service.suspend') }}
          </AppButton>
          <AppButton v-if="can.provision" variant="primary" icon="check" @click="provision">
            {{ t('ui.service.provision') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="service.failureReason" tone="danger">
        {{ service.failureReason }}
      </AppAlert>
      <AppAlert v-else-if="service.suspensionReason" tone="warning">
        {{ service.suspensionReason }}
      </AppAlert>

      <!-- Where the account actually is. Four facts across the top, in the
           order somebody looking for it would ask them. -->
      <DescriptionList :items="location" layout="grid" :columns="4">
        <template #account>
          <AppCopy
            v-if="service.externalId"
            :value="service.externalId"
            :noun="t('ui.service.account')"
            mono
          />
          <span v-else>—</span>
        </template>
      </DescriptionList>

      <div
        class="grid gap-x-10 gap-y-8"
        :class="hasAside ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]' : ''"
      >
        <div class="flex min-w-0 flex-col gap-8">
          <DetailSection :title="t('ui.service.billing')">
            <DescriptionList :items="billing" />
          </DetailSection>

          <DetailSection
            :title="t('ui.service.activity')"
            :description="t('ui.service.activity_intro')"
          >
            <ul v-if="service.events.length > 0" class="divide-line-subtle divide-y">
              <li v-for="event in service.events" :key="event.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-body flex flex-wrap items-center gap-x-2 font-medium">
                      <span>{{ event.operation }}</span>
                      <AppStatus :tone="statusTone(event.outcome)" :label="event.outcomeLabel" />
                    </p>
                    <p
                      v-if="event.message"
                      class="text-content-muted text-chrome mt-1 leading-relaxed break-words"
                    >
                      {{ event.message }}
                    </p>
                    <p v-if="event.actor" class="text-content-subtle text-chrome mt-1">
                      {{ event.actor }}
                    </p>
                  </div>
                  <p class="text-content-subtle text-chrome shrink-0 whitespace-nowrap">
                    {{ formatDateTime(event.occurredAt) }}
                  </p>
                </div>
              </li>
            </ul>
            <EmptyState
              v-else
              variant="plain"
              icon="history"
              :title="t('ui.service.no_activity')"
              :description="t('ui.service.no_activity_detail')"
            />
          </DetailSection>

          <!--
            Why this node. §4 of the operations handoff calls the persisted,
            explainable reason the deliverable of smart placement rather than a
            nicety, and this is where an operator reads it: worst factor first,
            because a placement is explained by its objections and not by the
            eight things that were fine.
          -->
          <!--
            Changing plan. A panel rather than a screen, because it is a thing
            somebody does to a service — and the figure is previewed by the
            same arithmetic that charges it, so what an operator reads and
            what the customer is invoiced cannot disagree.
          -->
          <DetailSection
            v-if="upgrade.open || (can.upgrade && upgrade.targets.length > 0)"
            :title="t('provisioning.upgrades.title')"
            :description="t('provisioning.upgrades.intro')"
          >
            <!-- One at a time: a second request would be priced against a
                 term the first is about to change. -->
            <div v-if="upgrade.open" class="flex flex-col gap-4">
              <DescriptionList
                :items="[
                  { key: 'to', label: t('provisioning.upgrades.to'), value: upgrade.open.to },
                  {
                    key: 'cycle',
                    label: t('provisioning.upgrades.cycle'),
                    value: upgrade.open.cycleLabel,
                  },
                  {
                    key: 'difference',
                    label: t('provisioning.upgrades.difference'),
                    value: upgrade.open.difference,
                  },
                ]"
              />

              <div class="flex flex-wrap items-center gap-3">
                <AppStatus :tone="asTone(upgrade.open.tone)" :label="upgrade.open.statusLabel" />
                <Link
                  v-if="upgrade.open.invoiceId"
                  :href="`/admin/invoices/${upgrade.open.invoiceId}`"
                  class="text-brand text-chrome hover:underline"
                >
                  {{ t('provisioning.upgrades.invoice') }}
                </Link>
                <AppButton
                  v-if="upgrade.open.canWithdraw && can.upgrade"
                  size="sm"
                  variant="danger-subtle"
                  @click="withdrawing = true"
                >
                  {{ t('provisioning.upgrades.withdraw') }}
                </AppButton>
              </div>
            </div>

            <div v-else-if="!changingPlan">
              <AppButton variant="secondary" @click="changingPlan = true">
                {{ t('provisioning.upgrades.request') }}
              </AppButton>
            </div>

            <form v-else class="flex max-w-xl flex-col gap-4" @submit.prevent="previewPlan">
              <AppSelect
                :model-value="planForm.product"
                :label="t('provisioning.upgrades.plan')"
                :options="
                  upgrade.targets.map((target) => ({
                    value: target.id,
                    // Named as the current plan rather than marked with a
                    // dash: it is still a valid choice, because moving from
                    // monthly to annual is a change of cycle on the same plan.
                    label: target.current
                      ? t('provisioning.upgrades.current', { name: target.name })
                      : target.name,
                  }))
                "
                :error="planForm.errors.product"
                @update:model-value="choosePlan(String($event))"
              />

              <!-- Only the cycles this plan is sold in, in this service's own
                   currency: there is no exchange rate in this product. -->
              <AppSelect
                v-model="planForm.cycle"
                :label="t('provisioning.upgrades.cycle')"
                :options="
                  planCycles.map((cycle) => ({
                    value: cycle.value,
                    label: `${cycle.label} · ${cycle.price}`,
                  }))
                "
                :error="planForm.errors.cycle"
              />

              <AppInput v-model="planForm.note" :label="t('provisioning.upgrades.note')" />

              <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="secondary" :loading="planForm.processing">
                  {{ t('provisioning.upgrades.request_submit') }}
                </AppButton>
                <AppButton variant="ghost" @click="changingPlan = false">
                  {{ t('ui.confirm.cancel') }}
                </AppButton>
              </div>

              <!--
                The arithmetic, shown before anybody is charged. The two lines
                are what the invoice will say and they sum to the figure that
                is charged, by construction.
              -->
              <div
                v-if="preview && preview.product === planForm.product"
                class="border-line bg-surface-primary flex flex-col gap-3 rounded-lg border p-4"
              >
                <DescriptionList
                  :items="[
                    {
                      key: 'days',
                      label: t('provisioning.upgrades.days'),
                      value: String(preview.daysRemaining),
                    },
                    {
                      key: 'credit',
                      label: t('provisioning.upgrades.credit'),
                      value: preview.credit,
                    },
                    {
                      key: 'charge',
                      label: t('provisioning.upgrades.charge'),
                      value: preview.charge,
                    },
                    {
                      key: 'difference',
                      label: preview.isUpgrade
                        ? t('provisioning.upgrades.difference')
                        : t('provisioning.upgrades.refund_due'),
                      value: preview.difference,
                    },
                  ]"
                />

                <p class="text-content-muted text-chrome max-w-[70ch]">
                  {{
                    preview.restartsTerm
                      ? t('provisioning.upgrades.restarts_detail')
                      : t('provisioning.upgrades.same_term_detail')
                  }}
                </p>

                <p v-if="preview.isFree" class="text-content-muted text-chrome max-w-[70ch]">
                  {{ t('provisioning.upgrades.free_detail') }}
                </p>

                <div>
                  <AppButton variant="primary" :loading="planForm.processing" @click="changePlan">
                    {{ t('provisioning.upgrades.confirm_submit') }}
                  </AppButton>
                </div>
              </div>
            </form>
          </DetailSection>

          <DetailSection
            v-if="placement"
            :title="t('provisioning.placement.title')"
            :description="t('provisioning.placement.intro')"
          >
            <div class="flex flex-col gap-5">
              <DescriptionList :items="placementSummary" />

              <AppTable
                v-if="placement.factors.length > 0"
                name="placement"
                :columns="PLACEMENT_COLUMNS"
              >
                <AppTableRow v-for="factor in placement.factors" :key="factor.factor">
                  <td data-col="factor">
                    <span class="font-medium">{{ factor.label }}</span>
                    <span class="text-content-subtle text-chrome block">
                      {{
                        factor.measured
                          ? t('provisioning.placement.measured')
                          : t('provisioning.placement.stated')
                      }}
                    </span>
                  </td>
                  <td data-col="reading" class="tabular-nums">{{ reading(factor) }}</td>
                  <td data-col="weight" class="numeric tabular-nums">{{ factor.weight }}</td>
                  <td data-col="score" class="numeric tabular-nums">{{ percent(factor.score) }}</td>
                </AppTableRow>
              </AppTable>

              <p v-else class="text-content-muted text-chrome max-w-[70ch]">
                {{ t('provisioning.placement.unscored') }}
              </p>
            </div>
          </DetailSection>

          <DetailSection
            v-if="service.options.length > 0"
            :title="t('ui.service.configuration')"
            :description="t('ui.service.configuration_intro')"
          >
            <DescriptionList
              :items="
                service.options.map((option) => ({
                  key: option.group + option.label,
                  label: option.group,
                  value: option.label,
                }))
              "
            />
          </DetailSection>
        </div>

        <aside v-if="hasAside" class="flex min-w-0 flex-col gap-8">
          <DetailSection
            v-if="can.update && service.username"
            :title="t('ui.service.credentials')"
            :description="t('ui.service.credentials_note')"
          >
            <DescriptionList
              v-if="credentials"
              :items="[
                {
                  key: 'username',
                  label: t('ui.service.username'),
                  value: credentials.username,
                  mono: true,
                },
                {
                  key: 'password',
                  label: t('ui.service.password'),
                  value: credentials.password,
                  mono: true,
                },
              ]"
            />
            <div v-else>
              <AppButton icon="security" @click="revealCredentials">
                {{ t('ui.service.reveal') }}
              </AppButton>
            </div>
          </DetailSection>

          <DetailSection
            v-if="can.update && service.transitions.length > 0"
            :title="t('ui.service.change_status')"
            :description="t('ui.service.change_status_intro')"
          >
            <form class="flex flex-col gap-3" @submit.prevent="changeStatus">
              <AppSelect
                v-model="statusForm.status"
                :label="t('ui.service.new_status')"
                :options="service.transitions"
                :error="statusForm.errors.status"
              />
              <AppInput
                v-model="statusForm.reason"
                :label="t('ui.service.reason')"
                :error="statusForm.errors.reason"
                :hint="t('ui.service.reason_hint')"
              />
              <div>
                <AppButton type="submit" variant="primary" :loading="statusForm.processing">
                  {{ t('ui.service.apply') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>
        </aside>
      </div>
    </div>

    <!--
      Last on the page, always. Anything below this would be a reason to
      scroll past it, and a thing people scroll past is a thing they stop
      reading.
    -->
    <DangerZone v-if="can.terminate" :description="t('ui.service.danger_intro')">
      <DangerZoneRow
        :title="t('ui.service.terminate_title')"
        :description="t('ui.service.terminate_detail')"
      >
        <AppButton variant="danger-subtle" @click="terminating = true">
          {{ t('ui.service.terminate_button') }}
        </AppButton>
      </DangerZoneRow>
    </DangerZone>

    <AppConfirm
      v-model:open="suspending"
      level="high-risk"
      :title="t('ui.service.suspend_title', { name: service.name })"
      :description="t('ui.service.suspend_detail')"
      :confirm-label="t('ui.service.suspend_confirm')"
      :busy="suspendForm.processing"
      @confirm="suspend"
    />

    <AppConfirm
      v-model:open="terminating"
      level="destructive"
      :title="t('ui.service.terminate_confirm_title', { name: service.name })"
      :description="t('ui.service.terminate_confirm_detail')"
      :phrase="service.name"
      :confirm-label="t('ui.service.terminate_confirm')"
      :busy="terminatingBusy"
      @confirm="terminate"
    />

    <p v-if="suspendForm.errors.reason" class="text-danger text-chrome mt-2" role="alert">
      {{ suspendForm.errors.reason }}
    </p>
    <AppConfirm
      :open="withdrawing"
      level="consequential"
      :title="t('provisioning.upgrades.withdraw')"
      :description="t('provisioning.upgrades.withdraw_body')"
      :confirm-label="t('provisioning.upgrades.withdraw')"
      @close="withdrawing = false"
      @confirm="withdrawPlan"
    />
  </AdminLayout>
</template>
