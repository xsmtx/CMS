<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppCard from '../../../../Components/AppCard.vue'
import AppCheckbox from '../../../../Components/AppCheckbox.vue'
import AppInput from '../../../../Components/AppInput.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

interface RateSnapshot {
  rate: string
  source: string | null
  capturedAt: string
}

const props = defineProps<{
  currency: {
    id: string
    code: string
    name: string
    symbol: string | null
    exponent: number
    rate: string
    isBase: boolean
    isActive: boolean
  } | null
  history: RateSnapshot[]
}>()

const isEditing = computed(() => props.currency !== null)

const form = useForm({
  code: props.currency?.code ?? '',
  name: props.currency?.name ?? '',
  symbol: props.currency?.symbol ?? '',
  rate: props.currency?.rate ?? '1.00000000',
  is_base: props.currency?.isBase ?? false,
  is_active: props.currency?.isActive ?? true,
})

function submit(): void {
  form[props.currency ? 'put' : 'post'](
    props.currency ? `/admin/catalog/currencies/${props.currency.id}` : '/admin/catalog/currencies',
  )
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="isEditing ? 'Edit currency' : 'Add currency'" />

  <AdminLayout
    :heading="isEditing ? `Edit ${currency?.code}` : 'Add currency'"
    description="The number of decimals comes from ISO 4217 and is not editable: the yen has none, the dinar has three, and guessing would silently change every price."
  >
    <div class="flex flex-col gap-8">
      <form class="flex flex-col gap-6" @submit.prevent="submit">
        <AppCard>
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="form.code"
              label="Code"
              :error="form.errors.code"
              hint="Three letters, ISO 4217: EUR, USD, TRY."
              required
            />

            <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />

            <AppInput
              v-model="form.symbol"
              label="Symbol"
              :error="form.errors.symbol"
              hint="Shown beside amounts. Optional."
            />

            <AppInput
              v-model="form.rate"
              label="Rate"
              :error="form.errors.rate"
              :disabled="form.is_base"
              hint="Against the base currency. Used for reporting, never at checkout."
            />

            <div class="flex items-center">
              <AppCheckbox
                v-model="form.is_base"
                label="Base currency"
                description="Every other rate is quoted against this one, and its own rate is always 1."
              />
            </div>

            <div class="flex items-center">
              <AppCheckbox
                v-model="form.is_active"
                label="Active"
                description="Inactive currencies stay on existing prices but cannot be chosen for new ones."
              />
            </div>
          </div>
        </AppCard>

        <div class="flex items-center gap-3">
          <AppButton type="submit" variant="primary" :loading="form.processing">
            {{ isEditing ? 'Save currency' : 'Add currency' }}
          </AppButton>
          <AppButton href="/admin/catalog/currencies" variant="ghost">Cancel</AppButton>
        </div>
      </form>

      <section v-if="history.length > 0">
        <h2 class="text-base font-semibold tracking-tight">Rate history</h2>
        <p class="text-content-muted text-body mt-1 mb-4 max-w-[60ch] leading-relaxed">
          Every change is kept, so a document issued last quarter can still say what the rate was
          when it was issued.
        </p>

        <ul class="border-line divide-line bg-surface-primary divide-y rounded-lg border">
          <li
            v-for="(snapshot, index) in history"
            :key="index"
            class="text-body flex items-center justify-between gap-4 px-4 py-2.5"
          >
            <span class="font-mono tabular-nums">{{ snapshot.rate }}</span>
            <span class="text-content-muted text-chrome">
              {{ formatDate(snapshot.capturedAt) }}
              <span v-if="snapshot.source" class="text-content-subtle ml-2">{{
                snapshot.source
              }}</span>
            </span>
          </li>
        </ul>
      </section>
    </div>
  </AdminLayout>
</template>
