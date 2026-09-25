<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../../Components/AppAlert.vue'
import { useTranslations } from '../../../../composables/useTranslations'
import AppButton from '../../../../Components/AppButton.vue'
import DetailSection from '../../../../Components/DetailSection.vue'
import AppInput from '../../../../Components/AppInput.vue'
import AppSelect from '../../../../Components/AppSelect.vue'
import AppTextarea from '../../../../Components/AppTextarea.vue'
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
  product: { id: string; name: string }
  addon: {
    id: string
    name: string
    slug: string
    description: string | null
    status: string
    position: number
    prices: PriceCell[]
  } | null
  statuses: { value: string; label: string }[]
  cycles: CycleOption[]
  currencies: CurrencyOption[]
}>()

const { t } = useTranslations()

const isEditing = computed(() => props.addon !== null)

const form = useForm({
  name: props.addon?.name ?? '',
  slug: props.addon?.slug ?? '',
  description: props.addon?.description ?? '',
  status: props.addon?.status ?? 'active',
  position: String(props.addon?.position ?? 0),
})

// The addon's own price matrix, saved separately so changing what it costs
// does not require resaving its description.
const pricing = useForm<{ prices: PriceCell[] }>({ prices: props.addon?.prices ?? [] })

const priceError = computed(() => firstPriceError(pricing.errors))

function submit(): void {
  form
    .transform((data) => ({ ...data, position: Number(data.position) }))
    [props.addon ? 'put' : 'post'](
      props.addon
        ? `/admin/catalog/products/${props.product.id}/addons/${props.addon.id}`
        : `/admin/catalog/products/${props.product.id}/addons`,
    )
}

function savePrices(): void {
  if (!props.addon) return

  pricing
    .transform((data) => ({ prices: toPricePayload(data.prices) }))
    .put(`/admin/catalog/products/${props.product.id}/addons/${props.addon.id}/pricing`, {
      preserveScroll: true,
    })
}
</script>

<template>
  <Head :title="isEditing ? t('catalog.addons.edit') : t('catalog.addons.new')" />

  <AdminLayout
    :heading="isEditing ? t('catalog.addons.edit') : t('catalog.addons.new')"
    :description="t('catalog.addons.form_intro', { product: product.name })"
  >
    <div class="flex flex-col gap-8">
      <form class="flex flex-col gap-6" @submit.prevent="submit">
        <DetailSection :title="t('catalog.addons.the_addon')">
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="form.name"
              :label="t('catalog.addons.name')"
              :error="form.errors.name"
              required
            />

            <AppInput
              v-model="form.slug"
              :label="t('catalog.addons.slug')"
              :error="form.errors.slug"
              :hint="t('catalog.addons.slug_hint')"
            />

            <div class="sm:col-span-2">
              <AppTextarea
                v-model="form.description"
                :label="t('catalog.addons.description')"
                :error="form.errors.description"
              />
            </div>

            <AppSelect
              v-model="form.status"
              :label="t('catalog.addons.status')"
              :options="statuses"
              :error="form.errors.status"
            />

            <AppInput
              v-model="form.position"
              :label="t('catalog.addons.position')"
              type="number"
              :error="form.errors.position"
            />
          </div>
        </DetailSection>

        <div class="flex items-center gap-3">
          <AppButton type="submit" variant="primary" :loading="form.processing">
            {{ isEditing ? 'Save addon' : 'Create addon' }}
          </AppButton>
          <AppButton :href="`/admin/catalog/products/${product.id}/addons`" variant="ghost">
            {{ t('ui.confirm.cancel') }}
          </AppButton>
        </div>
      </form>

      <form v-if="addon" class="flex flex-col gap-6" @submit.prevent="savePrices">
        <AppAlert v-if="priceError" tone="danger">{{ priceError }}</AppAlert>

        <PriceMatrix
          v-model="pricing.prices"
          :cycles="cycles"
          :currencies="currencies"
          :title="t('catalog.addons.pricing')"
          :description="t('catalog.addons.pricing_hint')"
        />

        <div>
          <AppButton type="submit" variant="primary" :loading="pricing.processing">
            Save prices
          </AppButton>
        </div>
      </form>

      <p v-else class="text-content-muted text-body">Prices can be set once the addon exists.</p>
    </div>
  </AdminLayout>
</template>
