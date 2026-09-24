<script setup lang="ts">
/**
 * Adding a client the way an operator does it: on the telephone, once.
 *
 * `Form.vue` edits the commercial record alone, which is right for a
 * correction. This screen creates the company, the person and the address
 * together, because a customer with no contact cannot sign in and one with
 * no address cannot be invoiced — and an operator who has to visit three
 * screens will finish one of them.
 *
 * Anything local — a national identity number, a tax office, a second
 * mobile — arrives as a custom field. Every customer custom field this
 * installation has defined appears here automatically, typed the way it
 * was defined, so a Turkish installation is not a special case in the code.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import type { CustomFieldDefinition } from '../../../Components/CustomFieldInput.vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import CustomFieldInput from '../../../Components/CustomFieldInput.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTaxIdentity } from '../../../composables/useTaxIdentity'
import { useTranslations } from '../../../composables/useTranslations'

interface Option {
  value: string
  label: string
}

interface RoleOption extends Option {
  can: string[]
}

const props = defineProps<{
  statuses: Option[]
  currencies: Option[]
  locales: Option[]
  tags: Option[]
  roles: RoleOption[]
  customFields: CustomFieldDefinition[]
  defaults: {
    country: string
    currency: string
    locale: string
    phonePlaceholder: string
  }
}>()

// The seller's own word for a tax id. "VAT number" is wrong in most of the
// world, and an operator entering a Turkish customer should read Vergi No.
const { label: taxIdLabel } = useTaxIdentity()

const { t } = useTranslations()

interface ClientForm {
  first_name: string
  last_name: string
  email: string
  phone: string
  locale: string
  password: string
  password_confirmation: string
  role: string

  company_name: string
  legal_name: string
  tax_id: string
  tax_id_type: string
  status: string
  currency_code: string
  tag_ids: string[]

  address_line_one: string
  address_line_two: string
  city: string
  region: string
  postal_code: string
  country_code: string

  notify_invoices: boolean
  notify_support: boolean
  notify_product: boolean
  notify_marketing: boolean
  marketing_opt_in: boolean

  send_overdue_notices: boolean
  automatic_suspension: boolean
  separate_invoices: boolean

  custom_fields: Record<string, string>
  notes: string
  send_welcome: boolean
}

const form = useForm<ClientForm>({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  locale: props.defaults.locale,
  password: '',
  password_confirmation: '',
  role: props.roles[0]?.value ?? 'account-owner',

  company_name: '',
  legal_name: '',
  tax_id: '',
  tax_id_type: '',
  status: 'active',
  currency_code: props.defaults.currency,
  tag_ids: [],

  address_line_one: '',
  address_line_two: '',
  city: '',
  region: '',
  postal_code: '',
  country_code: props.defaults.country,

  notify_invoices: true,
  notify_support: true,
  notify_product: true,
  notify_marketing: false,
  marketing_opt_in: false,

  send_overdue_notices: true,
  automatic_suspension: true,
  separate_invoices: false,

  custom_fields: Object.fromEntries(props.customFields.map((field) => [field.key, ''])),
  notes: '',
  send_welcome: true,
})

const chosenRole = computed(() => props.roles.find((role) => role.value === form.role) ?? null)

/**
 * A password set here is read out over the telephone. Leaving it blank is
 * the better answer — the reset link does the same job without anybody
 * saying a password aloud — so the field says so rather than being
 * required.
 */
const settingPassword = computed(() => form.password !== '' || form.password_confirmation !== '')

function customFieldError(key: string): string | undefined {
  return (form.errors as Record<string, string | undefined>)[`custom_fields.${key}`]
}

function toggleTag(id: string, checked: boolean): void {
  form.tag_ids = checked ? [...form.tag_ids, id] : form.tag_ids.filter((value) => value !== id)
}

function submit(): void {
  form.post('/admin/clients')
}
</script>

<template>
  <Head :title="t('ui.client_new.title')" />

  <AdminLayout :heading="t('ui.client_new.title')">
    <template #header>
      <PageHeader :title="t('ui.client_new.title')" :description="t('ui.client_new.intro')" />
    </template>

    <!--
      Capped rather than run to the window edge. Two columns across 1200px
      gives a first-name field 580px wide, which reads as a mistake; a form is
      prose with boxes in it and takes a prose measure.
    -->
    <form class="flex max-w-4xl flex-col gap-8" @submit.prevent="submit">
      <DetailSection :title="t('ui.client_new.person')">
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
          <AppInput
            v-model="form.email"
            type="email"
            :label="t('ui.client_new.email')"
            :error="form.errors.email"
            required
          />
          <AppInput
            v-model="form.phone"
            :label="t('ui.client_new.phone')"
            :placeholder="defaults.phonePlaceholder"
            :error="form.errors.phone"
          />
          <AppSelect
            v-model="form.locale"
            :label="t('ui.client_new.language')"
            :options="locales"
            :error="form.errors.locale"
          />
          <AppSelect
            v-model="form.role"
            :label="t('ui.client_new.role')"
            :options="roles"
            :error="form.errors.role"
          />
        </div>

        <p v-if="chosenRole" class="text-content-muted text-chrome mt-4 leading-relaxed">
          <span class="text-content font-medium">{{ chosenRole.label }}</span>
          {{ t('ui.client_new.role_can') }}
          <!-- Not mono: these are sentences a person wrote, not identifiers.
               The monospace face is for an IP, a ULID or a hostname. -->
          <span v-if="chosenRole.can.length > 0" class="text-content">
            {{ chosenRole.can.join(', ') }}
          </span>
          <span v-else>{{ t('ui.client_new.role_none') }}</span>
        </p>

        <div class="border-line-subtle mt-5 grid gap-5 border-t pt-5 sm:grid-cols-2">
          <AppInput
            v-model="form.password"
            type="password"
            :label="t('ui.client_new.password')"
            autocomplete="new-password"
            :hint="t('ui.client_new.password_hint')"
            :error="form.errors.password"
          />
          <AppInput
            v-if="settingPassword"
            v-model="form.password_confirmation"
            type="password"
            :label="t('ui.client_new.password_confirm')"
            autocomplete="new-password"
          />
        </div>
      </DetailSection>

      <DetailSection :title="t('ui.client_new.company')">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.company_name"
            :label="t('ui.client_new.company_name')"
            :hint="t('ui.client_new.company_name_hint')"
            :error="form.errors.company_name"
          />
          <AppInput
            v-model="form.legal_name"
            :label="t('ui.client_new.legal_name')"
            :error="form.errors.legal_name"
          />
          <AppInput v-model="form.tax_id" :label="taxIdLabel" :error="form.errors.tax_id" />
          <AppInput
            v-model="form.tax_id_type"
            :label="t('ui.client_new.tax_id_type')"
            :hint="t('ui.client_new.tax_id_type_hint')"
            :error="form.errors.tax_id_type"
          />
          <AppSelect
            v-model="form.status"
            :label="t('ui.client_new.status')"
            :options="statuses"
            :error="form.errors.status"
          />
          <AppSelect
            v-model="form.currency_code"
            :label="t('ui.client_new.currency')"
            :options="currencies"
            :hint="t('ui.client_new.currency_hint')"
            :error="form.errors.currency_code"
          />
        </div>

        <div v-if="tags.length > 0" class="border-line-subtle mt-5 border-t pt-5">
          <p class="text-content-subtle text-label mb-3 uppercase">
            {{ t('ui.client_new.group') }}
          </p>
          <div class="grid gap-3 sm:grid-cols-3">
            <AppCheckbox
              v-for="tag in tags"
              :key="tag.value"
              :model-value="form.tag_ids.includes(tag.value)"
              :label="tag.label"
              @update:model-value="(checked: boolean) => toggleTag(tag.value, checked)"
            />
          </div>
        </div>
      </DetailSection>

      <DetailSection
        :title="t('ui.client_new.address')"
        :description="t('ui.client_new.address_intro')"
      >
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.address_line_one"
            :label="t('ui.client_new.address_one')"
            :error="form.errors.address_line_one"
          />
          <AppInput
            v-model="form.address_line_two"
            :label="t('ui.client_new.address_two')"
            :error="form.errors.address_line_two"
          />
          <AppInput
            v-model="form.city"
            :label="t('ui.client_new.city')"
            :error="form.errors.city"
          />
          <AppInput
            v-model="form.region"
            :label="t('ui.client_new.region')"
            :error="form.errors.region"
          />
          <AppInput
            v-model="form.postal_code"
            :label="t('ui.client_new.postcode')"
            :error="form.errors.postal_code"
          />
          <AppInput
            v-model="form.country_code"
            :label="t('ui.client_new.country')"
            :hint="t('ui.client_new.country_hint')"
            :error="form.errors.country_code"
          />
        </div>
      </DetailSection>

      <DetailSection :title="t('ui.client_new.mail')" :description="t('ui.client_new.mail_intro')">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppCheckbox v-model="form.notify_invoices" :label="t('ui.client_new.mail_invoices')" />
          <AppCheckbox v-model="form.notify_support" :label="t('ui.client_new.mail_support')" />
          <AppCheckbox v-model="form.notify_product" :label="t('ui.client_new.mail_product')" />
          <AppCheckbox v-model="form.notify_marketing" :label="t('ui.client_new.mail_marketing')" />
        </div>

        <div class="border-line-subtle mt-5 border-t pt-5">
          <AppCheckbox
            v-model="form.marketing_opt_in"
            :label="t('ui.client_new.opt_in')"
            :description="t('ui.client_new.opt_in_hint')"
          />
        </div>
      </DetailSection>

      <DetailSection :title="t('ui.client_new.billing')">
        <div class="grid gap-4">
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

      <DetailSection :title="t('ui.client_new.finish')">
        <div class="grid gap-5">
          <AppTextarea
            v-model="form.notes"
            :label="t('ui.client_new.notes')"
            :rows="4"
            :hint="t('ui.client_new.notes_hint')"
            :error="form.errors.notes"
          />
          <AppCheckbox
            v-model="form.send_welcome"
            :label="t('ui.client_new.welcome')"
            :description="t('ui.client_new.welcome_hint')"
          />
        </div>
      </DetailSection>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ t('ui.client_new.submit') }}
        </AppButton>
        <AppButton href="/admin/customers" variant="ghost">
          {{ t('ui.confirm.cancel') }}
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
