<script setup lang="ts">
/**
 * One order: what was bought, what it came to, and whether it may proceed.
 *
 * Laid out as a resource page (enterprise-cms-ux, "Detail pages"):
 *
 * 1. **Identity** — the number, the status, who placed it, and Raise invoice
 *    as the one primary action while there is no invoice.
 * 2. **Figures** — what is due now and what it renews at, side by side. An
 *    order's total and its recurring total are different numbers and the
 *    second is the one people forget to look at.
 * 3. **The order** — the lines with their options and addons, its totals, and
 *    the history of every status it has been in.
 * 4. **Decisions** — risk, invoice and status in the aside, where work is done.
 *
 * There is no Danger Zone here on purpose. The one irreversible action is
 * refusing an order held for review, and that is one half of a single
 * decision: an operator reads the risk reasons and answers release or refuse.
 * Moving the refusal to the foot of the page would separate the answer from
 * the question it answers, which is the opposite of what a danger zone is for.
 * The entry is `danger-subtle` and the solid press is inside the confirmation.
 */
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface OrderOption {
  group: string
  label: string
  amount: string | null
}

interface OrderLine {
  id: string
  kind: string
  name: string
  groupName: string | null
  cycleLabel: string | null
  quantity: number
  unitRecurring: string
  lineSetup: string | null
  lineDiscount: string | null
  lineTotal: string
  domain: string | null
  options: OrderOption[]
  children: OrderLine[]
}

interface HistoryEntry {
  from: string | null
  to: string
  toLabel: string
  actor: string | null
  reason: string | null
  occurredAt: string
}

const props = defineProps<{
  order: {
    id: string
    number: string
    customer: string | null
    contact: string | null
    status: string
    statusLabel: string
    currency: string
    total: string
    subtotal: string
    discount: string
    setup: string
    tax: string
    recurringTotal: string
    promotionCode: string | null
    placedAt: string | null
    termsAcceptedAt: string | null
    termsVersion: string | null
    ipAddress: string | null
    notes: string | null
    taxBreakdown: { name: string; rate: string }[]
    riskDecision: string | null
    riskScore: number | null
    riskReasons: string[]
    riskReviewedAt: string | null
    riskReviewedBy: string | null
    items: OrderLine[]
    history: HistoryEntry[]
    transitions: { value: string; label: string }[]
  }
  invoice: {
    id: string
    number: string
    status: string
    statusLabel: string
    balance: string
  } | null
  can: { update: boolean; review: boolean; invoice: boolean }
}>()

const { t } = useTranslations()

const heading = computed(() => t('ui.order.title', { number: props.order.number }))

const statusForm = useForm({
  status: props.order.transitions[0]?.value ?? '',
  reason: '',
})

const reviewForm = useForm({ reason: '' })
const invoiceForm = useForm({})

/** Which half of the review decision is being confirmed, if either. */
const deciding = ref<'release' | 'refuse' | null>(null)

const heldForReview = computed(() => props.can.review && props.order.status === 'fraud_review')

/**
 * What is due now, and what it will be every cycle after that. A one-time
 * order renews at zero and says so — an absent line would read as unknown.
 */
const figures = computed<Metric[]>(() => {
  const items: Metric[] = [
    { key: 'total', label: t('ui.order.total'), value: props.order.total },
    { key: 'recurring', label: t('ui.order.renews_at'), value: props.order.recurringTotal },
  ]

  if (props.invoice) {
    items.push({
      key: 'outstanding',
      label: t('ui.order.outstanding'),
      value: props.invoice.balance,
      hint: props.invoice.number,
      href: `/admin/invoices/${props.invoice.id}`,
    })
  }

  return items
})

const placement = computed<DescriptionItem[]>(() => [
  { key: 'placed', label: t('ui.order.placed'), value: formatDateTime(props.order.placedAt) },
  { key: 'contact', label: t('ui.order.contact'), value: props.order.contact },
  { key: 'terms', label: t('ui.order.terms') },
  { key: 'from', label: t('ui.order.from'), value: props.order.ipAddress, mono: true },
])

function raiseInvoice(): void {
  invoiceForm.post(`/admin/orders/${props.order.id}/invoice`)
}

function changeStatus(): void {
  statusForm.put(`/admin/orders/${props.order.id}/status`, { preserveScroll: true })
}

/**
 * Both halves of the review post the same payload to different routes, and
 * the reason comes from the confirmation rather than from a field on the
 * page — an override has to be explainable later, and the dialog is where
 * the operator is actually committing to it.
 */
function decide(reason: string | null): void {
  const choice = deciding.value

  if (choice === null) return

  reviewForm.reason = reason ?? ''
  reviewForm.post(`/admin/orders/${props.order.id}/${choice}`, {
    preserveScroll: true,
    onSuccess: () => {
      deciding.value = null
    },
  })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="heading" />

  <AdminLayout :heading="heading">
    <template #header>
      <PageHeader :title="heading">
        <template #status>
          <AppStatus :tone="statusTone(order.status)" :label="order.statusLabel" />
        </template>

        <template #meta>
          <span v-if="order.customer">{{ order.customer }}</span>
          <template v-if="order.placedAt">
            <span v-if="order.customer" aria-hidden="true">·</span>
            <span>{{ t('ui.order.placed_on', { date: formatDateTime(order.placedAt) }) }}</span>
          </template>
        </template>

        <template v-if="can.invoice && !invoice" #actions>
          <AppButton
            variant="primary"
            icon="invoice"
            :loading="invoiceForm.processing"
            @click="raiseInvoice"
          >
            {{ t('ui.order.raise_invoice') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="order.notes" tone="info">{{ order.notes }}</AppAlert>

      <MetricStrip :items="figures" />

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div class="flex min-w-0 flex-col gap-8">
          <DetailSection :title="t('ui.order.items')">
            <ul class="divide-line-subtle divide-y">
              <li v-for="line in order.items" :key="line.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-body font-medium">
                      {{ line.name }}
                      <span v-if="line.quantity > 1" class="text-content-muted">
                        × {{ line.quantity }}
                      </span>
                    </p>
                    <p class="text-content-muted text-chrome">
                      <span v-if="line.groupName">{{ line.groupName }} · </span>
                      <span v-if="line.cycleLabel">{{ line.cycleLabel }}</span>
                      <span v-if="line.domain"> · {{ line.domain }}</span>
                    </p>

                    <ul v-if="line.options.length > 0" class="mt-2 space-y-0.5">
                      <li
                        v-for="option in line.options"
                        :key="`${line.id}-${option.group}`"
                        class="text-content-muted text-chrome"
                      >
                        {{ option.group }}: {{ option.label }}
                        <span v-if="option.amount" class="tabular-nums">
                          ({{ option.amount }})
                        </span>
                      </li>
                    </ul>

                    <!--
                      Addons, one level down and no further: an order line is a
                      product with its addons under it, and nothing loads a
                      third level (ADR 0035).
                    -->
                    <ul v-if="line.children.length > 0" class="mt-2 space-y-0.5">
                      <li
                        v-for="child in line.children"
                        :key="child.id"
                        class="text-content-muted text-chrome"
                      >
                        + {{ child.name }}
                        <span class="tabular-nums">{{ child.lineTotal }}</span>
                      </li>
                    </ul>
                  </div>

                  <div class="shrink-0 text-right whitespace-nowrap">
                    <p class="text-body tabular-nums">{{ line.lineTotal }}</p>
                    <p v-if="line.lineSetup" class="text-content-subtle text-chrome">
                      {{ t('ui.order.setup_included', { amount: line.lineSetup }) }}
                    </p>
                    <p v-if="line.lineDiscount" class="text-success text-chrome">
                      −{{ line.lineDiscount }}
                    </p>
                  </div>
                </div>
              </li>
            </ul>

            <!--
              The totals read against the amounts above them, so they sit in a
              narrow column on the right rather than in a second framed box.
            -->
            <dl
              class="border-line divide-line-subtle text-body mt-4 divide-y border-t pt-1 lg:ml-auto lg:w-80"
            >
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">{{ t('ui.order.subtotal') }}</dt>
                <dd class="tabular-nums">{{ order.subtotal }}</dd>
              </div>
              <div v-if="order.promotionCode" class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">
                  {{ t('ui.order.discount_with_code', { code: order.promotionCode }) }}
                </dt>
                <dd class="text-success tabular-nums">−{{ order.discount }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">{{ t('ui.order.setup') }}</dt>
                <dd class="tabular-nums">{{ order.setup }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">
                  {{ t('ui.order.tax') }}
                  <span v-if="order.taxBreakdown.length > 0" class="text-content-subtle">
                    ({{ order.taxBreakdown[0]?.name }} {{ order.taxBreakdown[0]?.rate }}%)
                  </span>
                </dt>
                <dd class="tabular-nums">{{ order.tax }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-2 font-medium">
                <dt>{{ t('ui.order.total') }}</dt>
                <dd class="tabular-nums">{{ order.total }}</dd>
              </div>
            </dl>
          </DetailSection>

          <DetailSection :title="t('ui.order.history')" :description="t('ui.order.history_intro')">
            <ol class="divide-line-subtle divide-y">
              <li
                v-for="(entry, index) in order.history"
                :key="index"
                class="text-body flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0"
              >
                <span class="min-w-0">
                  {{ entry.toLabel }}
                  <span v-if="entry.reason" class="text-content-muted text-chrome mt-0.5 block">
                    {{ entry.reason }}
                  </span>
                </span>
                <span class="text-content-muted text-chrome shrink-0 text-right whitespace-nowrap">
                  {{ formatDateTime(entry.occurredAt) }}
                  <span v-if="entry.actor" class="text-content-subtle block">
                    {{ entry.actor }}
                  </span>
                </span>
              </li>
            </ol>
          </DetailSection>
        </div>

        <aside class="flex min-w-0 flex-col gap-8">
          <DetailSection v-if="order.riskDecision" :title="t('ui.order.risk')">
            <template #actions>
              <AppStatus
                :tone="statusTone(order.riskDecision)"
                :label="t(`ui.order.risk_decisions.${order.riskDecision}`)"
              />
            </template>

            <ul
              v-if="order.riskReasons.length > 0"
              class="text-content-muted text-body list-disc space-y-1 pl-4"
            >
              <li v-for="(reason, index) in order.riskReasons" :key="index">{{ reason }}</li>
            </ul>
            <p v-else class="text-content-muted text-body">{{ t('ui.order.risk_nothing') }}</p>

            <p v-if="order.riskReviewedAt" class="text-content-subtle text-chrome mt-3">
              {{ t('ui.order.risk_reviewed', { date: formatDateTime(order.riskReviewedAt) }) }}
              <span v-if="order.riskReviewedBy">
                {{ t('ui.order.risk_reviewed_by', { name: order.riskReviewedBy }) }}
              </span>
            </p>

            <!--
              One decision with two answers, so both live here. Refuse is the
              entry to something irreversible and is `danger-subtle`; the solid
              red press is inside the confirmation, which is also where the
              reason is written.
            -->
            <div v-if="heldForReview" class="mt-4 flex flex-wrap gap-2">
              <AppButton variant="primary" @click="deciding = 'release'">
                {{ t('ui.order.release') }}
              </AppButton>
              <AppButton variant="danger-subtle" @click="deciding = 'refuse'">
                {{ t('ui.order.refuse') }}
              </AppButton>
            </div>
          </DetailSection>

          <DetailSection v-if="invoice || can.invoice" :title="t('ui.order.invoice')">
            <template v-if="invoice">
              <div class="flex items-baseline justify-between gap-3">
                <Link
                  :href="`/admin/invoices/${invoice.id}`"
                  class="text-body font-medium underline-offset-4 hover:underline"
                >
                  {{ invoice.number }}
                </Link>
                <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
              </div>
              <p class="text-content-muted text-chrome mt-2">
                {{ t('ui.order.outstanding') }}
                <span class="tabular-nums">{{ invoice.balance }}</span>
              </p>
            </template>

            <p v-else class="text-content-muted text-body">{{ t('ui.order.no_invoice') }}</p>
          </DetailSection>

          <DetailSection
            v-if="can.update && order.transitions.length > 0"
            :title="t('ui.order.change_status')"
          >
            <form class="flex flex-col gap-3" @submit.prevent="changeStatus">
              <AppSelect
                v-model="statusForm.status"
                :label="t('ui.order.new_status')"
                :options="order.transitions"
                :error="statusForm.errors.status"
              />
              <AppInput
                v-model="statusForm.reason"
                :label="t('ui.order.reason')"
                :error="statusForm.errors.reason"
                :hint="t('ui.order.reason_hint')"
              />
              <div>
                <AppButton type="submit" variant="primary" :loading="statusForm.processing">
                  {{ t('ui.order.apply') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>

          <DetailSection :title="t('ui.order.placement')">
            <DescriptionList :items="placement">
              <template #terms>
                <span v-if="order.termsVersion">
                  {{ order.termsVersion }}
                  <span v-if="order.termsAcceptedAt" class="text-content-muted">
                    · {{ formatDateTime(order.termsAcceptedAt) }}
                  </span>
                </span>
                <span v-else>—</span>
              </template>
            </DescriptionList>
          </DetailSection>
        </aside>
      </div>
    </div>

    <AppConfirm
      :open="deciding === 'release'"
      level="high-risk"
      :title="t('ui.order.release_title')"
      :description="t('ui.order.release_detail')"
      :confirm-label="t('ui.order.release_confirm')"
      :busy="reviewForm.processing"
      @update:open="(value: boolean) => (deciding = value ? deciding : null)"
      @confirm="decide"
    />

    <AppConfirm
      :open="deciding === 'refuse'"
      level="high-risk"
      :title="t('ui.order.refuse_title')"
      :description="t('ui.order.refuse_detail')"
      :confirm-label="t('ui.order.refuse_confirm')"
      :busy="reviewForm.processing"
      @update:open="(value: boolean) => (deciding = value ? deciding : null)"
      @confirm="decide"
    />

    <p v-if="reviewForm.errors.reason" class="text-danger text-chrome mt-2" role="alert">
      {{ reviewForm.errors.reason }}
    </p>
  </AdminLayout>
</template>
