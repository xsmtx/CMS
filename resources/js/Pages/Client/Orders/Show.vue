<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

interface OrderOption {
  group: string
  label: string
}

interface OrderLine {
  id: string
  name: string
  cycleLabel: string | null
  domain: string | null
  quantity: number
  lineTotal: string
  options: OrderOption[]
  children: OrderLine[]
}

defineProps<{
  order: {
    number: string
    status: string
    statusLabel: string
    total: string
    placedAt: string | null
    subtotal: string
    discount: string | null
    setup: string | null
    tax: string
    recurringTotal: string | null
    promotionCode: string | null
    items: OrderLine[]
  }
  invoice: {
    number: string
    status: string
    statusLabel: string
    balance: string
    isOwed: boolean
  } | null
}>()

const { t } = useTranslations()

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="order.number" />

  <ClientLayout
    :heading="order.number"
    :description="t('ordering.portal.placed', { date: formatDate(order.placedAt) })"
  >
    <template #actions>
      <AppStatus :tone="statusTone(order.status)" :label="order.statusLabel" />
    </template>

    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
      <div>
        <DetailSection :title="t('ordering.portal.what_you_ordered')">
          <!--
            The copy the order froze when it was placed, not what the
            catalog says today (ADR 0021). That is the point of reading an
            old order at all.
          -->
          <ul class="divide-line divide-y">
            <li v-for="line in order.items" :key="line.id" class="py-3 first:pt-0 last:pb-0">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-body font-medium">
                    {{ line.name }}
                    <span v-if="line.quantity > 1" class="text-content-muted">
                      × {{ line.quantity }}
                    </span>
                  </p>
                  <p
                    v-if="line.cycleLabel || line.domain"
                    class="text-content-muted text-chrome mt-0.5"
                  >
                    <span v-if="line.cycleLabel">{{ line.cycleLabel }}</span>
                    <span v-if="line.cycleLabel && line.domain"> · </span>
                    <span v-if="line.domain">{{ line.domain }}</span>
                  </p>
                  <ul v-if="line.options.length > 0" class="text-content-muted text-chrome mt-1">
                    <li v-for="option in line.options" :key="option.group + option.label">
                      {{ option.group }}: {{ option.label }}
                    </li>
                  </ul>
                </div>
                <p class="text-body shrink-0 tabular-nums">{{ line.lineTotal }}</p>
              </div>

              <ul v-if="line.children.length > 0" class="border-line mt-2 ml-4 border-l pl-3">
                <li
                  v-for="child in line.children"
                  :key="child.id"
                  class="flex items-start justify-between gap-4 py-1.5"
                >
                  <span class="text-content-muted text-chrome">{{ child.name }}</span>
                  <span class="text-content-muted text-chrome shrink-0 tabular-nums">
                    {{ child.lineTotal }}
                  </span>
                </li>
              </ul>
            </li>
          </ul>

          <dl class="border-line text-body mt-4 border-t pt-4">
            <div class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.subtotal') }}</dt>
              <dd class="tabular-nums">{{ order.subtotal }}</dd>
            </div>
            <div v-if="order.discount" class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">
                {{ t('ordering.cart.discount') }}
                <span v-if="order.promotionCode">({{ order.promotionCode }})</span>
              </dt>
              <dd class="tabular-nums">−{{ order.discount }}</dd>
            </div>
            <div v-if="order.setup" class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.setup') }}</dt>
              <dd class="tabular-nums">{{ order.setup }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.tax') }}</dt>
              <dd class="tabular-nums">{{ order.tax }}</dd>
            </div>
            <div class="border-line mt-2 flex justify-between gap-4 border-t pt-3 font-semibold">
              <dt>{{ t('ordering.orders.total') }}</dt>
              <dd class="tabular-nums">{{ order.total }}</dd>
            </div>
            <div v-if="order.recurringTotal" class="flex justify-between gap-4 py-1">
              <dt class="text-content-muted">{{ t('ordering.cart.recurring_label') }}</dt>
              <dd class="text-content-muted tabular-nums">{{ order.recurringTotal }}</dd>
            </div>
          </dl>
        </DetailSection>
      </div>

      <!--
        Framed, and only when there is one: the invoice is where money leaves
        the customer, which is a different kind of thing from a copy of what
        they ordered.
      -->
      <div>
        <AppCard v-if="invoice" :title="t('ordering.portal.invoice')">
          <div class="flex items-baseline justify-between gap-3">
            <Link
              :href="`/client/billing/invoices/${invoice.number}`"
              class="text-body font-medium underline-offset-4 hover:underline"
            >
              {{ invoice.number }}
            </Link>
            <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
          </div>

          <p v-if="invoice.isOwed" class="text-content-muted text-chrome mt-2">
            {{ t('billing.invoices.balance') }}
            <span class="tabular-nums">{{ invoice.balance }}</span>
          </p>

          <AppButton
            v-if="invoice.isOwed"
            class="mt-4 w-full"
            variant="primary"
            :href="`/client/billing/invoices/${invoice.number}`"
          >
            {{ t('ordering.portal.pay') }}
          </AppButton>
        </AppCard>
      </div>
    </div>
  </ClientLayout>
</template>
