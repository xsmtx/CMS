<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppButton from '../../Components/AppButton.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppInput from '../../Components/AppInput.vue'
import AppSelect from '../../Components/AppSelect.vue'
import { useTaxIdentity } from '../../composables/useTaxIdentity'
import { useTranslations } from '../../composables/useTranslations'
import AuthLayout from '../../Layouts/AuthLayout.vue'

/**
 * Opening an account.
 *
 * Four groups, in the order somebody thinks about them: who they are, how
 * they will sign in, who they are buying as, and where to send the invoice.
 * The last two are optional and say so — an individual with no company and
 * no address finishes this form in six fields.
 */
const props = defineProps<{
  loginUrl: string
  currency: string
  currencies: string[]
  defaultCountry: string
  phonePlaceholder: string
}>()

const { t } = useTranslations()

// The shared prop rather than one of our own: a page prop named like a shared
// one wins for that screen and quietly takes the shell's copy with it.
const { label: taxIdentityLabel, requiredForBusiness } = useTaxIdentity()

const form = useForm({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  company_name: '',
  tax_id: '',
  currency_code: props.currency,
  address_line_one: '',
  address_line_two: '',
  city: '',
  region: '',
  postal_code: '',
  country_code: props.defaultCountry,
  marketing_opt_in: false,
})

/**
 * The currency is a choice only when there is one to make.
 *
 * It is written onto the customer and every price they are ever quoted
 * follows from it, so a single-currency installation states it rather than
 * asking a question with one answer.
 */
const currencyOptions = computed(() =>
  props.currencies.map((code) => ({ value: code, label: code })),
)

/**
 * A business is a company name, not a tax id, and whether one is obliged to
 * give an id is the seller's setting. The asterisk therefore appears exactly
 * when the server would refuse.
 */
const taxIdRequired = computed(() => requiredForBusiness.value && form.company_name.trim() !== '')

function submit(): void {
  // Both password fields are cleared whatever the outcome, so a refused
  // attempt never leaves a credential sitting in the DOM.
  form.post('/register', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head :title="t('ui.register.title')" />

  <AuthLayout wide :heading="t('ui.register.title')" :subheading="t('ui.register.intro')">
    <form class="flex flex-col gap-8" @submit.prevent="submit">
      <fieldset>
        <legend class="text-title mb-4 font-semibold">{{ t('ui.register.you') }}</legend>

        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.first_name"
            :label="t('ui.register.first_name')"
            autocomplete="given-name"
            :error="form.errors.first_name"
            required
          />
          <AppInput
            v-model="form.last_name"
            :label="t('ui.register.last_name')"
            autocomplete="family-name"
            :error="form.errors.last_name"
            required
          />
          <AppInput
            v-model="form.email"
            :label="t('ui.register.email')"
            type="email"
            autocomplete="email"
            :error="form.errors.email"
            required
          />
          <AppInput
            v-model="form.phone"
            :label="t('ui.register.phone')"
            type="tel"
            autocomplete="tel"
            :placeholder="phonePlaceholder"
            :error="form.errors.phone"
          />
        </div>
      </fieldset>

      <fieldset>
        <legend class="text-title mb-4 font-semibold">{{ t('ui.register.password_group') }}</legend>

        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.password"
            :label="t('ui.register.password')"
            type="password"
            autocomplete="new-password"
            :hint="t('ui.register.password_hint')"
            :error="form.errors.password"
            required
          />
          <AppInput
            v-model="form.password_confirmation"
            :label="t('ui.register.password_again')"
            type="password"
            autocomplete="new-password"
            :error="form.errors.password_confirmation"
            required
          />
        </div>
      </fieldset>

      <fieldset>
        <legend class="text-title font-semibold">{{ t('ui.register.company_group') }}</legend>
        <p class="text-content-muted text-chrome mt-1 mb-4 max-w-[60ch] leading-relaxed">
          {{ t('ui.register.company_hint') }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.company_name"
            :label="t('ui.register.company')"
            autocomplete="organization"
            :error="form.errors.company_name"
          />
          <AppInput
            v-model="form.tax_id"
            :label="taxIdentityLabel"
            autocomplete="off"
            :required="taxIdRequired"
            :error="form.errors.tax_id"
          />
          <AppSelect
            v-if="currencyOptions.length > 1"
            v-model="form.currency_code"
            :label="t('ui.register.currency')"
            :options="currencyOptions"
            :hint="t('ui.register.currency_hint')"
            :error="form.errors.currency_code"
          />
        </div>
      </fieldset>

      <fieldset>
        <legend class="text-title font-semibold">{{ t('ui.register.address_group') }}</legend>
        <p class="text-content-muted text-chrome mt-1 mb-4 max-w-[60ch] leading-relaxed">
          {{ t('ui.register.address_hint') }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.address_line_one"
            class="sm:col-span-2"
            :label="t('crm.fields.line_one')"
            autocomplete="address-line1"
            :error="form.errors.address_line_one"
          />
          <AppInput
            v-model="form.address_line_two"
            class="sm:col-span-2"
            :label="t('crm.fields.line_two')"
            autocomplete="address-line2"
            :error="form.errors.address_line_two"
          />
          <AppInput
            v-model="form.city"
            :label="t('crm.fields.city')"
            autocomplete="address-level2"
            :error="form.errors.city"
          />
          <AppInput
            v-model="form.region"
            :label="t('crm.fields.region')"
            autocomplete="address-level1"
            :error="form.errors.region"
          />
          <AppInput
            v-model="form.postal_code"
            :label="t('crm.fields.postal_code')"
            autocomplete="postal-code"
            :error="form.errors.postal_code"
          />
          <AppInput
            v-model="form.country_code"
            :label="t('crm.fields.country')"
            autocomplete="country"
            :hint="t('ui.register.country_hint')"
            :error="form.errors.country_code"
          />
        </div>
      </fieldset>

      <div class="flex flex-col gap-5">
        <AppCheckbox v-model="form.marketing_opt_in" :label="t('ui.register.marketing')" />

        <div>
          <AppButton type="submit" variant="primary" :loading="form.processing" class="w-full">
            {{ t('ui.register.submit') }}
          </AppButton>
        </div>
      </div>
    </form>

    <p class="text-content-muted text-body mt-6">
      {{ t('ui.register.have_account') }}
      <Link :href="loginUrl" class="text-brand underline underline-offset-4">
        {{ t('ui.register.sign_in') }}
      </Link>
    </p>
  </AuthLayout>
</template>
