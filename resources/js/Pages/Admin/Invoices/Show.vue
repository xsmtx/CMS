<script setup lang="ts">
/**
 * One invoice: what it says, what has been paid against it, and what is left.
 *
 * Laid out as a resource page (enterprise-cms-ux, "Detail pages"):
 *
 * 1. **Identity** — the number, the status, who it is for and when it is due,
 *    with Issue as the one primary action while it is still a draft.
 * 2. **Figures** — total, paid and outstanding in one `MetricStrip`, because
 *    the first question anybody opens an invoice with is how much is left.
 * 3. **The document** — items and their totals, the payments against it, the
 *    ledger rows behind those payments. The bill-to party and the three ways
 *    money can move sit in the aside, where work is done rather than read.
 * 4. **Danger zone** — cancelling the draft, last on the page.
 *
 * Nothing here decides anything about money. Every figure arrives formatted
 * from the server (`Money` is integer minor units and has no `toFloat()`), and
 * the only arithmetic in this file is the refundable amount the server already
 * worked out.
 */
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import MoneyInput from '../../../Components/MoneyInput.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface InvoiceLine {
  id: string
  description: string
  detail: string | null
  quantity: number
  unitAmount: string
  lineAmount: string
  discount: string | null
}

interface InvoicePayment {
  id: string
  gateway: string
  status: string
  statusLabel: string
  amount: string
  refunded: string | null
  refundable: number
  reference: string | null
  receivedAt: string | null
  note: string | null
}

interface LedgerRow {
  kindLabel: string
  amount: string
  increases: boolean
  description: string | null
  occurredAt: string
}

const props = defineProps<{
  invoice: {
    id: string
    number: string
    customer: string | null
    customerId: string | null
    status: string
    statusLabel: string
    currency: string
    total: string
    paid: string
    balance: string
    balanceMinor: number
    subtotal: string
    discount: string | null
    tax: string | null
    issuedOn: string | null
    dueOn: string | null
    isPastDue: boolean
    isProforma: boolean
    orderNumber: string | null
    orderId: string | null
    notes: string | null
    terms: string | null
    billTo: {
      name: string | null
      company: string | null
      taxId: string | null
      address: string | null
      country: string | null
      email: string | null
    }
    items: InvoiceLine[]
    payments: InvoicePayment[]
    ledger: LedgerRow[]
    creditNotes: { number: string; amount: string; reason: string; issuedOn: string }[]
    creditBalance: string | null
    creditBalanceMinor: number
  }
  gateways: { value: string; label: string }[]
  can: { update: boolean; recordPayment: boolean; refund: boolean; credit: boolean }
}>()

const { t } = useTranslations()

const isDraft = computed(() => props.invoice.status === 'draft')
const owes = computed(() => props.invoice.balanceMinor > 0)

const heading = computed(() => t('ui.invoice.title', { number: props.invoice.number }))

const paymentForm = useForm({
  amount_minor: props.invoice.balanceMinor > 0 ? props.invoice.balanceMinor : 0,
  gateway: props.gateways[0]?.value ?? 'manual',
  reference: '',
  received_on: new Date().toISOString().slice(0, 10),
  note: '',
})

const creditNoteForm = useForm({ amount_minor: 0, reason: '' })
const applyCreditForm = useForm({ amount_minor: 0, reason: 'Applied to invoice' })
const refundForm = useForm({ amount_minor: 0, reason: '' })

// One form each rather than a fresh `useForm({})` per press: a form built
// inside the handler has no `processing` anybody can watch, so the button
// stays pressable while the request is in flight.
const issueForm = useForm({})
const cancelForm = useForm({})

const issuing = ref(false)
const cancelling = ref(false)
const refunding = ref<InvoicePayment | null>(null)
const crediting = ref(false)

/**
 * The three figures the page exists to answer, in one strip.
 *
 * Only the balance carries a tone, and only when there is one: money owed is
 * a state of the invoice. A total is never green — revenue is not a status.
 */
const figures = computed<Metric[]>(() => [
  { key: 'total', label: t('ui.invoice.total'), value: props.invoice.total },
  { key: 'paid', label: t('ui.invoice.paid'), value: props.invoice.paid },
  {
    key: 'balance',
    label: t('ui.invoice.balance'),
    value: props.invoice.balance,
    // When the money is due, beside how much of it there is. A date is not a
    // figure, so it is the hint under the balance rather than a fourth cell.
    hint: owes.value
      ? t('ui.invoice.due_on', { date: formatDate(props.invoice.dueOn) })
      : undefined,
    tone: owes.value ? (props.invoice.isPastDue ? 'critical' : 'warning') : 'healthy',
  },
])

/** The dates and the paper trail. The bill-to party is rendered above it. */
const documentFacts = computed<DescriptionItem[]>(() => {
  return [
    { key: 'issued', label: t('ui.invoice.issued'), value: formatDate(props.invoice.issuedOn) },
    { key: 'due', label: t('ui.invoice.due'), value: formatDate(props.invoice.dueOn) },
  ]
})

function issue(): void {
  issueForm.post(`/admin/invoices/${props.invoice.id}/issue`, {
    preserveScroll: true,
    onSuccess: () => {
      issuing.value = false
    },
  })
}

/**
 * Cancelling is confirmed at `consequential`, not `high-risk`.
 *
 * The button only exists while the invoice is a draft — a document with no
 * number and no legal standing — and the endpoint takes no reason, so asking
 * for one would collect a sentence nothing records.
 */
function cancel(): void {
  cancelForm.post(`/admin/invoices/${props.invoice.id}/cancel`, {
    preserveScroll: true,
    onSuccess: () => {
      cancelling.value = false
    },
  })
}

function recordPayment(): void {
  paymentForm.post(`/admin/invoices/${props.invoice.id}/payments`, { preserveScroll: true })
}

function askToRefund(payment: InvoicePayment): void {
  refundForm.amount_minor = payment.refundable
  refundForm.reason = ''
  refunding.value = payment
}

function refund(reason: string | null): void {
  const payment = refunding.value

  if (payment === null) return

  refundForm.reason = reason ?? ''
  refundForm.post(`/admin/invoices/${props.invoice.id}/payments/${payment.id}/refund`, {
    preserveScroll: true,
    onSuccess: () => {
      refunding.value = null
    },
  })
}

function applyCredit(): void {
  applyCreditForm.post(`/admin/invoices/${props.invoice.id}/credit/apply`, { preserveScroll: true })
}

function issueCreditNote(): void {
  creditNoteForm.post(`/admin/invoices/${props.invoice.id}/credit-note`, {
    preserveScroll: true,
    onSuccess: () => {
      crediting.value = false
    },
  })
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
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
          <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
        </template>

        <template #meta>
          <Link
            v-if="invoice.customerId"
            :href="`/admin/customers/${invoice.customerId}`"
            class="hover:text-content underline underline-offset-4"
          >
            {{ invoice.customer }}
          </Link>
          <span v-else-if="invoice.customer">{{ invoice.customer }}</span>
          <template v-if="invoice.orderNumber">
            <span aria-hidden="true">·</span>
            <Link
              :href="`/admin/orders/${invoice.orderId}`"
              class="hover:text-content underline underline-offset-4"
            >
              {{ invoice.orderNumber }}
            </Link>
          </template>
        </template>

        <template v-if="can.update && isDraft" #actions>
          <AppButton variant="primary" icon="check" @click="issuing = true">
            {{ t('ui.invoice.issue') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="isDraft" tone="info">{{ t('ui.invoice.draft_notice') }}</AppAlert>
      <AppAlert v-else-if="invoice.isPastDue" tone="danger">
        {{ t('ui.invoice.overdue', { date: formatDate(invoice.dueOn) }) }}
      </AppAlert>

      <MetricStrip :items="figures" />

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div class="flex min-w-0 flex-col gap-8">
          <DetailSection :title="t('ui.invoice.items')">
            <ul class="divide-line-subtle divide-y">
              <li v-for="line in invoice.items" :key="line.id" class="py-2.5 first:pt-0 last:pb-0">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-body">
                      {{ line.description }}
                      <span v-if="line.quantity > 1" class="text-content-muted">
                        × {{ line.quantity }}
                      </span>
                    </p>
                    <p
                      v-if="line.detail"
                      class="text-content-muted text-chrome mt-0.5 whitespace-pre-line"
                    >
                      {{ line.detail }}
                    </p>
                  </div>
                  <div class="shrink-0 text-right whitespace-nowrap">
                    <p class="text-body tabular-nums">{{ line.lineAmount }}</p>
                    <p v-if="line.discount" class="text-success text-chrome">
                      −{{ line.discount }}
                    </p>
                  </div>
                </div>
              </li>
            </ul>

            <!--
              The totals, as a plain list under a hairline rather than a second
              framed surface: a box inside a section would be a card inside a
              card, and the arithmetic belongs to the items above it.
            -->
            <dl
              class="border-line divide-line-subtle text-body mt-4 divide-y border-t pt-1 lg:ml-auto lg:w-80"
            >
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">{{ t('ui.invoice.subtotal') }}</dt>
                <dd class="tabular-nums">{{ invoice.subtotal }}</dd>
              </div>
              <div v-if="invoice.discount" class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">{{ t('ui.invoice.discount') }}</dt>
                <dd class="text-success tabular-nums">−{{ invoice.discount }}</dd>
              </div>
              <div v-if="invoice.tax" class="flex justify-between gap-4 py-2">
                <dt class="text-content-muted">{{ t('ui.invoice.tax') }}</dt>
                <dd class="tabular-nums">{{ invoice.tax }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-2 font-medium">
                <dt>{{ t('ui.invoice.total') }}</dt>
                <dd class="tabular-nums">{{ invoice.total }}</dd>
              </div>
            </dl>
          </DetailSection>

          <DetailSection :title="t('ui.invoice.payments')">
            <EmptyState
              v-if="invoice.payments.length === 0"
              variant="plain"
              icon="billing"
              :title="t('ui.invoice.no_payments')"
              :description="t('ui.invoice.no_payments_detail')"
            />

            <ul v-else class="divide-line-subtle divide-y">
              <li
                v-for="payment in invoice.payments"
                :key="payment.id"
                class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 py-2.5 first:pt-0 last:pb-0"
              >
                <div class="min-w-0">
                  <p class="text-body flex flex-wrap items-center gap-x-2">
                    <span>{{ payment.gateway }}</span>
                    <AppStatus :tone="statusTone(payment.status)" :label="payment.statusLabel" />
                  </p>
                  <!--
                    A payment that has not arrived has no date, and an em dash
                    followed by a reference reads as a missing field rather
                    than as "not yet".
                  -->
                  <p
                    v-if="payment.receivedAt || payment.reference"
                    class="text-content-muted text-chrome mt-0.5 flex flex-wrap items-center gap-x-2"
                  >
                    <span v-if="payment.receivedAt">{{ formatDateTime(payment.receivedAt) }}</span>
                    <span v-if="payment.receivedAt && payment.reference" aria-hidden="true">·</span>
                    <span v-if="payment.reference" class="font-mono">{{ payment.reference }}</span>
                  </p>
                  <p v-if="payment.note" class="text-content-muted text-chrome mt-0.5">
                    {{ payment.note }}
                  </p>
                </div>

                <div class="flex shrink-0 items-start gap-3">
                  <div class="text-right whitespace-nowrap">
                    <p class="text-body tabular-nums">{{ payment.amount }}</p>
                    <p v-if="payment.refunded" class="text-content-subtle text-chrome">
                      {{ t('ui.invoice.refunded', { amount: payment.refunded }) }}
                    </p>
                  </div>

                  <!--
                    The entry to a refund, not the refund. `danger-subtle`
                    here and the solid press inside the confirmation, where
                    the amount and the reason are asked for together.
                  -->
                  <AppButton
                    v-if="can.refund && payment.refundable > 0"
                    size="sm"
                    variant="danger-subtle"
                    @click="askToRefund(payment)"
                  >
                    {{ t('ui.invoice.refund') }}
                  </AppButton>
                </div>
              </li>
            </ul>
          </DetailSection>

          <DetailSection
            v-if="invoice.ledger.length > 0"
            :title="t('ui.invoice.ledger')"
            :description="t('ui.invoice.ledger_intro')"
          >
            <ul class="divide-line-subtle divide-y">
              <li
                v-for="(row, index) in invoice.ledger"
                :key="index"
                class="text-body flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0"
              >
                <span class="min-w-0">
                  {{ row.kindLabel }}
                  <span v-if="row.description" class="text-content-muted text-chrome mt-0.5 block">
                    {{ row.description }}
                  </span>
                </span>
                <span class="shrink-0 text-right whitespace-nowrap">
                  <span
                    class="tabular-nums"
                    :class="row.increases ? 'text-success' : 'text-content'"
                  >
                    {{ row.increases ? '+' : '−' }}{{ row.amount }}
                  </span>
                  <span class="text-content-subtle text-chrome block">
                    {{ formatDateTime(row.occurredAt) }}
                  </span>
                </span>
              </li>
            </ul>
          </DetailSection>

          <!--
            What the document says at the foot of it: the operator's own note,
            and the seller's terms copied onto the row when it was issued — so
            an invoice from last year still carries last year's wording.
          -->
          <DetailSection v-if="invoice.notes || invoice.terms" :title="t('ui.invoice.on_document')">
            <div class="text-content-muted text-body flex max-w-[80ch] flex-col gap-3">
              <p v-if="invoice.notes" class="leading-relaxed whitespace-pre-line">
                {{ invoice.notes }}
              </p>
              <p
                v-if="invoice.terms"
                class="border-line-subtle text-chrome leading-relaxed whitespace-pre-line"
                :class="invoice.notes ? 'border-t pt-3' : ''"
              >
                {{ invoice.terms }}
              </p>
            </div>
          </DetailSection>
        </div>

        <aside class="flex min-w-0 flex-col gap-8">
          <DetailSection :title="t('ui.invoice.bill_to')">
            <div class="text-content-muted text-body space-y-1">
              <p v-if="invoice.billTo.company" class="text-content font-medium">
                {{ invoice.billTo.company }}
              </p>
              <p v-if="invoice.billTo.name">{{ invoice.billTo.name }}</p>
              <p v-if="invoice.billTo.address" class="whitespace-pre-line">
                {{ invoice.billTo.address }}
              </p>
              <p v-if="invoice.billTo.country">{{ invoice.billTo.country }}</p>
              <p v-if="invoice.billTo.taxId" class="text-chrome font-mono">
                {{ invoice.billTo.taxId }}
              </p>
              <p v-if="invoice.billTo.email" class="text-chrome">{{ invoice.billTo.email }}</p>
              <p v-if="isDraft" class="text-content-subtle text-chrome">
                {{ t('ui.invoice.bill_to_draft') }}
              </p>
            </div>

            <DescriptionList :items="documentFacts" class="border-line-subtle mt-4 border-t pt-3" />
          </DetailSection>

          <DetailSection
            v-if="can.recordPayment && owes && !isDraft"
            :title="t('ui.invoice.record_payment')"
          >
            <form class="flex flex-col gap-3" @submit.prevent="recordPayment">
              <MoneyInput
                v-model="paymentForm.amount_minor"
                :label="t('ui.invoice.amount')"
                :exponent="2"
                :symbol="invoice.currency"
              />
              <AppSelect
                v-model="paymentForm.gateway"
                :label="t('ui.invoice.method')"
                :options="gateways"
                :error="paymentForm.errors.gateway"
              />
              <AppInput
                v-model="paymentForm.reference"
                :label="t('ui.invoice.reference')"
                :error="paymentForm.errors.reference"
                :hint="t('ui.invoice.reference_hint')"
              />
              <AppInput
                v-model="paymentForm.received_on"
                :label="t('ui.invoice.received')"
                type="date"
                :error="paymentForm.errors.received_on"
              />
              <div>
                <AppButton type="submit" variant="primary" :loading="paymentForm.processing">
                  {{ t('ui.invoice.record') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>

          <DetailSection
            v-if="can.credit && invoice.creditBalanceMinor > 0 && owes"
            :title="t('ui.invoice.account_credit')"
            :description="t('ui.invoice.credit_available', { amount: invoice.creditBalance ?? '' })"
          >
            <form class="flex flex-col gap-3" @submit.prevent="applyCredit">
              <MoneyInput
                v-model="applyCreditForm.amount_minor"
                :label="t('ui.invoice.apply')"
                :exponent="2"
                :symbol="invoice.currency"
              />
              <div>
                <AppButton type="submit" :loading="applyCreditForm.processing">
                  {{ t('ui.invoice.apply_credit') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>

          <DetailSection
            v-if="can.credit && !isDraft"
            :title="t('ui.invoice.credit_note')"
            :description="t('ui.invoice.credit_note_intro')"
          >
            <form v-if="crediting" class="flex flex-col gap-3" @submit.prevent="issueCreditNote">
              <MoneyInput
                v-model="creditNoteForm.amount_minor"
                :label="t('ui.invoice.amount')"
                :exponent="2"
                :symbol="invoice.currency"
              />
              <AppInput
                v-model="creditNoteForm.reason"
                :label="t('ui.invoice.credit_note_reason')"
                :error="creditNoteForm.errors.reason"
                :hint="t('ui.invoice.credit_note_reason_hint')"
              />
              <div class="flex gap-2">
                <AppButton type="submit" variant="primary" :loading="creditNoteForm.processing">
                  {{ t('ui.invoice.credit_note_issue') }}
                </AppButton>
                <AppButton variant="ghost" @click="crediting = false">
                  {{ t('ui.confirm.cancel') }}
                </AppButton>
              </div>
            </form>
            <div v-else>
              <AppButton icon="add" @click="crediting = true">
                {{ t('ui.invoice.credit_note_start') }}
              </AppButton>
            </div>

            <ul
              v-if="invoice.creditNotes.length > 0"
              class="divide-line-subtle text-chrome border-line-subtle mt-4 divide-y border-t pt-1"
            >
              <li
                v-for="note in invoice.creditNotes"
                :key="note.number"
                class="flex justify-between gap-3 py-2"
              >
                <span class="min-w-0">
                  <span class="font-mono">{{ note.number }}</span>
                  <span class="text-content-muted mt-0.5 block">{{ note.reason }}</span>
                </span>
                <span class="shrink-0 whitespace-nowrap tabular-nums">{{ note.amount }}</span>
              </li>
            </ul>
          </DetailSection>
        </aside>
      </div>
    </div>

    <DangerZone v-if="can.update && isDraft">
      <DangerZoneRow
        :title="t('ui.invoice.cancel_title')"
        :description="t('ui.invoice.cancel_detail')"
      >
        <AppButton variant="danger-subtle" @click="cancelling = true">
          {{ t('ui.invoice.cancel_button') }}
        </AppButton>
      </DangerZoneRow>
    </DangerZone>

    <AppConfirm
      v-model:open="issuing"
      level="consequential"
      :title="t('ui.invoice.issue_confirm_title')"
      :description="t('ui.invoice.issue_confirm_detail')"
      :confirm-label="t('ui.invoice.issue')"
      :busy="issueForm.processing"
      @confirm="issue"
    />

    <AppConfirm
      v-model:open="cancelling"
      level="consequential"
      :title="t('ui.invoice.cancel_confirm_title')"
      :description="t('ui.invoice.cancel_confirm_detail')"
      :confirm-label="t('ui.invoice.cancel_button_confirm')"
      :busy="cancelForm.processing"
      @confirm="cancel"
    />

    <!--
      The amount is asked for inside the confirmation rather than in the row,
      because a partial refund and a full one are the same decision and they
      belong to the same press. The reason is the dialog's own field; it is
      written to the audit record, and money leaving the business is what gets
      asked about later.
    -->
    <AppConfirm
      :open="refunding !== null"
      level="high-risk"
      :title="t('ui.invoice.refund_title')"
      :description="t('ui.invoice.refund_detail')"
      :confirm-label="t('ui.invoice.refund_confirm')"
      :busy="refundForm.processing"
      @update:open="(value: boolean) => (refunding = value ? refunding : null)"
      @confirm="refund"
    >
      <MoneyInput
        v-model="refundForm.amount_minor"
        :label="t('ui.invoice.refund_amount')"
        :exponent="2"
        :symbol="invoice.currency"
      />
    </AppConfirm>

    <p v-if="refundForm.errors.reason" class="text-danger text-chrome mt-2" role="alert">
      {{ refundForm.errors.reason }}
    </p>
  </AdminLayout>
</template>
