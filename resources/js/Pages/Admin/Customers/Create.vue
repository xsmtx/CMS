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
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import CustomFieldInput from '../../../Components/CustomFieldInput.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTaxIdentity } from '../../../composables/useTaxIdentity'

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
  <Head title="Add new client" />

  <AdminLayout
    heading="Add new client"
    description="The company, the first person and where to invoice them, in one go."
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppCard title="The person">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.first_name"
            label="First name"
            :error="form.errors.first_name"
            required
          />
          <AppInput
            v-model="form.last_name"
            label="Last name"
            :error="form.errors.last_name"
            required
          />
          <AppInput
            v-model="form.email"
            type="email"
            label="Email address"
            :error="form.errors.email"
            required
          />
          <AppInput
            v-model="form.phone"
            label="Phone number"
            :placeholder="defaults.phonePlaceholder"
            :error="form.errors.phone"
          />
          <AppSelect
            v-model="form.locale"
            label="Language"
            :options="locales"
            :error="form.errors.locale"
          />
          <AppSelect v-model="form.role" label="Role" :options="roles" :error="form.errors.role" />
        </div>

        <p v-if="chosenRole" class="text-content-muted mt-4 text-xs leading-relaxed">
          <span class="text-content font-medium">{{ chosenRole.label }}</span> can:
          <span v-if="chosenRole.can.length > 0" class="font-mono">
            {{ chosenRole.can.join(', ') }}
          </span>
          <span v-else>nothing yet — this role holds no permissions.</span>
        </p>

        <div class="border-line mt-5 grid gap-5 border-t pt-5 sm:grid-cols-2">
          <AppInput
            v-model="form.password"
            type="password"
            label="Password"
            autocomplete="new-password"
            hint="Leave blank and they set their own from the welcome email."
            :error="form.errors.password"
          />
          <AppInput
            v-if="settingPassword"
            v-model="form.password_confirmation"
            type="password"
            label="Confirm password"
            autocomplete="new-password"
          />
        </div>
      </AppCard>

      <AppCard title="The company">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.company_name"
            label="Company name"
            hint="Leave blank for an individual."
            :error="form.errors.company_name"
          />
          <AppInput v-model="form.legal_name" label="Legal name" :error="form.errors.legal_name" />
          <AppInput v-model="form.tax_id" :label="taxIdLabel" :error="form.errors.tax_id" />
          <AppInput
            v-model="form.tax_id_type"
            label="Tax id type"
            hint="For example VAT or VKN."
            :error="form.errors.tax_id_type"
          />
          <AppSelect
            v-model="form.status"
            label="Status"
            :options="statuses"
            :error="form.errors.status"
          />
          <AppSelect
            v-model="form.currency_code"
            label="Currency"
            :options="currencies"
            hint="Fixed for the life of the account: nothing is converted at display time."
            :error="form.errors.currency_code"
          />
        </div>

        <div v-if="tags.length > 0" class="border-line mt-5 border-t pt-5">
          <p class="mb-3 text-sm font-medium">Client group</p>
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
      </AppCard>

      <AppCard
        title="Where to invoice them"
        description="Optional here, but an invoice cannot be issued without it."
      >
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.address_line_one"
            label="Address 1"
            :error="form.errors.address_line_one"
          />
          <AppInput
            v-model="form.address_line_two"
            label="Address 2"
            :error="form.errors.address_line_two"
          />
          <AppInput v-model="form.city" label="City" :error="form.errors.city" />
          <AppInput v-model="form.region" label="State or region" :error="form.errors.region" />
          <AppInput v-model="form.postal_code" label="Postcode" :error="form.errors.postal_code" />
          <AppInput
            v-model="form.country_code"
            label="Country"
            hint="Two-letter ISO code, for example TR."
            :error="form.errors.country_code"
          />
        </div>
      </AppCard>

      <AppCard
        title="What we send them"
        description="Transactional mail about something they pay for is always sent; these are the rest."
      >
        <div class="grid gap-4 sm:grid-cols-2">
          <AppCheckbox v-model="form.notify_invoices" label="Invoice emails" />
          <AppCheckbox v-model="form.notify_support" label="Support emails" />
          <AppCheckbox v-model="form.notify_product" label="Product and domain emails" />
          <AppCheckbox v-model="form.notify_marketing" label="Marketing emails" />
        </div>

        <div class="border-line mt-5 border-t pt-5">
          <AppCheckbox
            v-model="form.marketing_opt_in"
            label="Opted in to marketing on the account"
            description="The company's own answer, recorded next to the person's. Consent is asked once and honoured everywhere."
          />
        </div>
      </AppCard>

      <AppCard title="Billing preferences">
        <div class="grid gap-4">
          <AppCheckbox
            v-model="form.send_overdue_notices"
            label="Send overdue notices"
            description="Turn this off for an account somebody chases by telephone. The dunning sequence still runs; it just says nothing."
          />
          <AppCheckbox
            v-model="form.automatic_suspension"
            label="Allow automatic suspension"
            description="Whether dunning may suspend or terminate this customer's services when an invoice goes unpaid."
          />
          <AppCheckbox
            v-model="form.separate_invoices"
            label="Invoice each item separately"
            description="Off means one invoice per currency for everything renewing together, which is what most customers want."
          />
        </div>
      </AppCard>

      <AppCard
        v-if="customFields.length > 0"
        title="Additional fields"
        description="Defined by this installation."
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
      </AppCard>

      <AppCard title="Finish">
        <div class="grid gap-5">
          <AppTextarea
            v-model="form.notes"
            label="Admin notes"
            :rows="4"
            hint="Internal. Never shown to the customer."
            :error="form.errors.notes"
          />
          <AppCheckbox
            v-model="form.send_welcome"
            label="Send a new account information message"
            description="Goes out through the delivery log like every other message, and respects what they chose above."
          />
        </div>
      </AppCard>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">Add client</AppButton>
        <AppButton href="/admin/customers" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
