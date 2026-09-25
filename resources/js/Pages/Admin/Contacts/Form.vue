<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

const props = defineProps<{
  customer: { id: string; name: string }
  contact: {
    id: string
    firstName: string
    lastName: string
    email: string
    phone: string | null
    portalAccess: boolean
    isPrimary: boolean
    status: string
    notifyInvoices: boolean
    notifySupport: boolean
    notifyProduct: boolean
    notifyMarketing: boolean
  } | null
  statuses: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const isEditing = computed(() => props.contact !== null)

const heading = computed(() =>
  isEditing.value ? t('ui.contact_form.edit') : t('ui.contact_form.add'),
)

const form = useForm({
  first_name: props.contact?.firstName ?? '',
  last_name: props.contact?.lastName ?? '',
  email: props.contact?.email ?? '',
  phone: props.contact?.phone ?? '',
  portal_access: props.contact?.portalAccess ?? false,
  is_primary: props.contact?.isPrimary ?? false,
  status: props.contact?.status ?? 'active',
  notify_invoices: props.contact?.notifyInvoices ?? true,
  notify_support: props.contact?.notifySupport ?? true,
  notify_product: props.contact?.notifyProduct ?? true,
  notify_marketing: props.contact?.notifyMarketing ?? false,
})

function submit(): void {
  if (props.contact) {
    form.put(`/admin/customers/${props.customer.id}/contacts/${props.contact.id}`)
    return
  }

  form.post(`/admin/customers/${props.customer.id}/contacts`)
}
</script>

<template>
  <Head :title="heading" />

  <AdminLayout :heading="heading">
    <template #header>
      <PageHeader
        :title="heading"
        :description="t('ui.contact_form.intro', { customer: customer.name })"
      />
    </template>

    <form class="flex max-w-4xl flex-col gap-8" @submit.prevent="submit">
      <DetailSection :title="t('ui.contact_form.person')">
        <div class="grid max-w-xl gap-5">
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="form.first_name"
              :label="t('ui.client_new.first_name')"
              :error="form.errors.first_name"
              required
            />
            <AppInput
              v-model="form.last_name"
              :label="t('ui.client_new.last_name')"
              :error="form.errors.last_name"
              required
            />
          </div>
          <AppInput
            v-model="form.email"
            :label="t('ui.client_new.email')"
            type="email"
            :hint="t('ui.contact_form.email_hint')"
            :error="form.errors.email"
            required
          />
          <AppInput
            v-model="form.phone"
            :label="t('ui.contact_form.phone')"
            :error="form.errors.phone"
          />
          <AppSelect
            v-model="form.status"
            :label="t('ui.client_new.status')"
            :options="statuses"
            :error="form.errors.status"
          />
        </div>
      </DetailSection>

      <DetailSection :title="t('ui.contact_form.access')">
        <div class="flex max-w-xl flex-col gap-4">
          <AppCheckbox
            v-model="form.portal_access"
            :label="t('ui.contact_form.portal')"
            :description="t('ui.contact_form.portal_hint')"
          />
          <AppCheckbox
            v-model="form.is_primary"
            :label="t('ui.contact_form.primary')"
            :description="t('ui.contact_form.primary_hint')"
          />

          <AppAlert v-if="isEditing && !form.portal_access" tone="info">
            {{ t('ui.contact_form.access_off') }}
          </AppAlert>
        </div>
      </DetailSection>

      <DetailSection
        :title="t('ui.contact_form.notifications')"
        :description="t('ui.contact_form.notifications_intro')"
      >
        <div class="grid max-w-xl gap-3 sm:grid-cols-2">
          <AppCheckbox v-model="form.notify_invoices" :label="t('ui.contact_form.invoices')" />
          <AppCheckbox v-model="form.notify_support" :label="t('ui.contact_form.support')" />
          <AppCheckbox v-model="form.notify_product" :label="t('ui.contact_form.product')" />
          <AppCheckbox v-model="form.notify_marketing" :label="t('ui.contact_form.marketing')" />
        </div>
      </DetailSection>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? t('ui.client_form.save') : t('ui.contact_form.add') }}
        </AppButton>
        <AppButton :href="`/admin/customers/${customer.id}`" variant="ghost">
          {{ t('ui.confirm.cancel') }}
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
