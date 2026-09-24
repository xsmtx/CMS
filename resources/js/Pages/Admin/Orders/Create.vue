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
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

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

const DOMAIN_ADDONS = computed(() => [
  { value: 'dns_management', label: t('ui.order_new.addons.dns_management') },
  { value: 'email_forwarding', label: t('ui.order_new.addons.email_forwarding') },
  { value: 'id_protection', label: t('ui.order_new.addons.id_protection') },
])

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
  <Head :title="t('ui.order_new.title')" />

  <AdminLayout :heading="t('ui.order_new.title')">
    <template #header>
      <PageHeader :title="t('ui.order_new.title')" :description="t('ui.order_new.intro')" />
    </template>

    <form
      class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,21rem)]"
      @submit.prevent="submit"
    >
      <div class="flex min-w-0 flex-col gap-8">
        <DetailSection :title="t('ui.order_new.client')">
          <template v-if="chosen" #actions>
            <AppButton type="button" variant="ghost" size="sm" @click="forget">
              {{ t('ui.order_new.change') }}
            </AppButton>
          </template>

          <div v-if="chosen">
            <p class="text-body font-medium">{{ chosen.name }}</p>
            <p class="text-content-muted text-chrome">
              <span v-if="chosen.email">{{ chosen.email }} · </span>{{ chosen.currency }}
            </p>
          </div>

          <div v-else class="flex flex-col gap-3">
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <AppInput
                  v-model="term"
                  :label="t('ui.order_new.find_client')"
                  :error="form.errors.customer_id"
                  @keyup.enter="search"
                />
              </div>
              <AppButton type="button" @click="search">{{ t('ui.order_new.search') }}</AppButton>
            </div>

            <ul
              v-if="candidates.length > 0"
              class="border-line divide-line-subtle divide-y rounded-lg border"
            >
              <li v-for="candidate in candidates" :key="candidate.id">
                <button
                  type="button"
                  class="pressable hover:bg-surface-hover block w-full px-3 py-2 text-left transition-colors duration-(--duration-fast)"
                  @click="choose(candidate)"
                >
                  <span class="text-body block font-medium">{{ candidate.name }}</span>
                  <span class="text-content-muted text-chrome block">
                    {{ candidate.email ?? '—' }} · {{ candidate.currency }}
                  </span>
                </button>
              </li>
            </ul>
          </div>
        </DetailSection>

        <DetailSection
          :title="t('ui.order_new.products')"
          :description="t('ui.order_new.products_intro', { currency })"
        >
          <template v-if="products.length > 0" #actions>
            <AppButton type="button" variant="ghost" size="sm" icon="add" @click="addLine">
              {{ t('ui.order_new.add_product') }}
            </AppButton>
          </template>

          <p v-if="products.length === 0" class="text-content-muted text-body">
            {{ t('ui.order_new.no_products', { currency }) }}
          </p>

          <div v-else class="flex flex-col gap-4">
            <!--
              A frame per line, not a divider: five fields in a grid run into
              the five below them, and an operator editing the third line has
              to be able to see where it starts.
            -->
            <div
              v-for="(line, index) in form.lines"
              :key="index"
              class="border-line rounded-lg border p-4"
            >
              <div class="grid gap-4 sm:grid-cols-2">
                <AppSelect
                  v-model="line.product_id"
                  :label="t('ui.order_new.product')"
                  :options="[
                    { value: '', label: t('ui.order_new.choose') },
                    ...products.map((one) => ({
                      value: one.id,
                      label: one.group ? `${one.group} — ${one.name}` : one.name,
                    })),
                  ]"
                  @update:model-value="onProduct(line)"
                />
                <AppSelect
                  v-model="line.billing_cycle"
                  :label="t('ui.order_new.cycle')"
                  :options="cyclesFor(line)"
                  :disabled="line.product_id === ''"
                />
                <AppInput
                  v-if="productFor(line)?.requiresDomain"
                  v-model="line.domain"
                  :label="t('ui.order_new.domain')"
                  :hint="t('ui.order_new.domain_hint')"
                />
                <AppInput
                  v-model.number="line.quantity"
                  :label="t('ui.order_new.quantity')"
                  type="number"
                />
                <AppInput
                  v-model="line.price_override"
                  :label="t('ui.order_new.price_override')"
                  :hint="t('ui.order_new.price_override_hint', { currency })"
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
                  {{ t('ui.order_new.remove') }}
                </AppButton>
              </div>
            </div>
          </div>
        </DetailSection>

        <DetailSection
          :title="t('ui.order_new.domain_registration')"
          :description="t('ui.order_new.domain_registration_intro')"
        >
          <div class="flex flex-wrap gap-4">
            <label class="text-body flex items-center gap-2">
              <input
                v-model="form.domain_action"
                type="radio"
                value=""
                class="accent-brand size-3.5"
              />
              {{ t('ui.order_new.none') }}
            </label>
            <label
              v-for="action in domainActions"
              :key="action.value"
              class="text-body flex items-center gap-2"
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
              :label="t('ui.order_new.domain')"
              :error="form.errors.domain_name"
              placeholder="example.com"
            />
            <AppInput
              v-model.number="form.domain_years"
              :label="t('ui.order_new.years')"
              type="number"
            />
            <AppInput
              v-model="form.domain_registration_price"
              :label="t('ui.order_new.registration_override')"
              :hint="t('ui.order_new.registration_override_hint', { currency })"
            />
          </div>

          <div v-if="form.domain_action !== ''" class="mt-4">
            <p class="text-content-subtle text-label mb-2 uppercase">
              {{ t('ui.order_new.addons_label') }}
            </p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="addon in DOMAIN_ADDONS"
                :key="addon.value"
                type="button"
                class="pressable border-line hover:border-line-strong text-chrome rounded-sm border px-2.5 py-1.5 transition-colors duration-(--duration-fast)"
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
            class="border-line-subtle text-content-muted text-chrome mt-4 border-t pt-3 leading-relaxed"
          >
            {{ t('ui.order_new.transfer_note') }}
          </p>
        </DetailSection>

        <DetailSection
          :title="t('ui.order_new.placing')"
          :description="t('ui.order_new.placing_intro')"
        >
          <div class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
              <AppSelect
                v-model="form.gateway"
                :label="t('ui.order_new.method')"
                :options="gateways"
                :error="form.errors.gateway"
              />
              <AppInput
                v-model="form.promotion_code"
                :label="t('ui.order_new.promotion')"
                :hint="t('ui.order_new.promotion_hint')"
              />
            </div>

            <AppCheckbox
              v-model="form.confirm"
              :label="t('ui.order_new.confirm')"
              :description="t('ui.order_new.confirm_hint')"
            />
            <AppCheckbox
              v-model="form.generate_invoice"
              :label="t('ui.order_new.generate_invoice')"
              :description="t('ui.order_new.generate_invoice_hint')"
            />
            <AppCheckbox
              v-model="form.send_email"
              :label="t('ui.order_new.send_email')"
              :description="t('ui.order_new.send_email_hint')"
            />
          </div>
        </DetailSection>

        <DetailSection
          :title="t('ui.order_new.notes')"
          :description="t('ui.order_new.notes_intro')"
        >
          <AppTextarea v-model="form.notes" :label="t('ui.order_new.notes')" :rows="3" />
        </DetailSection>
      </div>

      <!--
        The one framed surface on the page: the figures an operator checks
        before pressing Submit, and the press itself, sticky beside a form
        that is longer than the screen.
      -->
      <aside class="flex min-w-0 flex-col gap-8 lg:sticky lg:top-20 lg:self-start">
        <AppCard :title="t('ui.order_new.summary')">
          <dl class="text-body flex flex-col gap-2">
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">{{ t('ui.order_new.subtotal') }}</dt>
              <dd class="tabular-nums">{{ format(subtotalMinor) }}</dd>
            </div>
            <div v-if="Number(taxRatePercent) > 0" class="flex justify-between gap-4">
              <dt class="text-content-muted">{{ taxName }} @ {{ taxRatePercent }}%</dt>
              <dd class="tabular-nums">{{ format(taxMinor) }}</dd>
            </div>
            <div class="border-line flex justify-between gap-4 border-t pt-2 font-medium">
              <dt>{{ t('ui.order_new.total') }}</dt>
              <dd class="tabular-nums">{{ format(totalMinor) }}</dd>
            </div>
          </dl>

          <p class="text-content-subtle text-chrome mt-3 leading-relaxed">
            {{ t('ui.order_new.preview_note') }}
          </p>

          <div class="mt-5 flex gap-2">
            <AppButton
              type="submit"
              variant="primary"
              :loading="form.processing"
              :disabled="!ready"
            >
              {{ t('ui.order_new.submit') }}
            </AppButton>
            <AppButton type="button" variant="ghost" href="/admin/orders">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </AppCard>
      </aside>
    </form>
  </AdminLayout>
</template>
