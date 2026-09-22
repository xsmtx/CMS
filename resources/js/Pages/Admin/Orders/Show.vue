<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
  can: { update: boolean; review: boolean }
}>()

const statusForm = useForm({
  status: props.order.transitions[0]?.value ?? '',
  reason: '',
})

const reviewForm = useForm({ reason: '' })
const reviewing = ref(false)

function changeStatus(): void {
  statusForm.put(`/admin/orders/${props.order.id}/status`, { preserveScroll: true })
}

function release(): void {
  reviewForm.post(`/admin/orders/${props.order.id}/release`, { preserveScroll: true })
}

function refuse(): void {
  reviewForm.post(`/admin/orders/${props.order.id}/refuse`, { preserveScroll: true })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="`Order ${order.number}`" />

  <AdminLayout :heading="`Order ${order.number}`" :description="order.customer ?? undefined">
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppCard>
          <h2 class="mb-4 text-sm font-semibold">Items</h2>

          <ul class="divide-line divide-y">
            <li v-for="line in order.items" :key="line.id" class="py-3 first:pt-0 last:pb-0">
              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-sm font-medium">
                    {{ line.name }}
                    <span v-if="line.quantity > 1" class="text-content-muted">
                      × {{ line.quantity }}
                    </span>
                  </p>
                  <p class="text-content-muted text-xs">
                    <span v-if="line.groupName">{{ line.groupName }} · </span>
                    <span v-if="line.cycleLabel">{{ line.cycleLabel }}</span>
                    <span v-if="line.domain"> · {{ line.domain }}</span>
                  </p>

                  <ul v-if="line.options.length > 0" class="mt-2 space-y-0.5">
                    <li
                      v-for="option in line.options"
                      :key="`${line.id}-${option.group}`"
                      class="text-content-muted text-xs"
                    >
                      {{ option.group }}: {{ option.label }}
                      <span v-if="option.amount" class="tabular-nums"> ({{ option.amount }})</span>
                    </li>
                  </ul>

                  <ul v-if="line.children.length > 0" class="mt-2 space-y-0.5">
                    <li
                      v-for="child in line.children"
                      :key="child.id"
                      class="text-content-muted text-xs"
                    >
                      + {{ child.name }}
                      <span class="tabular-nums">{{ child.lineTotal }}</span>
                    </li>
                  </ul>
                </div>

                <div class="text-right whitespace-nowrap">
                  <p class="text-sm tabular-nums">{{ line.lineTotal }}</p>
                  <p v-if="line.lineSetup" class="text-content-subtle text-xs">
                    incl. {{ line.lineSetup }} setup
                  </p>
                  <p v-if="line.lineDiscount" class="text-success text-xs">
                    −{{ line.lineDiscount }}
                  </p>
                </div>
              </div>
            </li>
          </ul>
        </AppCard>

        <AppCard>
          <h2 class="mb-4 text-sm font-semibold">History</h2>

          <ol class="divide-line divide-y">
            <li
              v-for="(entry, index) in order.history"
              :key="index"
              class="flex items-start justify-between gap-4 py-2.5 text-sm first:pt-0 last:pb-0"
            >
              <span>
                {{ entry.toLabel }}
                <span v-if="entry.reason" class="text-content-muted mt-0.5 block text-xs">
                  {{ entry.reason }}
                </span>
              </span>
              <span class="text-content-muted text-right text-xs whitespace-nowrap">
                {{ formatDateTime(entry.occurredAt) }}
                <span v-if="entry.actor" class="text-content-subtle block">{{ entry.actor }}</span>
              </span>
            </li>
          </ol>
        </AppCard>
      </div>

      <div class="flex flex-col gap-6">
        <AppCard>
          <h2 class="mb-3 text-sm font-semibold">Totals</h2>

          <dl class="divide-line divide-y text-sm">
            <div class="flex justify-between py-2">
              <dt class="text-content-muted">Subtotal</dt>
              <dd class="tabular-nums">{{ order.subtotal }}</dd>
            </div>
            <div v-if="order.promotionCode" class="flex justify-between py-2">
              <dt class="text-content-muted">Discount ({{ order.promotionCode }})</dt>
              <dd class="text-success tabular-nums">−{{ order.discount }}</dd>
            </div>
            <div class="flex justify-between py-2">
              <dt class="text-content-muted">Setup</dt>
              <dd class="tabular-nums">{{ order.setup }}</dd>
            </div>
            <div class="flex justify-between py-2">
              <dt class="text-content-muted">
                Tax
                <span v-if="order.taxBreakdown.length > 0" class="text-content-subtle">
                  ({{ order.taxBreakdown[0]?.name }} {{ order.taxBreakdown[0]?.rate }}%)
                </span>
              </dt>
              <dd class="tabular-nums">{{ order.tax }}</dd>
            </div>
            <div class="flex justify-between py-2 font-semibold">
              <dt>Total</dt>
              <dd class="tabular-nums">{{ order.total }}</dd>
            </div>
            <div class="flex justify-between py-2">
              <dt class="text-content-muted">Renews at</dt>
              <dd class="text-content-muted tabular-nums">{{ order.recurringTotal }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard v-if="order.riskDecision">
          <h2 class="mb-2 text-sm font-semibold">
            Risk
            <AppBadge class="ml-2">{{ order.riskDecision }}</AppBadge>
          </h2>

          <ul v-if="order.riskReasons.length > 0" class="text-content-muted space-y-1 text-xs">
            <li v-for="(reason, index) in order.riskReasons" :key="index">{{ reason }}</li>
          </ul>
          <p v-else class="text-content-muted text-xs">Nothing flagged.</p>

          <p v-if="order.riskReviewedAt" class="text-content-subtle mt-3 text-xs">
            Reviewed {{ formatDateTime(order.riskReviewedAt) }}
            <span v-if="order.riskReviewedBy">by {{ order.riskReviewedBy }}</span>
          </p>

          <div v-if="can.review && order.status === 'fraud_review'" class="mt-4">
            <template v-if="reviewing">
              <AppInput
                v-model="reviewForm.reason"
                label="Reason"
                :error="reviewForm.errors.reason"
                hint="Recorded against the order. Required, because an override has to be explainable later."
              />
              <div class="mt-3 flex gap-2">
                <AppButton
                  size="sm"
                  variant="primary"
                  :loading="reviewForm.processing"
                  @click="release"
                >
                  Release
                </AppButton>
                <AppButton
                  size="sm"
                  variant="danger"
                  :loading="reviewForm.processing"
                  @click="refuse"
                >
                  Refuse
                </AppButton>
                <AppButton size="sm" variant="ghost" @click="reviewing = false">Cancel</AppButton>
              </div>
            </template>
            <AppButton v-else size="sm" @click="reviewing = true">Review this order</AppButton>
          </div>
        </AppCard>

        <AppCard v-if="can.update && order.transitions.length > 0">
          <h2 class="mb-3 text-sm font-semibold">Change status</h2>

          <div class="flex flex-col gap-3">
            <AppSelect
              v-model="statusForm.status"
              label="New status"
              :options="order.transitions"
              :error="statusForm.errors.status"
            />
            <AppInput
              v-model="statusForm.reason"
              label="Reason"
              :error="statusForm.errors.reason"
              hint="Optional, and kept forever."
            />
            <div>
              <AppButton
                size="sm"
                variant="primary"
                :loading="statusForm.processing"
                @click="changeStatus"
              >
                Apply
              </AppButton>
            </div>
          </div>
        </AppCard>

        <AppCard>
          <h2 class="mb-3 text-sm font-semibold">Placement</h2>

          <dl class="text-content-muted space-y-2 text-xs">
            <div class="flex justify-between gap-4">
              <dt>Placed</dt>
              <dd>{{ formatDateTime(order.placedAt) }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt>Contact</dt>
              <dd>{{ order.contact ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt>Terms</dt>
              <dd>
                {{ order.termsVersion ?? '—' }}
                <span v-if="order.termsAcceptedAt"
                  >· {{ formatDateTime(order.termsAcceptedAt) }}</span
                >
              </dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt>From</dt>
              <dd class="font-mono">{{ order.ipAddress ?? '—' }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppAlert v-if="order.notes" tone="info">{{ order.notes }}</AppAlert>
      </div>
    </div>

    <div class="mt-6">
      <AppButton href="/admin/orders" variant="ghost">Back to orders</AppButton>
    </div>
  </AdminLayout>
</template>
