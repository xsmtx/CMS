<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import { useTranslations } from '../../../../composables/useTranslations'
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

const { t } = useTranslations()

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
  <Head :title="isEditing ? t('catalog.currencies.edit') : t('catalog.currencies.new')" />

  <AdminLayout
    :heading="
      isEditing
        ? t('catalog.currencies.edit_code', { code: currency?.code ?? '' })
        : t('catalog.currencies.new')
    "
    :description="t('catalog.currencies.form_intro')"
  >
    <div class="flex flex-col gap-8">
      <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.code"
            :label="t('catalog.currencies.code')"
            :error="form.errors.code"
            :hint="t('catalog.currencies.code_hint')"
            required
          />

          <AppInput
            v-model="form.name"
            :label="t('catalog.currencies.name')"
            :error="form.errors.name"
            required
          />

          <AppInput
            v-model="form.symbol"
            :label="t('catalog.currencies.symbol')"
            :error="form.errors.symbol"
            :hint="t('catalog.currencies.symbol_hint')"
          />

          <AppInput
            v-model="form.rate"
            :label="t('catalog.currencies.rate')"
            :error="form.errors.rate"
            :disabled="form.is_base"
            :hint="t('catalog.currencies.rate_hint')"
          />

          <div class="flex items-center">
            <AppCheckbox
              v-model="form.is_base"
              :label="t('catalog.currencies.base')"
              :description="t('catalog.currencies.base_hint')"
            />
          </div>

          <div class="flex items-center">
            <AppCheckbox
              v-model="form.is_active"
              :label="t('catalog.currencies.active')"
              :description="t('catalog.currencies.active_hint')"
            />
          </div>
        </div>

        <div class="flex items-center gap-3">
          <AppButton type="submit" variant="primary" :loading="form.processing">
            {{ isEditing ? t('catalog.currencies.save') : t('catalog.currencies.new') }}
          </AppButton>
          <AppButton href="/admin/catalog/currencies" variant="ghost">{{
            t('ui.confirm.cancel')
          }}</AppButton>
        </div>
      </form>

      <section v-if="history.length > 0">
        <h2 class="text-title font-semibold tracking-tight">
          {{ t('catalog.currencies.history') }}
        </h2>
        <p class="text-content-muted text-body mt-1 mb-4 max-w-[60ch] leading-relaxed">
          {{ t('catalog.currencies.history_hint') }}
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
