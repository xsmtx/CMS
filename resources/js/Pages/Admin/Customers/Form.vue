<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import type { CustomFieldDefinition } from '../../../Components/CustomFieldInput.vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import CustomFieldInput from '../../../Components/CustomFieldInput.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTaxIdentity } from '../../../composables/useTaxIdentity'
import { useTranslations } from '../../../composables/useTranslations'

const props = defineProps<{
  customer: {
    id: string
    companyName: string | null
    legalName: string | null
    taxId: string | null
    taxIdType: string | null
    status: string
    currencyCode: string
    marketingOptIn: boolean
    sendOverdueNotices: boolean
    automaticSuspension: boolean
    separateInvoices: boolean
    tagIds: string[]
  } | null
  statuses: { value: string; label: string }[]
  tags: { id: string; name: string }[]
  customFields: CustomFieldDefinition[]
}>()

// The seller's own word for a tax id. "VAT number" is wrong in most of the
// world, and an operator entering a Turkish customer should read Vergi No.
const { label: taxIdLabel } = useTaxIdentity()

const { t } = useTranslations()

const isEditing = computed(() => props.customer !== null)

const heading = computed(() =>
  isEditing.value ? t('ui.client_form.edit') : t('ui.client_form.add'),
)

interface CustomerForm {
  company_name: string
  legal_name: string
  tax_id: string
  tax_id_type: string
  status: string
  currency_code: string
  marketing_opt_in: boolean
  send_overdue_notices: boolean
  automatic_suspension: boolean
  separate_invoices: boolean
  tag_ids: string[]
  custom_fields: Record<string, string>
}

const form = useForm<CustomerForm>({
  company_name: props.customer?.companyName ?? '',
  legal_name: props.customer?.legalName ?? '',
  tax_id: props.customer?.taxId ?? '',
  tax_id_type: props.customer?.taxIdType ?? '',
  status: props.customer?.status ?? 'pending',
  currency_code: props.customer?.currencyCode ?? 'EUR',
  marketing_opt_in: props.customer?.marketingOptIn ?? false,
  send_overdue_notices: props.customer?.sendOverdueNotices ?? true,
  automatic_suspension: props.customer?.automaticSuspension ?? true,
  separate_invoices: props.customer?.separateInvoices ?? false,
  tag_ids: props.customer?.tagIds ?? [],
  custom_fields: Object.fromEntries(
    props.customFields.map((field) => [field.key, String(field.value ?? '')]),
  ),
})

/**
 * Custom field errors arrive keyed by dotted path, which is not a key of the
 * form's own shape. Reading them through an index signature keeps the rest
 * of the form strongly typed.
 */
function customFieldError(key: string): string | undefined {
  return (form.errors as Record<string, string | undefined>)[`custom_fields.${key}`]
}

function toggleTag(id: string, checked: boolean): void {
  form.tag_ids = checked ? [...form.tag_ids, id] : form.tag_ids.filter((value) => value !== id)
}

function submit(): void {
  if (props.customer) {
    form.put(`/admin/customers/${props.customer.id}`)
    return
  }

  form.post('/admin/customers')
}
</script>

<template>
  <Head :title="heading" />

  <AdminLayout :heading="heading">
    <template #header>
      <PageHeader :title="heading" :description="t('ui.client_form.intro')" />
    </template>

    <form class="flex max-w-4xl flex-col gap-8" @submit.prevent="submit">
      <DetailSection :title="t('ui.client_form.profile')">
        <div class="grid max-w-xl gap-5">
          <AppInput
            v-model="form.company_name"
            :label="t('ui.client_new.company_name')"
            :error="form.errors.company_name"
          />
          <AppInput
            v-model="form.legal_name"
            :label="t('ui.client_new.legal_name')"
            :error="form.errors.legal_name"
          />
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput v-model="form.tax_id" :label="taxIdLabel" :error="form.errors.tax_id" />
            <AppInput
              v-model="form.tax_id_type"
              :label="t('ui.client_new.tax_id_type')"
              :hint="t('ui.client_new.tax_id_type_hint')"
            />
          </div>
          <div class="grid gap-5 sm:grid-cols-2">
            <AppSelect
              v-model="form.status"
              :label="t('ui.client_new.status')"
              :options="statuses"
              :error="form.errors.status"
            />
            <AppInput
              v-model="form.currency_code"
              :label="t('ui.client_new.currency')"
              :hint="t('ui.client_form.currency_hint')"
              :error="form.errors.currency_code"
              required
            />
          </div>
          <AppCheckbox
            v-model="form.marketing_opt_in"
            :label="t('ui.client_form.marketing')"
            :description="t('ui.client_form.marketing_hint')"
          />
        </div>
      </DetailSection>

      <!--
        The same three settings the create screen states, reading the same
        strings: two copies of a sentence about automatic suspension is two
        places for it to drift.
      -->
      <DetailSection :title="t('ui.client_new.billing')">
        <div class="grid max-w-xl gap-4">
          <AppCheckbox
            v-model="form.send_overdue_notices"
            :label="t('ui.client_new.overdue')"
            :description="t('ui.client_new.overdue_hint')"
          />
          <AppCheckbox
            v-model="form.automatic_suspension"
            :label="t('ui.client_new.suspension')"
            :description="t('ui.client_new.suspension_hint')"
          />
          <AppCheckbox
            v-model="form.separate_invoices"
            :label="t('ui.client_new.separate')"
            :description="t('ui.client_new.separate_hint')"
          />
        </div>
      </DetailSection>

      <DetailSection
        v-if="tags.length > 0"
        :title="t('ui.client_form.tags')"
        :description="t('ui.client_form.tags_intro')"
      >
        <div class="grid gap-3 sm:grid-cols-3">
          <AppCheckbox
            v-for="tag in tags"
            :key="tag.id"
            :model-value="form.tag_ids.includes(tag.id)"
            :label="tag.name"
            @update:model-value="(checked: boolean) => toggleTag(tag.id, checked)"
          />
        </div>
      </DetailSection>

      <DetailSection
        v-if="customFields.length > 0"
        :title="t('ui.client_new.additional')"
        :description="t('ui.client_new.additional_intro')"
      >
        <div class="grid max-w-xl gap-5">
          <CustomFieldInput
            v-for="field in customFields"
            :key="field.key"
            v-model="form.custom_fields"
            :field="field"
            :error="customFieldError(field.key)"
          />
        </div>
      </DetailSection>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? t('ui.client_form.save') : t('ui.client_form.create') }}
        </AppButton>
        <AppButton
          :href="isEditing ? `/admin/customers/${customer?.id}` : '/admin/customers'"
          variant="ghost"
        >
          {{ t('ui.confirm.cancel') }}
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
