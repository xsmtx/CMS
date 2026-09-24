<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'

import AppAlert from '../../Components/AppAlert.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCard from '../../Components/AppCard.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppInput from '../../Components/AppInput.vue'
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
  <Head title="Profile" />

  <ClientLayout heading="Profile" description="Your details and how we contact you.">
    <div class="flex flex-col gap-5">
      <AppAlert v-if="page.props.flash?.status" tone="success">
        {{ page.props.flash.status }}
      </AppAlert>

      <AppCard title="You">
        <form
          class="grid max-w-xl gap-5"
          @submit.prevent="meForm.put('/client/profile', { preserveScroll: true })"
        >
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="meForm.first_name"
              label="First name"
              :error="meForm.errors.first_name"
              required
            />
            <AppInput
              v-model="meForm.last_name"
              label="Last name"
              :error="meForm.errors.last_name"
              required
            />
          </div>

          <AppInput
            :model-value="me.email"
            label="Email address"
            hint="Contact support to change the address you sign in with."
            disabled
            @update:model-value="() => {}"
          />

          <AppInput v-model="meForm.phone" label="Phone" :error="meForm.errors.phone" />

          <fieldset class="flex flex-col gap-3">
            <legend class="text-body mb-1 font-medium">Email preferences</legend>
            <p class="text-content-muted text-chrome -mt-1 mb-2">
              Invoices and service notices about what you pay for are always sent.
            </p>
            <AppCheckbox v-model="meForm.notify_invoices" label="Invoices and receipts" />
            <AppCheckbox v-model="meForm.notify_support" label="Support replies" />
            <AppCheckbox v-model="meForm.notify_product" label="Product announcements" />
            <AppCheckbox v-model="meForm.notify_marketing" label="Offers and marketing" />
          </fieldset>

          <div>
            <AppButton type="submit" variant="primary" :loading="meForm.processing">
              Save my details
            </AppButton>
          </div>
        </form>
      </AppCard>

      <AppCard
        title="Company"
        :description="
          can.manage
            ? 'Shown on your invoices.'
            : 'Only the account owner can change these. Contact them or our support team.'
        "
      >
        <form
          class="grid max-w-xl gap-5"
          @submit.prevent="customerForm.put('/client/profile/customer', { preserveScroll: true })"
        >
          <AppInput
            v-model="customerForm.company_name"
            label="Company name"
            :disabled="!can.manage"
            :error="customerForm.errors.company_name"
          />
          <AppInput
            v-model="customerForm.legal_name"
            label="Legal name"
            :disabled="!can.manage"
            :error="customerForm.errors.legal_name"
          />
          <AppInput
            v-model="customerForm.tax_id"
            label="Tax id"
            :disabled="!can.manage"
            :error="customerForm.errors.tax_id"
          />

          <div v-if="can.manage">
            <AppButton type="submit" variant="primary" :loading="customerForm.processing">
              Save company details
            </AppButton>
          </div>
        </form>
      </AppCard>
    </div>
  </ClientLayout>
</template>
