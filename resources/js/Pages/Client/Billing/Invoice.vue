<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

interface InvoiceLine {
  id: string
  description: string
  detail: string | null
  quantity: number
  amount: string
}

interface InvoicePayment {
  id: string
  gateway: string
  amount: string
  status: string
  statusLabel: string
  receivedAt: string | null
}

interface InvoiceCreditNote {
  id: string
  number: string
  amount: string
  issuedOn: string | null
}

const props = defineProps<{
  invoice: {
    number: string
    status: string
    statusLabel: string
    total: string
    balance: string
    isOwed: boolean
    dueOn: string | null
    issuedOn: string | null
    subtotal: string
    discount: string | null
    tax: string
    paid: string
    billTo: string[]
    terms: string | null
    items: InvoiceLine[]
    payments: InvoicePayment[]
    creditNotes: InvoiceCreditNote[]
  }
  gateways: { value: string; label: string; instructions: string | null }[]
  can: { pay: boolean }
}>()

const { t } = useTranslations()

const form = useForm({ gateway: props.gateways[0]?.value ?? '' })
const chosen = ref(props.gateways[0] ?? null)

const LINE_COLUMNS: TableColumn[] = [
  { key: 'description', label: t('billing.invoices.description') },
  { key: 'amount', label: t('billing.payments.amount'), numeric: true },
]

const PAYMENT_COLUMNS: TableColumn[] = [
  { key: 'when', label: t('billing.payments.received_on') },
  { key: 'status', label: t('billing.invoices.status') },
  { key: 'amount', label: t('billing.payments.amount'), numeric: true },
]

const NOTE_COLUMNS: TableColumn[] = [
  { key: 'number', label: t('billing.credit_notes.number') },
  { key: 'issued', label: t('billing.credit_notes.issued') },
  { key: 'amount', label: t('billing.payments.amount'), numeric: true },
]

/** The dates a document carries, read the way the reader's browser reads them. */
const issued = computed(() => formatDate(props.invoice.issuedOn))
const due = computed(() => formatDate(props.invoice.dueOn))

function choose(value: string): void {
  form.gateway = value
  chosen.value = props.gateways.find((gateway) => gateway.value === value) ?? null
}

function pay(): void {
  // The same endpoint the storefront uses. One payment path, whether the
  // customer arrived here from checkout or from their own invoice list.
  form.post(`/invoices/${props.invoice.number}/pay`)
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="invoice.number" />

  <ClientLayout :heading="invoice.number">
    <template #actions>
      <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
    </template>

    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
      <div class="flex flex-col gap-8">
        <div>
          <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <p class="text-content-muted text-chrome">
              {{ issued }} · {{ t('billing.portal.due', { date: due }) }}
            </p>

            <div v-if="invoice.billTo.length > 0" class="text-right">
              <p class="text-content-muted text-chrome">{{ t('billing.portal.bill_to') }}</p>
              <p
                v-for="line in invoice.billTo"
                :key="line"
                class="text-body leading-relaxed whitespace-pre-line"
              >
                {{ line }}
              </p>
            </div>
          </div>

          <AppTable :columns="LINE_COLUMNS">
            <AppTableRow v-for="item in invoice.items" :key="item.id">
              <td data-col="description">
                <span class="font-medium">{{ item.description }}</span>
                <span v-if="item.quantity > 1" class="text-content-muted">
                  × {{ item.quantity }}
                </span>
                <span
                  v-if="item.detail"
                  class="text-content-muted text-chrome mt-0.5 block leading-relaxed whitespace-pre-line"
                >
                  {{ item.detail }}
                </span>
              </td>
              <td data-col="amount" class="numeric tabular-nums">{{ item.amount }}</td>
            </AppTableRow>
          </AppTable>

          <!--
            The arithmetic of the document, right-aligned under it and capped
            in width: a total sitting 700px from the line it totals is a total
            nobody connects to anything.
          -->
          <dl class="text-body mt-4 ml-auto max-w-sm">
            <div class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.subtotal') }}</dt>
              <dd class="tabular-nums">{{ invoice.subtotal }}</dd>
            </div>
            <div v-if="invoice.discount" class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.discount') }}</dt>
              <dd class="tabular-nums">−{{ invoice.discount }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.tax') }}</dt>
              <dd class="tabular-nums">{{ invoice.tax }}</dd>
            </div>
            <div class="border-line mt-2 flex justify-between gap-4 border-t pt-3 font-semibold">
              <dt>{{ t('billing.invoices.total') }}</dt>
              <dd class="tabular-nums">{{ invoice.total }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('billing.invoices.paid') }}</dt>
              <dd class="tabular-nums">{{ invoice.paid }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-1 font-semibold">
              <dt>{{ t('billing.invoices.balance') }}</dt>
              <dd class="tabular-nums">{{ invoice.balance }}</dd>
            </div>
          </dl>
        </div>

        <DetailSection
          v-if="invoice.payments.length > 0"
          :title="t('billing.invoices.payments')"
          :level="3"
          :divided="false"
        >
          <AppTable :columns="PAYMENT_COLUMNS">
            <AppTableRow v-for="payment in invoice.payments" :key="payment.id">
              <td data-col="when" class="text-content-muted whitespace-nowrap">
                {{ formatDate(payment.receivedAt) }}
              </td>
              <td data-col="status">
                <AppStatus :tone="statusTone(payment.status)" :label="payment.statusLabel" />
              </td>
              <td data-col="amount" class="numeric tabular-nums">{{ payment.amount }}</td>
            </AppTableRow>
          </AppTable>
        </DetailSection>

        <DetailSection
          v-if="invoice.creditNotes.length > 0"
          :title="t('billing.credit_notes.title')"
          :level="3"
          :divided="false"
        >
          <AppTable :columns="NOTE_COLUMNS">
            <AppTableRow v-for="note in invoice.creditNotes" :key="note.id">
              <td data-col="number" class="font-medium">{{ note.number }}</td>
              <td data-col="issued" class="text-content-muted">{{ formatDate(note.issuedOn) }}</td>
              <td data-col="amount" class="numeric tabular-nums">{{ note.amount }}</td>
            </AppTableRow>
          </AppTable>
        </DetailSection>
      </div>

      <!--
        Framed, because paying is a separate thing from reading: it is the one
        place on this page where something leaves the customer's bank.
      -->
      <div>
        <AppCard v-if="invoice.isOwed && can.pay && gateways.length > 0">
          <h2 class="text-body mb-1 font-semibold">{{ t('billing.payments.pay_now') }}</h2>
          <p class="text-page mb-4 font-semibold tabular-nums">{{ invoice.balance }}</p>

          <div class="flex flex-col gap-2">
            <label
              v-for="gateway in gateways"
              :key="gateway.value"
              class="border-line hover:bg-surface-secondary text-body flex cursor-pointer items-center gap-2.5 rounded-md border px-3 py-2.5 transition-colors duration-(--duration-fast)"
              :class="form.gateway === gateway.value ? 'border-brand' : ''"
            >
              <input
                type="radio"
                name="gateway"
                :value="gateway.value"
                :checked="form.gateway === gateway.value"
                class="accent-(--color-accent)"
                @change="choose(gateway.value)"
              />
              {{ gateway.label }}
            </label>
          </div>

          <p
            v-if="chosen?.instructions"
            class="border-line bg-surface-secondary text-chrome mt-3 rounded-md border px-3 py-2 leading-relaxed whitespace-pre-line"
          >
            {{ chosen.instructions }}
          </p>

          <AppButton class="mt-4 w-full" variant="primary" :loading="form.processing" @click="pay">
            {{ t('billing.payments.pay_now') }}
          </AppButton>
        </AppCard>

        <AppCard v-else-if="!invoice.isOwed">
          <p class="text-body leading-relaxed">{{ t('billing.portal.nothing_owed') }}</p>
        </AppCard>
      </div>
    </div>

    <!--
      The sentence the seller's country obliges the document to carry, as it was
      when this invoice was issued. A customer reading a two-year-old invoice
      sees what they were sent, not what the terms say today.
    -->
    <p
      v-if="invoice.terms"
      class="border-line text-content-muted text-chrome mt-8 border-t pt-4 leading-relaxed whitespace-pre-line"
    >
      {{ invoice.terms }}
    </p>
  </ClientLayout>
</template>
