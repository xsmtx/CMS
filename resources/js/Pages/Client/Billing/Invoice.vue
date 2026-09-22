<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppCard>
          <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
              <AppBadge :tone="invoice.isOwed ? 'warning' : 'success'">
                {{ invoice.statusLabel }}
              </AppBadge>
              <p class="text-content-muted mt-2 text-xs">
                {{ invoice.issuedOn ?? '—' }} ·
                {{ t('billing.portal.due', { date: invoice.dueOn ?? '—' }) }}
              </p>
            </div>

            <div v-if="invoice.billTo.length > 0" class="text-right">
              <p class="text-content-muted text-xs">{{ t('billing.portal.bill_to') }}</p>
              <p
                v-for="line in invoice.billTo"
                :key="line"
                class="text-sm leading-relaxed whitespace-pre-line"
              >
                {{ line }}
              </p>
            </div>
          </div>

          <ul class="divide-line divide-y">
            <li v-for="item in invoice.items" :key="item.id" class="py-3 first:pt-0">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-sm font-medium">
                    {{ item.description }}
                    <span v-if="item.quantity > 1" class="text-content-muted">
                      × {{ item.quantity }}
                    </span>
                  </p>
                  <p
                    v-if="item.detail"
                    class="text-content-muted mt-0.5 text-xs leading-relaxed whitespace-pre-line"
                  >
                    {{ item.detail }}
                  </p>
                </div>
                <p class="shrink-0 text-sm tabular-nums">{{ item.amount }}</p>
              </div>
            </li>
          </ul>

          <dl class="border-line mt-4 border-t pt-4 text-sm">
            <div class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('billing.invoices.total') }}</dt>
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
        </AppCard>

        <AppCard v-if="invoice.payments.length > 0" :title="t('billing.invoices.payments')">
          <ul class="divide-line divide-y text-sm">
            <li
              v-for="payment in invoice.payments"
              :key="payment.id"
              class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
            >
              <span class="text-content-muted">
                {{ formatDate(payment.receivedAt) }} · {{ payment.status }}
              </span>
              <span class="tabular-nums">{{ payment.amount }}</span>
            </li>
          </ul>
        </AppCard>

        <AppCard v-if="invoice.creditNotes.length > 0" :title="t('billing.credit_notes.title')">
          <ul class="divide-line divide-y text-sm">
            <li
              v-for="note in invoice.creditNotes"
              :key="note.id"
              class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
            >
              <span>
                {{ note.number }}
                <span class="text-content-muted">· {{ note.issuedOn ?? '—' }}</span>
              </span>
              <span class="tabular-nums">{{ note.amount }}</span>
            </li>
          </ul>
        </AppCard>
      </div>

      <div>
        <AppCard v-if="invoice.isOwed && can.pay && gateways.length > 0">
          <h2 class="mb-1 text-sm font-semibold">{{ t('billing.payments.pay_now') }}</h2>
          <p class="text-content-muted mb-4 text-2xl font-semibold tracking-tight tabular-nums">
            {{ invoice.balance }}
          </p>

          <div class="flex flex-col gap-2">
            <label
              v-for="gateway in gateways"
              :key="gateway.value"
              class="border-line hover:bg-surface-sunken flex cursor-pointer items-center gap-2.5 rounded-[var(--radius-sm)] border px-3 py-2.5 text-sm transition-colors duration-(--duration-fast)"
              :class="form.gateway === gateway.value ? 'border-accent' : ''"
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
            class="border-line bg-surface-sunken mt-3 rounded-[var(--radius-sm)] border px-3 py-2 text-xs leading-relaxed whitespace-pre-line"
          >
            {{ chosen.instructions }}
          </p>

          <AppButton class="mt-4 w-full" variant="primary" :loading="form.processing" @click="pay">
            {{ t('billing.payments.pay_now') }}
          </AppButton>
        </AppCard>

        <AppCard v-else-if="!invoice.isOwed">
          <p class="text-sm leading-relaxed">{{ t('billing.portal.nothing_owed') }}</p>
        </AppCard>
      </div>
    </div>
  </ClientLayout>
</template>
