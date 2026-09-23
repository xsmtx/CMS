<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import BillingTabs from './BillingTabs.vue'

interface StoredMethod {
  id: string
  gateway: string
  brand: string | null
  lastFour: string | null
  expiry: string | null
  isDefault: boolean
}

const props = defineProps<{
  details: {
    companyName: string | null
    legalName: string | null
    taxId: string | null
    currencyCode: string
    lineOne: string | null
    lineTwo: string | null
    city: string | null
    region: string | null
    postalCode: string | null
    countryCode: string | null
  }
  methods: StoredMethod[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const form = useForm({
  company_name: props.details.companyName ?? '',
  legal_name: props.details.legalName ?? '',
  tax_id: props.details.taxId ?? '',
  line_one: props.details.lineOne ?? '',
  line_two: props.details.lineTwo ?? '',
  city: props.details.city ?? '',
  region: props.details.region ?? '',
  postal_code: props.details.postalCode ?? '',
  country_code: props.details.countryCode ?? '',
})

function save(): void {
  form.put('/client/billing/details', { preserveScroll: true })
}

function makeDefault(id: string): void {
  router.put(`/client/billing/methods/${id}/default`, {}, { preserveScroll: true })
}

function remove(id: string): void {
  router.delete(`/client/billing/methods/${id}`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t('billing.portal.details_title')" />

  <ClientLayout
    :heading="t('billing.portal.details_title')"
    :description="t('billing.portal.details_description')"
  >
    <BillingTabs current="details" />

    <div class="flex flex-col gap-6">
      <AppCard>
        <!--
          Said plainly, because every system that lets a customer assume
          otherwise has taught them the wrong thing: an issued invoice keeps
          the details it was issued with.
        -->
        <p class="text-content-muted mb-5 max-w-[60ch] text-sm leading-relaxed">
          {{ t('billing.portal.details_note') }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.company_name"
            :label="t('crm.fields.company_name')"
            :error="form.errors.company_name"
          />
          <AppInput
            v-model="form.legal_name"
            :label="t('crm.fields.legal_name')"
            :error="form.errors.legal_name"
          />
          <AppInput
            v-model="form.tax_id"
            :label="t('crm.fields.tax_id')"
            :error="form.errors.tax_id"
          />
        </div>

        <h3 class="mt-6 mb-3 text-sm font-semibold">{{ t('billing.portal.address') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.line_one"
            class="sm:col-span-2"
            :label="t('crm.fields.line_one')"
            :error="form.errors.line_one"
          />
          <AppInput
            v-model="form.line_two"
            class="sm:col-span-2"
            :label="t('crm.fields.line_two')"
            :error="form.errors.line_two"
          />
          <AppInput v-model="form.city" :label="t('crm.fields.city')" :error="form.errors.city" />
          <AppInput
            v-model="form.region"
            :label="t('crm.fields.region')"
            :error="form.errors.region"
          />
          <AppInput
            v-model="form.postal_code"
            :label="t('crm.fields.postal_code')"
            :error="form.errors.postal_code"
          />
          <AppInput
            v-model="form.country_code"
            :label="t('crm.fields.country')"
            :error="form.errors.country_code"
          />
        </div>

        <div class="mt-6">
          <AppButton variant="primary" :loading="form.processing" @click="save">
            {{ t('crm.save') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard :title="t('billing.portal.methods_title')">
        <ul v-if="methods.length > 0" class="divide-line divide-y">
          <li
            v-for="method in methods"
            :key="method.id"
            class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
          >
            <div>
              <p class="text-sm font-medium">
                {{ method.brand ?? method.gateway }}
                <span v-if="method.lastFour" class="text-content-muted">
                  •••• {{ method.lastFour }}
                </span>
                <AppBadge v-if="method.isDefault" class="ml-2" tone="brand">
                  {{ t('billing.portal.default') }}
                </AppBadge>
              </p>
              <p v-if="method.expiry" class="text-content-muted mt-0.5 text-xs">
                {{ method.expiry }}
              </p>
            </div>

            <div v-if="can.manage" class="flex gap-2">
              <AppButton
                v-if="!method.isDefault"
                size="sm"
                variant="ghost"
                @click="makeDefault(method.id)"
              >
                {{ t('billing.portal.make_default') }}
              </AppButton>
              <AppButton size="sm" variant="ghost" @click="remove(method.id)">
                {{ t('billing.portal.remove') }}
              </AppButton>
            </div>
          </li>
        </ul>

        <EmptyState
          v-else
          :title="t('billing.portal.methods_none')"
          :description="t('billing.portal.methods_add_note')"
        />
      </AppCard>
    </div>
  </ClientLayout>
</template>
