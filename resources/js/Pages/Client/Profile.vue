<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppInput from '../../Components/AppInput.vue'
import AppCard from '../../Components/AppCard.vue'
import { useTaxIdentity } from '../../composables/useTaxIdentity'
import { useTranslations } from '../../composables/useTranslations'
import ClientLayout from '../../Layouts/ClientLayout.vue'

const props = defineProps<{
  customer: {
    companyName: string | null
    legalName: string | null
    taxId: string | null
    status: string
    currencyCode: string
    marketingOptIn: boolean
  }
  me: {
    firstName: string
    lastName: string
    email: string
    phone: string | null
    isPrimary: boolean
    notifyInvoices: boolean
    notifySupport: boolean
    notifyProduct: boolean
    notifyMarketing: boolean
  }
  can: { manage: boolean }
}>()

const page = usePage()
const { t } = useTranslations()

// The seller's own word for it. The billing details screen already asks the
// same question with the same vocabulary; two names for one field on two
// screens of the same portal is how a customer starts doubting both.
const { label: taxIdLabel } = useTaxIdentity()

const meForm = useForm({
  first_name: props.me.firstName,
  last_name: props.me.lastName,
  phone: props.me.phone ?? '',
  notify_invoices: props.me.notifyInvoices,
  notify_support: props.me.notifySupport,
  notify_product: props.me.notifyProduct,
  notify_marketing: props.me.notifyMarketing,
})

const customerForm = useForm({
  company_name: props.customer.companyName ?? '',
  legal_name: props.customer.legalName ?? '',
  tax_id: props.customer.taxId ?? '',
  marketing_opt_in: props.customer.marketingOptIn,
})
</script>

<template>
  <Head :title="t('portal.profile.title')" />

  <ClientLayout :heading="t('portal.profile.title')" :description="t('portal.profile.description')">
    <div class="flex flex-col gap-8">
      <AppAlert v-if="page.props.flash?.status" tone="success">
        {{ page.props.flash.status }}
      </AppAlert>

      <AppCard :title="t('portal.profile.you')">
        <form
          class="grid max-w-xl gap-5"
          @submit.prevent="meForm.put('/client/profile', { preserveScroll: true })"
        >
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="meForm.first_name"
              :label="t('portal.contacts.first_name')"
              :error="meForm.errors.first_name"
              required
            />
            <AppInput
              v-model="meForm.last_name"
              :label="t('portal.contacts.last_name')"
              :error="meForm.errors.last_name"
              required
            />
          </div>

          <AppInput
            :model-value="me.email"
            :label="t('portal.contacts.email')"
            :hint="t('portal.profile.email_hint')"
            disabled
            @update:model-value="() => {}"
          />

          <AppInput
            v-model="meForm.phone"
            :label="t('portal.contacts.phone')"
            :error="meForm.errors.phone"
          />

          <fieldset class="flex flex-col gap-3">
            <legend class="text-body mb-1 font-medium">
              {{ t('portal.profile.preferences') }}
            </legend>
            <p class="text-content-muted text-chrome -mt-1 mb-2 max-w-[60ch] leading-relaxed">
              {{ t('portal.profile.preferences_hint') }}
            </p>
            <AppCheckbox
              v-model="meForm.notify_invoices"
              :label="t('portal.profile.notify_invoices')"
            />
            <AppCheckbox
              v-model="meForm.notify_support"
              :label="t('portal.profile.notify_support')"
            />
            <AppCheckbox
              v-model="meForm.notify_product"
              :label="t('portal.profile.notify_product')"
            />
            <AppCheckbox
              v-model="meForm.notify_marketing"
              :label="t('portal.profile.notify_marketing')"
            />
          </fieldset>

          <div>
            <AppButton type="submit" variant="primary" :loading="meForm.processing">
              {{ t('portal.profile.save_me') }}
            </AppButton>
          </div>
        </form>
      </AppCard>

      <AppCard
        :title="t('portal.profile.company')"
        :description="
          can.manage ? t('portal.profile.company_shown') : t('portal.profile.company_owner_only')
        "
      >
        <form
          class="grid max-w-xl gap-5"
          @submit.prevent="customerForm.put('/client/profile/customer', { preserveScroll: true })"
        >
          <AppInput
            v-model="customerForm.company_name"
            :label="t('crm.fields.company_name')"
            :disabled="!can.manage"
            :error="customerForm.errors.company_name"
          />
          <AppInput
            v-model="customerForm.legal_name"
            :label="t('crm.fields.legal_name')"
            :disabled="!can.manage"
            :error="customerForm.errors.legal_name"
          />
          <AppInput
            v-model="customerForm.tax_id"
            :label="taxIdLabel"
            :disabled="!can.manage"
            :error="customerForm.errors.tax_id"
          />

          <div v-if="can.manage">
            <AppButton type="submit" variant="primary" :loading="customerForm.processing">
              {{ t('portal.profile.save_company') }}
            </AppButton>
          </div>
        </form>
      </AppCard>
    </div>
  </ClientLayout>
</template>
