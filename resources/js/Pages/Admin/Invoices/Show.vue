<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import MoneyInput from '../../../Components/MoneyInput.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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

const isDraft = computed(() => props.invoice.status === 'draft')
const owes = computed(() => props.invoice.balanceMinor > 0)

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

const refunding = ref<string | null>(null)
const crediting = ref(false)

function issue(): void {
  useForm({}).post(`/admin/invoices/${props.invoice.id}/issue`, { preserveScroll: true })
}

function cancel(): void {
  useForm({}).post(`/admin/invoices/${props.invoice.id}/cancel`, { preserveScroll: true })
}

function recordPayment(): void {
  paymentForm.post(`/admin/invoices/${props.invoice.id}/payments`, { preserveScroll: true })
}

function refund(paymentId: string): void {
  refundForm.post(`/admin/invoices/${props.invoice.id}/payments/${paymentId}/refund`, {
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
  <Head :title="`Invoice ${invoice.number}`" />

  <AdminLayout :heading="`Invoice ${invoice.number}`" :description="invoice.customer ?? undefined">
    <AppAlert v-if="isDraft" tone="info" class="mb-5">
      This is a draft. It has no number and no legal standing until it is issued — and once it is,
      nothing about it can change.
    </AppAlert>

    <AppAlert v-else-if="invoice.isPastDue" tone="danger" class="mb-5">
      Overdue since {{ formatDate(invoice.dueOn) }}.
    </AppAlert>

    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppCard>
          <div class="mb-4 flex items-start justify-between gap-4">
            <h2 class="text-sm font-semibold">Items</h2>
            <AppBadge>{{ invoice.statusLabel }}</AppBadge>
          </div>

          <ul class="divide-line divide-y">
            <li v-for="line in invoice.items" :key="line.id" class="py-3 first:pt-0 last:pb-0">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-sm">
                    {{ line.description }}
                    <span v-if="line.quantity > 1" class="text-content-muted">
                      × {{ line.quantity }}
                    </span>
                  </p>
                  <p
                    v-if="line.detail"
                    class="text-content-muted mt-0.5 text-xs whitespace-pre-line"
                  >
                    {{ line.detail }}
                  </p>
                </div>
                <div class="text-right whitespace-nowrap">
                  <p class="text-sm tabular-nums">{{ line.lineAmount }}</p>
                  <p v-if="line.discount" class="text-success text-xs">−{{ line.discount }}</p>
                </div>
              </div>
            </li>
          </ul>

          <dl class="border-line divide-line mt-4 divide-y border-t text-sm">
            <div class="flex justify-between py-2">
              <dt class="text-content-muted">Subtotal</dt>
              <dd class="tabular-nums">{{ invoice.subtotal }}</dd>
            </div>
            <div v-if="invoice.discount" class="flex justify-between py-2">
              <dt class="text-content-muted">Discount</dt>
              <dd class="text-success tabular-nums">−{{ invoice.discount }}</dd>
            </div>
            <div v-if="invoice.tax" class="flex justify-between py-2">
              <dt class="text-content-muted">Tax</dt>
              <dd class="tabular-nums">{{ invoice.tax }}</dd>
            </div>
            <div class="flex justify-between py-2 font-semibold">
              <dt>Total</dt>
              <dd class="tabular-nums">{{ invoice.total }}</dd>
            </div>
            <div class="flex justify-between py-2">
              <dt class="text-content-muted">Paid</dt>
              <dd class="tabular-nums">{{ invoice.paid }}</dd>
            </div>
            <div class="flex justify-between py-2 font-semibold">
              <dt>Balance</dt>
              <dd class="tabular-nums">{{ invoice.balance }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard>
          <h2 class="mb-4 text-sm font-semibold">Payments</h2>

          <p v-if="invoice.payments.length === 0" class="text-content-muted text-sm">
            No payments yet.
          </p>

          <ul v-else class="divide-line divide-y">
            <li
              v-for="payment in invoice.payments"
              :key="payment.id"
              class="py-3 first:pt-0 last:pb-0"
            >
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-sm">
                    {{ payment.gateway }}
                    <span class="text-content-muted">· {{ payment.statusLabel }}</span>
                  </p>
                  <p class="text-content-muted mt-0.5 text-xs">
                    {{ formatDateTime(payment.receivedAt) }}
                    <span v-if="payment.reference" class="font-mono"
                      >· {{ payment.reference }}</span
                    >
                  </p>
                  <p v-if="payment.note" class="text-content-muted mt-0.5 text-xs">
                    {{ payment.note }}
                  </p>
                </div>
                <div class="text-right whitespace-nowrap">
                  <p class="text-sm tabular-nums">{{ payment.amount }}</p>
                  <p v-if="payment.refunded" class="text-content-subtle text-xs">
                    −{{ payment.refunded }} refunded
                  </p>
                  <button
                    v-if="can.refund && payment.refundable > 0 && refunding !== payment.id"
                    type="button"
                    class="pressable text-danger mt-1 text-xs underline underline-offset-4"
                    @click="
                      () => {
                        refunding = payment.id
                        refundForm.amount_minor = payment.refundable
                      }
                    "
                  >
                    Refund
                  </button>
                </div>
              </div>

              <div v-if="refunding === payment.id" class="border-line mt-3 border-t pt-3">
                <div class="grid gap-3 sm:grid-cols-2">
                  <MoneyInput
                    v-model="refundForm.amount_minor"
                    label="Amount to refund"
                    :exponent="2"
                    :symbol="invoice.currency"
                  />
                  <AppInput
                    v-model="refundForm.reason"
                    label="Reason"
                    :error="refundForm.errors.reason"
                    hint="Required. Money leaving the business is what gets asked about later."
                  />
                </div>
                <div class="mt-3 flex gap-2">
                  <AppButton
                    size="sm"
                    variant="danger"
                    :loading="refundForm.processing"
                    @click="refund(payment.id)"
                  >
                    Refund
                  </AppButton>
                  <AppButton size="sm" variant="ghost" @click="refunding = null">Cancel</AppButton>
                </div>
              </div>
            </li>
          </ul>
        </AppCard>

        <AppCard v-if="invoice.ledger.length > 0">
          <h2 class="mb-4 text-sm font-semibold">Ledger</h2>

          <ul class="divide-line divide-y">
            <li
              v-for="(row, index) in invoice.ledger"
              :key="index"
              class="flex items-start justify-between gap-4 py-2.5 text-sm first:pt-0 last:pb-0"
            >
              <span>
                {{ row.kindLabel }}
                <span v-if="row.description" class="text-content-muted mt-0.5 block text-xs">
                  {{ row.description }}
                </span>
              </span>
              <span class="text-right whitespace-nowrap">
                <span class="tabular-nums" :class="row.increases ? 'text-success' : 'text-danger'">
                  {{ row.increases ? '+' : '−' }}{{ row.amount }}
                </span>
                <span class="text-content-subtle block text-xs">
                  {{ formatDateTime(row.occurredAt) }}
                </span>
              </span>
            </li>
          </ul>
        </AppCard>
      </div>

      <div class="flex flex-col gap-6">
        <AppCard>
          <h2 class="mb-3 text-sm font-semibold">Bill to</h2>

          <div class="text-content-muted space-y-1 text-sm">
            <p v-if="invoice.billTo.company" class="text-content font-medium">
              {{ invoice.billTo.company }}
            </p>
            <p v-if="invoice.billTo.name">{{ invoice.billTo.name }}</p>
            <p v-if="invoice.billTo.address" class="whitespace-pre-line">
              {{ invoice.billTo.address }}
            </p>
            <p v-if="invoice.billTo.country">{{ invoice.billTo.country }}</p>
            <p v-if="invoice.billTo.taxId" class="font-mono text-xs">{{ invoice.billTo.taxId }}</p>
            <p v-if="invoice.billTo.email" class="text-xs">{{ invoice.billTo.email }}</p>
            <p v-if="isDraft" class="text-content-subtle text-xs">
              Copied onto the invoice when it is issued.
            </p>
          </div>

          <dl class="border-line text-content-muted mt-4 space-y-2 border-t pt-4 text-xs">
            <div class="flex justify-between gap-4">
              <dt>Issued</dt>
              <dd>{{ formatDate(invoice.issuedOn) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt>Due</dt>
              <dd>{{ formatDate(invoice.dueOn) }}</dd>
            </div>
            <div v-if="invoice.orderNumber" class="flex justify-between gap-4">
              <dt>Order</dt>
              <dd>
                <Link
                  :href="`/admin/orders/${invoice.orderId}`"
                  class="underline underline-offset-4"
                >
                  {{ invoice.orderNumber }}
                </Link>
              </dd>
            </div>
          </dl>
        </AppCard>

        <AppCard v-if="can.update && isDraft">
          <h2 class="mb-2 text-sm font-semibold">Issue</h2>
          <p class="text-content-muted mb-4 text-xs leading-relaxed">
            Takes the next number, copies the customer's details onto the document and freezes it.
          </p>
          <div class="flex gap-2">
            <AppButton size="sm" variant="primary" @click="issue">Issue invoice</AppButton>
            <AppButton size="sm" variant="ghost" @click="cancel">Cancel</AppButton>
          </div>
        </AppCard>

        <AppCard v-if="can.recordPayment && owes && !isDraft">
          <h2 class="mb-3 text-sm font-semibold">Record a payment</h2>

          <div class="flex flex-col gap-3">
            <MoneyInput
              v-model="paymentForm.amount_minor"
              label="Amount"
              :exponent="2"
              :symbol="invoice.currency"
            />
            <AppSelect
              v-model="paymentForm.gateway"
              label="Method"
              :options="gateways"
              :error="paymentForm.errors.gateway"
            />
            <AppInput
              v-model="paymentForm.reference"
              label="Reference"
              :error="paymentForm.errors.reference"
              hint="The bank's reference, so this can be matched to a statement."
            />
            <AppInput
              v-model="paymentForm.received_on"
              label="Received"
              type="date"
              :error="paymentForm.errors.received_on"
            />
            <div>
              <AppButton
                size="sm"
                variant="primary"
                :loading="paymentForm.processing"
                @click="recordPayment"
              >
                Record payment
              </AppButton>
            </div>
          </div>
        </AppCard>

        <AppCard v-if="can.credit && invoice.creditBalanceMinor > 0 && owes">
          <h2 class="mb-1 text-sm font-semibold">Account credit</h2>
          <p class="text-content-muted mb-3 text-xs">{{ invoice.creditBalance }} available.</p>

          <div class="flex flex-col gap-3">
            <MoneyInput
              v-model="applyCreditForm.amount_minor"
              label="Apply"
              :exponent="2"
              :symbol="invoice.currency"
            />
            <div>
              <AppButton size="sm" :loading="applyCreditForm.processing" @click="applyCredit">
                Apply credit
              </AppButton>
            </div>
          </div>
        </AppCard>

        <AppCard v-if="can.credit && !isDraft">
          <h2 class="mb-1 text-sm font-semibold">Credit note</h2>
          <p class="text-content-muted mb-3 text-xs leading-relaxed">
            The only way to change what this invoice says. It stays as it is; a second document
            records the correction.
          </p>

          <template v-if="crediting">
            <div class="flex flex-col gap-3">
              <MoneyInput
                v-model="creditNoteForm.amount_minor"
                label="Amount"
                :exponent="2"
                :symbol="invoice.currency"
              />
              <AppInput
                v-model="creditNoteForm.reason"
                label="Reason"
                :error="creditNoteForm.errors.reason"
                hint="Printed on the credit note."
              />
              <div class="flex gap-2">
                <AppButton
                  size="sm"
                  variant="primary"
                  :loading="creditNoteForm.processing"
                  @click="issueCreditNote"
                >
                  Issue
                </AppButton>
                <AppButton size="sm" variant="ghost" @click="crediting = false">Cancel</AppButton>
              </div>
            </div>
          </template>
          <AppButton v-else size="sm" @click="crediting = true">Issue a credit note</AppButton>

          <ul v-if="invoice.creditNotes.length > 0" class="divide-line mt-4 divide-y text-xs">
            <li
              v-for="note in invoice.creditNotes"
              :key="note.number"
              class="flex justify-between gap-3 py-2"
            >
              <span>
                <span class="font-mono">{{ note.number }}</span>
                <span class="text-content-muted mt-0.5 block">{{ note.reason }}</span>
              </span>
              <span class="whitespace-nowrap tabular-nums">{{ note.amount }}</span>
            </li>
          </ul>
        </AppCard>
      </div>
    </div>

    <!--
      What the document says at the foot of it. The operator's own note about
      this invoice, and the sentence the seller's terms put on every document —
      copied onto the row when it was issued, so an invoice from last year still
      carries last year's wording.
    -->
    <div v-if="invoice.notes || invoice.terms" class="mt-6 flex flex-col gap-3">
      <p
        v-if="invoice.notes"
        class="text-content-muted text-xs leading-relaxed whitespace-pre-line"
      >
        {{ invoice.notes }}
      </p>
      <p
        v-if="invoice.terms"
        class="border-line text-content-muted border-t pt-3 text-xs leading-relaxed whitespace-pre-line"
      >
        {{ invoice.terms }}
      </p>
    </div>

    <div class="mt-6">
      <AppButton href="/admin/invoices" variant="ghost">Back to invoices</AppButton>
    </div>
  </AdminLayout>
</template>
