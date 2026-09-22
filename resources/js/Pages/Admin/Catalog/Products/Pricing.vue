<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

import AppAlert from '../../../../Components/AppAlert.vue'
import AppButton from '../../../../Components/AppButton.vue'
import AppCard from '../../../../Components/AppCard.vue'
import PriceMatrix from '../../../../Components/PriceMatrix.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
import type { CurrencyOption, CycleOption, PriceCell } from '../../../../types/catalog'

const props = defineProps<{
  product: { id: string; name: string; status: string }
  prices: PriceCell[]
  cycles: CycleOption[]
  currencies: CurrencyOption[]
}>()

const form = useForm<{ prices: PriceCell[] }>({ prices: props.prices })

function submit(): void {
  form.put(`/admin/catalog/products/${props.product.id}/pricing`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="`Pricing — ${product.name}`" />

  <AdminLayout
    :heading="`Pricing — ${product.name}`"
    description="One row per billing cycle, one tab per currency. Nothing is converted: a customer pays the price in their currency exactly as it is entered here."
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <AppAlert v-if="form.errors.prices" variant="danger">{{ form.errors.prices }}</AppAlert>

      <AppCard>
        <PriceMatrix v-model="form.prices" :cycles="cycles" :currencies="currencies" />
      </AppCard>

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          Save prices
        </AppButton>
        <AppButton :href="`/admin/catalog/products/${product.id}/edit`" variant="ghost">
          Back to product
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
