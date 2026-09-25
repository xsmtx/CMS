<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../../Components/AppAlert.vue'
import { useTranslations } from '../../../../composables/useTranslations'
import AppButton from '../../../../Components/AppButton.vue'
import PriceMatrix from '../../../../Components/PriceMatrix.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
import {
  firstPriceError,
  toPricePayload,
  type CurrencyOption,
  type CycleOption,
  type PriceCell,
} from '../../../../types/catalog'

const props = defineProps<{
  product: { id: string; name: string; status: string }
  prices: PriceCell[]
  cycles: CycleOption[]
  currencies: CurrencyOption[]
}>()

const { t } = useTranslations()

const form = useForm<{ prices: PriceCell[] }>({ prices: props.prices })

const priceError = computed(() => firstPriceError(form.errors))

function submit(): void {
  form
    .transform((data) => ({ prices: toPricePayload(data.prices) }))
    .put(`/admin/catalog/products/${props.product.id}/pricing`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="`${t('catalog.pricing.title')} — ${product.name}`" />

  <AdminLayout
    :heading="`${t('catalog.pricing.title')} — ${product.name}`"
    :description="t('catalog.pricing.subtitle')"
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <AppAlert v-if="priceError" tone="danger">{{ priceError }}</AppAlert>

      <PriceMatrix v-model="form.prices" :cycles="cycles" :currencies="currencies" />

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ t('catalog.pricing.save') }}
        </AppButton>
        <AppButton :href="`/admin/catalog/products/${product.id}/edit`" variant="ghost">
          {{ t('catalog.pricing.back_to_product') }}
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
