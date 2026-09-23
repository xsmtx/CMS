<script setup lang="ts">
/**
 * Taking an order over the phone.
 *
 * The summary on the right adds up the way the customer's will, but it is
 * a **preview**: the number that gets charged is worked out server side
 * when the order is placed, by the same pricing the storefront uses. A
 * screen that computed the real total would be a second place that prices
 * a sale, and the two would disagree the first time somebody changed a tax
 * rule.
 *
 * Only products that have a price in this client's currency are offered.
 * An operator should not be able to pick something the cart will refuse.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface Cycle {
  value: string
  label: string
  recurringMinor: number
  recurring: string
  setupMinor: number
  setup: string | null
}

interface ProductRow {
  id: string
  name: string
  group: string | null
  requiresDomain: boolean
  cycles: Cycle[]
}

interface Candidate {
  id: string
  name: string
  email: string | null
  currency: string
}

interface Line {
  product_id: string
  billing_cycle: string
  quantity: number
  domain: string
  price_override: string
}

const props = defineProps<{
  candidates: Candidate[]
  chosen: { id: string; name: string; email: string | null; currency: string } | null
  currency: string
  products: ProductRow[]
  gateways: { value: string; label: string }[]
  domainActions: { value: string; label: string }[]
  taxRatePercent: string
  taxName: string
}>()

function blankLine(): Line {
  return { product_id: '', billing_cycle: '', quantity: 1, domain: '', price_override: '' }
}

const form = useForm<{
  customer_id: string
  lines: Line[]
  promotion_code: string
  gateway: string
  notes: string
  confirm: boolean
  generate_invoice: boolean
  send_email: boolean
  domain_action: string
  domain_name: string
  domain_years: number
  domain_addons: string[]
  domain_registration_price: string
}>({
  customer_id: props.chosen?.id ?? '',
  lines: [blankLine()],
  promotion_code: '',
  gateway: props.gateways[0]?.value ?? '',
  notes: '',
  confirm: true,
  generate_invoice: false,
  send_email: true,
  domain_action: '',
  domain_name: '',
  domain_years: 1,
  domain_addons: [],
  domain_registration_price: '',
})

/** The client picker: a partial reload of this same screen. */
const term = ref('')

function search(): void {
  router.get(
    '/admin/orders/add',
    { q: term.value, customer: form.customer_id },
    { preserveState: true, preserveScroll: true, replace: true, only: ['candidates'] },
  )
}

function choose(candidate: Candidate): void {
  form.customer_id = candidate.id

  // The catalogue is reloaded too: a client in another currency is sold a
  // different set of things, and a price list that did not follow the
  // client would be the wrong list.
  router.get(
    '/admin/orders/add',
    { customer: candidate.id },
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: ['chosen', 'currency', 'products'],
    },
  )
}

function forget(): void {
  form.customer_id = ''

  router.get(
    '/admin/orders/add',
    {},
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: ['chosen', 'currency', 'products'],
    },
  )
}

watch(
  () => props.chosen,
  (chosen) => {
    if (chosen) form.customer_id = chosen.id
  },
)

// A product whose price list changed under the operator keeps a cycle it
// no longer has, which would be refused on submit. Cleared instead.
watch(
  () => props.products,
  () => {
    for (const line of form.lines) {
      const product = props.products.find((one) => one.id === line.product_id)

      if (!product) {
        line.product_id = ''
        line.billing_cycle = ''
      } else if (!product.cycles.some((cycle) => cycle.value === line.billing_cycle)) {
        line.billing_cycle = product.cycles[0]?.value ?? ''
      }
    }
  },
)

function addLine(): void {
  form.lines = [...form.lines, blankLine()]
}

function removeLine(index: number): void {
  form.lines = form.lines.filter((_, at) => at !== index)

  if (form.lines.length === 0) form.lines = [blankLine()]
}

function productFor(line: Line): ProductRow | null {
  return props.products.find((one) => one.id === line.product_id) ?? null
}

function cyclesFor(line: Line): { value: string; label: string }[] {
  return (productFor(line)?.cycles ?? []).map((cycle) => ({
    value: cycle.value,
    label: `${cycle.label} — ${cycle.recurring}`,
  }))
}

function onProduct(line: Line): void {
  const product = productFor(line)

  line.billing_cycle = product?.cycles[0]?.value ?? ''
}

/**
 * Minor units, from an amount somebody typed.
 *
 * The decimal exists in this one function. Everything the summary adds up
 * is an integer count of minor units, like every amount the server stores.
 */
function toMinor(value: string): number | null {
  if (value.trim() === '') return null

  const number = Number(value.replace(',', '.'))

  return Number.isFinite(number) ? Math.round(number * 100) : null
}

const money = computed(
  () => new Intl.NumberFormat(undefined, { style: 'currency', currency: props.currency }),
)

function format(minor: number): string {
  return money.value.format(minor / 100)
}

const subtotalMinor = computed(() => {
  let total = 0

  for (const line of form.lines) {
    const product = productFor(line)
    const cycle = product?.cycles.find((one) => one.value === line.billing_cycle)

    if (!cycle) continue

    const unit = toMinor(line.price_override) ?? cycle.recurringMinor

    total += (unit + cycle.setupMinor) * Math.max(line.quantity, 1)
  }

  if (form.domain_action !== '' && form.domain_name.trim() !== '') {
    total += toMinor(form.domain_registration_price) ?? 0
  }

  return total
})

const taxMinor = computed(() =>
  Math.round((subtotalMinor.value * Number(props.taxRatePercent || '0')) / 100),
)

const totalMinor = computed(() => subtotalMinor.value + taxMinor.value)

const DOMAIN_ADDONS = [
  { value: 'dns_management', label: 'DNS Management' },
  { value: 'email_forwarding', label: 'Email Forwarding' },
  { value: 'id_protection', label: 'ID Protection' },
]

function toggleAddon(value: string): void {
  form.domain_addons = form.domain_addons.includes(value)
    ? form.domain_addons.filter((one) => one !== value)
    : [...form.domain_addons, value]
}

const ready = computed(
  () =>
    form.customer_id !== '' &&
    (form.lines.some((line) => line.product_id !== '' && line.billing_cycle !== '') ||
      (form.domain_action !== '' && form.domain_name.trim() !== '')),
)

function submit(): void {
  form
    .transform((data) => ({
      ...data,
      lines: data.lines.filter((line) => line.product_id !== '' && line.billing_cycle !== ''),
    }))
    .post('/admin/orders')
}
</script>

<template>
  <Head title="Add new order" />

  <AdminLayout
    heading="Add New Order"
    description="An order taken over the phone. It goes through the same pricing the storefront uses, so nothing about it is a special case afterwards."
  >
    <form class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem]" @submit.prevent="submit">
      <div class="flex flex-col gap-6">
        <AppCard title="Client">
          <div v-if="chosen" class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-sm font-medium">{{ chosen.name }}</p>
              <p class="text-content-muted text-xs">
                <span v-if="chosen.email">{{ chosen.email }} · </span>{{ chosen.currency }}
              </p>
            </div>
            <AppButton type="button" variant="ghost" size="sm" @click="forget">Change</AppButton>
          </div>

          <div v-else class="flex flex-col gap-3">
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <AppInput
                  v-model="term"
                  label="Find a client"
                  :error="form.errors.customer_id"
                  @keyup.enter="search"
                />
              </div>
              <AppButton type="button" @click="search">Search</AppButton>
            </div>

            <ul
              v-if="candidates.length > 0"
              class="border-line divide-line divide-y rounded-[var(--radius-md)] border"
            >
              <li v-for="candidate in candidates" :key="candidate.id">
                <button
                  type="button"
                  class="pressable hover:bg-surface-secondary block w-full px-3 py-2 text-left"
                  @click="choose(candidate)"
                >
                  <span class="block text-sm font-medium">{{ candidate.name }}</span>
                  <span class="text-content-muted block text-xs">
                    {{ candidate.email ?? '—' }} · {{ candidate.currency }}
                  </span>
                </button>
              </li>
            </ul>
          </div>
        </AppCard>

        <AppCard
          title="Products"
          :description="`Priced in ${currency}. Only what is actually sold in that currency is offered.`"
        >
          <p v-if="products.length === 0" class="text-content-muted text-sm">
            Nothing in the catalogue has a price in {{ currency }}. Add one before taking an order
            in it.
          </p>

          <div v-else class="flex flex-col gap-4">
            <div
              v-for="(line, index) in form.lines"
              :key="index"
              class="border-line rounded-[var(--radius-md)] border p-4"
            >
              <div class="grid gap-4 sm:grid-cols-2">
                <AppSelect
                  v-model="line.product_id"
                  label="Product"
                  :options="[
                    { value: '', label: 'Choose…' },
                    ...products.map((one) => ({
                      value: one.id,
                      label: one.group ? `${one.group} — ${one.name}` : one.name,
                    })),
                  ]"
                  @update:model-value="onProduct(line)"
                />
                <AppSelect
                  v-model="line.billing_cycle"
                  label="Billing cycle"
                  :options="cyclesFor(line)"
                  :disabled="line.product_id === ''"
                />
                <AppInput
                  v-if="productFor(line)?.requiresDomain"
                  v-model="line.domain"
                  label="Domain"
                  hint="This product cannot be set up without a hostname."
                />
                <AppInput v-model.number="line.quantity" label="Quantity" type="number" />
                <AppInput
                  v-model="line.price_override"
                  label="Price override"
                  :hint="`Leave empty for the catalogue price. In ${currency}.`"
                />
              </div>

              <div class="mt-3 flex justify-end">
                <AppButton
                  type="button"
                  variant="ghost"
                  size="sm"
                  :disabled="form.lines.length === 1 && line.product_id === ''"
                  @click="removeLine(index)"
                >
                  Remove
                </AppButton>
              </div>
            </div>

            <div>
              <AppButton type="button" @click="addLine">Add Another Product</AppButton>
            </div>
          </div>
        </AppCard>

        <AppCard
          title="Domain registration"
          description="A domain is not a service. It is bought for a term and renewed on its own schedule."
        >
          <div class="flex flex-wrap gap-4">
            <label class="flex items-center gap-2 text-sm">
              <input
                v-model="form.domain_action"
                type="radio"
                value=""
                class="accent-brand size-3.5"
              />
              None
            </label>
            <label
              v-for="action in domainActions"
              :key="action.value"
              class="flex items-center gap-2 text-sm"
            >
              <input
                v-model="form.domain_action"
                type="radio"
                :value="action.value"
                class="accent-brand size-3.5"
              />
              {{ action.label }}
            </label>
          </div>

          <div v-if="form.domain_action !== ''" class="mt-4 grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.domain_name"
              label="Domain"
              :error="form.errors.domain_name"
              placeholder="example.com"
            />
            <AppInput
              v-model.number="form.domain_years"
              label="Registration period (years)"
              type="number"
            />
            <AppInput
              v-model="form.domain_registration_price"
              label="Registration price override"
              :hint="`Leave empty for the price on the board. In ${currency}.`"
            />
          </div>

          <div v-if="form.domain_action !== ''" class="mt-4">
            <p class="text-content-muted mb-2 text-xs font-medium">Addons</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="addon in DOMAIN_ADDONS"
                :key="addon.value"
                type="button"
                class="pressable border-line hover:border-line-strong rounded-full border px-3 py-1.5 text-xs transition-colors duration-(--duration-fast)"
                :class="form.domain_addons.includes(addon.value) ? 'border-brand text-content' : ''"
                :aria-pressed="form.domain_addons.includes(addon.value)"
                @click="toggleAddon(addon.value)"
              >
                {{ addon.label }}
              </button>
            </div>
          </div>

          <p
            v-if="form.domain_action === 'transfer'"
            class="border-line text-content-muted mt-4 border-t pt-3 text-xs"
          >
            The transfer code is asked for when the transfer is submitted, not here. A transfer code
            is fetched, shown once and never written down — storing it on an order would leave it
            sitting in the database for as long as the order does.
          </p>
        </AppCard>

        <AppCard title="Notes" description="Why this order exists. Visible to staff only.">
          <AppTextarea v-model="form.notes" label="Notes" :rows="3" />
        </AppCard>
      </div>

      <aside class="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
        <AppCard title="Order Summary">
          <dl class="flex flex-col gap-2 text-sm">
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">Sub Total</dt>
              <dd class="tabular-nums">{{ format(subtotalMinor) }}</dd>
            </div>
            <div v-if="Number(taxRatePercent) > 0" class="flex justify-between gap-4">
              <dt class="text-content-muted">{{ taxName }} @ {{ taxRatePercent }}%</dt>
              <dd class="tabular-nums">{{ format(taxMinor) }}</dd>
            </div>
            <div class="border-line flex justify-between gap-4 border-t pt-2 font-semibold">
              <dt>Total</dt>
              <dd class="tabular-nums">{{ format(totalMinor) }}</dd>
            </div>
          </dl>

          <p class="text-content-subtle mt-3 text-xs">
            A preview. The charge is worked out server side when the order is placed, by the same
            pricing the storefront uses.
          </p>
        </AppCard>

        <AppCard title="How to place it">
          <div class="flex flex-col gap-4">
            <AppSelect
              v-model="form.gateway"
              label="Payment method"
              :options="gateways"
              :error="form.errors.gateway"
            />
            <AppInput
              v-model="form.promotion_code"
              label="Promotion code"
              hint="A code that will not apply is reported rather than silently dropped."
            />

            <AppCheckbox
              v-model="form.confirm"
              label="Order Confirmation"
              description="Email the customer that the order exists. The status itself is worked out from the risk check, not from this."
            />
            <AppCheckbox
              v-model="form.generate_invoice"
              label="Generate Invoice"
              description="Raise and issue the invoice straight away."
            />
            <AppCheckbox
              v-model="form.send_email"
              label="Send Email"
              description="The master switch. Off means the customer hears nothing — not the confirmation, not the invoice."
            />
          </div>

          <div class="mt-5 flex gap-2">
            <AppButton
              type="submit"
              variant="primary"
              :loading="form.processing"
              :disabled="!ready"
            >
              Submit Order
            </AppButton>
            <Link href="/admin/orders">
              <AppButton type="button" variant="ghost">Cancel</AppButton>
            </Link>
          </div>
        </AppCard>
      </aside>
    </form>
  </AdminLayout>
</template>
