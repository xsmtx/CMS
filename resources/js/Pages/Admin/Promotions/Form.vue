<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import MoneyInput from '../../../Components/MoneyInput.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import type { CurrencyOption, CycleOption } from '../../../types/catalog'

const props = defineProps<{
  promotion: {
    id: string
    code: string
    name: string
    description: string | null
    type: string
    amountMinor: number | null
    currencyCode: string | null
    percentage: string | null
    scope: string
    application: string
    billingCycles: string[]
    startsAt: string | null
    endsAt: string | null
    usageLimit: number | null
    usageCount: number
    perCustomerLimit: number | null
    minimumSubtotalMinor: number | null
    newCustomersOnly: boolean
    stackable: boolean
    isActive: boolean
    productIds: string[]
    redemptions: number
  } | null
  types: { value: string; label: string }[]
  scopes: { value: string; label: string }[]
  applications: { value: string; label: string }[]
  cycles: CycleOption[]
  currencies: CurrencyOption[]
  products: { value: string; label: string }[]
}>()

const isEditing = computed(() => props.promotion !== null)

const form = useForm({
  code: props.promotion?.code ?? '',
  name: props.promotion?.name ?? '',
  description: props.promotion?.description ?? '',
  type: props.promotion?.type ?? 'percentage',
  amount_minor: props.promotion?.amountMinor ?? 0,
  currency_code: props.promotion?.currencyCode ?? props.currencies[0]?.code ?? 'EUR',
  percentage: props.promotion?.percentage ?? '10',
  scope: props.promotion?.scope ?? 'order',
  application: props.promotion?.application ?? 'first_payment',
  billing_cycles: props.promotion?.billingCycles ?? ([] as string[]),
  product_ids: props.promotion?.productIds ?? ([] as string[]),
  starts_at: props.promotion?.startsAt ?? '',
  ends_at: props.promotion?.endsAt ?? '',
  usage_limit:
    props.promotion?.usageLimit === null ? '' : String(props.promotion?.usageLimit ?? ''),
  per_customer_limit:
    props.promotion?.perCustomerLimit === null
      ? ''
      : String(props.promotion?.perCustomerLimit ?? ''),
  minimum_subtotal_minor: props.promotion?.minimumSubtotalMinor ?? 0,
  new_customers_only: props.promotion?.newCustomersOnly ?? false,
  stackable: props.promotion?.stackable ?? false,
  is_active: props.promotion?.isActive ?? true,
})

const isFixed = computed(() => form.type === 'fixed')
const needsProducts = computed(() => form.scope === 'products')

const exponent = computed(
  () => props.currencies.find((currency) => currency.code === form.currency_code)?.exponent ?? 2,
)

function toggleCycle(value: string, checked: boolean): void {
  form.billing_cycles = checked
    ? [...form.billing_cycles, value]
    : form.billing_cycles.filter((cycle) => cycle !== value)
}

function toggleProduct(value: string, checked: boolean): void {
  form.product_ids = checked
    ? [...form.product_ids, value]
    : form.product_ids.filter((id) => id !== value)
}

function submit(): void {
  form
    .transform((data) => ({
      ...data,
      amount_minor: isFixed.value ? data.amount_minor : null,
      currency_code: isFixed.value || data.minimum_subtotal_minor > 0 ? data.currency_code : null,
      percentage: isFixed.value ? null : data.percentage,
      starts_at: data.starts_at === '' ? null : data.starts_at,
      ends_at: data.ends_at === '' ? null : data.ends_at,
      usage_limit: data.usage_limit === '' ? null : Number(data.usage_limit),
      per_customer_limit: data.per_customer_limit === '' ? null : Number(data.per_customer_limit),
      minimum_subtotal_minor: data.minimum_subtotal_minor > 0 ? data.minimum_subtotal_minor : null,
      product_ids: needsProducts.value ? data.product_ids : [],
    }))
    [props.promotion ? 'put' : 'post'](
      props.promotion ? `/admin/promotions/${props.promotion.id}` : '/admin/promotions',
    )
}
</script>

<template>
  <Head :title="isEditing ? 'Edit promotion' : 'New promotion'" />

  <AdminLayout
    :heading="isEditing ? `Edit ${promotion?.code}` : 'New promotion'"
    description="A fixed amount is money and belongs to a currency; a percentage is a number and does not."
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.code"
            label="Code"
            :error="form.errors.code"
            hint="What the customer types. Letters, digits, hyphens and underscores."
            required
          />

          <AppInput
            v-model="form.name"
            label="Name"
            :error="form.errors.name"
            hint="For your own reference. Customers never see it."
            required
          />

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.description"
              label="Description"
              :error="form.errors.description"
            />
          </div>
        </div>
      </AppCard>

      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppSelect v-model="form.type" label="Type" :options="types" :error="form.errors.type" />

          <div v-if="isFixed" class="grid grid-cols-[1fr_auto] gap-3">
            <MoneyInput
              v-model="form.amount_minor"
              label="Amount off"
              :exponent="exponent"
              :symbol="form.currency_code"
            />
            <AppSelect
              v-model="form.currency_code"
              label="Currency"
              :options="
                currencies.map((currency) => ({ value: currency.code, label: currency.code }))
              "
              :error="form.errors.currency_code"
            />
          </div>

          <AppInput
            v-else
            v-model="form.percentage"
            label="Percentage off"
            :error="form.errors.percentage"
            hint="Applied once, to the eligible subtotal."
          />

          <AppSelect
            v-model="form.scope"
            label="Applies to"
            :options="scopes"
            :error="form.errors.scope"
          />

          <AppSelect
            v-model="form.application"
            label="Duration"
            :options="applications"
            :error="form.errors.application"
            hint="A recurring code rides along with the service and discounts every renewal."
          />
        </div>

        <fieldset v-if="needsProducts" class="border-line mt-5 border-t pt-5">
          <legend class="sr-only">Products</legend>
          <p class="mb-3 text-sm font-medium">Products</p>

          <div class="grid gap-2 sm:grid-cols-2">
            <AppCheckbox
              v-for="product in products"
              :key="product.value"
              :model-value="form.product_ids.includes(product.value)"
              :label="product.label"
              @update:model-value="(checked: boolean) => toggleProduct(product.value, checked)"
            />
          </div>
        </fieldset>

        <fieldset class="border-line mt-5 border-t pt-5">
          <legend class="sr-only">Billing cycles</legend>
          <p class="mb-1 text-sm font-medium">Billing cycles</p>
          <p class="text-content-muted mb-3 text-xs">
            Leave every box unticked to cover all of them.
          </p>

          <div class="grid gap-2 sm:grid-cols-3">
            <AppCheckbox
              v-for="cycle in cycles"
              :key="cycle.value"
              :model-value="form.billing_cycles.includes(cycle.value)"
              :label="cycle.label"
              @update:model-value="(checked: boolean) => toggleCycle(cycle.value, checked)"
            />
          </div>
        </fieldset>
      </AppCard>

      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.starts_at"
            label="Starts"
            type="date"
            :error="form.errors.starts_at"
          />
          <AppInput v-model="form.ends_at" label="Ends" type="date" :error="form.errors.ends_at" />

          <AppInput
            v-model="form.usage_limit"
            label="Total redemptions"
            type="number"
            :error="form.errors.usage_limit"
            hint="Empty is unlimited."
          />
          <AppInput
            v-model="form.per_customer_limit"
            label="Per customer"
            type="number"
            :error="form.errors.per_customer_limit"
            hint="Empty is unlimited."
          />

          <MoneyInput
            v-model="form.minimum_subtotal_minor"
            label="Minimum order"
            :exponent="exponent"
            :symbol="form.currency_code"
          />

          <div class="flex flex-col justify-end gap-3 pb-1">
            <AppCheckbox
              v-model="form.new_customers_only"
              label="New customers only"
              description="Refused for anyone who has ordered before."
            />
            <AppCheckbox v-model="form.is_active" label="Active" />
          </div>
        </div>

        <p v-if="promotion && promotion.redemptions > 0" class="text-content-muted mt-4 text-xs">
          Redeemed {{ promotion.redemptions }} time(s). The terms below apply to future orders only;
          what past customers were charged does not change.
        </p>
      </AppCard>

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save promotion' : 'Create promotion' }}
        </AppButton>
        <AppButton href="/admin/promotions" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
