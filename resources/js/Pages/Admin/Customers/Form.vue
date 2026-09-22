<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import type { CustomFieldDefinition } from '../../../Components/CustomFieldInput.vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import CustomFieldInput from '../../../Components/CustomFieldInput.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
    tagIds: string[]
  } | null
  statuses: { value: string; label: string }[]
  tags: { id: string; name: string }[]
  customFields: CustomFieldDefinition[]
}>()

const isEditing = computed(() => props.customer !== null)

interface CustomerForm {
  company_name: string
  legal_name: string
  tax_id: string
  tax_id_type: string
  status: string
  currency_code: string
  marketing_opt_in: boolean
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
  <Head :title="isEditing ? 'Edit customer' : 'Add customer'" />

  <AdminLayout
    :heading="isEditing ? 'Edit customer' : 'Add customer'"
    description="A company name or a legal name is enough; sole traders do not need both."
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppCard title="Profile">
        <div class="grid max-w-xl gap-5">
          <AppInput
            v-model="form.company_name"
            label="Company name"
            :error="form.errors.company_name"
          />
          <AppInput v-model="form.legal_name" label="Legal name" :error="form.errors.legal_name" />
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput v-model="form.tax_id" label="Tax id" :error="form.errors.tax_id" />
            <AppInput
              v-model="form.tax_id_type"
              label="Tax id type"
              hint="For example VAT or ABN."
            />
          </div>
          <div class="grid gap-5 sm:grid-cols-2">
            <AppSelect
              v-model="form.status"
              label="Status"
              :options="statuses"
              :error="form.errors.status"
            />
            <AppInput
              v-model="form.currency_code"
              label="Currency"
              hint="Three-letter ISO code."
              :error="form.errors.currency_code"
              required
            />
          </div>
          <AppCheckbox
            v-model="form.marketing_opt_in"
            label="Opted in to marketing email"
            description="Transactional mail about services they pay for is always sent."
          />
        </div>
      </AppCard>

      <AppCard v-if="tags.length > 0" title="Tags">
        <div class="grid gap-3 sm:grid-cols-3">
          <AppCheckbox
            v-for="tag in tags"
            :key="tag.id"
            :model-value="form.tag_ids.includes(tag.id)"
            :label="tag.name"
            @update:model-value="(checked: boolean) => toggleTag(tag.id, checked)"
          />
        </div>
      </AppCard>

      <AppCard v-if="customFields.length > 0" title="Additional fields">
        <div class="grid max-w-xl gap-5">
          <CustomFieldInput
            v-for="field in customFields"
            :key="field.key"
            v-model="form.custom_fields"
            :field="field"
            :error="customFieldError(field.key)"
          />
        </div>
      </AppCard>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save changes' : 'Create customer' }}
        </AppButton>
        <AppButton
          :href="isEditing ? `/admin/customers/${customer?.id}` : '/admin/customers'"
          variant="ghost"
        >
          Cancel
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
