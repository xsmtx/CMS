<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppCard from '../../../Components/AppCard.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTaxIdentity } from '../../../composables/useTaxIdentity'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
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

// The seller's own word for it, not "VAT number": that is wrong in most
// of the world, and this customer is reading their own invoice's vocabulary.
const { label: taxIdLabel } = useTaxIdentity()

const METHOD_COLUMNS: TableColumn[] = [
  { key: 'method', label: t('billing.portal.methods_title'), sticky: true },
  { key: 'expiry', label: t('billing.methods.expires') },
  { key: 'actions', label: '' },
]

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

/**
 * Removing a card used to happen on the first click.
 *
 * What goes is the instrument the next renewal would have been charged to, so
 * the sentence says that rather than "are you sure" — level 2, because
 * nothing is lost that the customer cannot add again the next time they pay.
 */
const removing = ref<StoredMethod | null>(null)

function save(): void {
  form.put('/client/billing/details', { preserveScroll: true })
}

function makeDefault(id: string): void {
  router.put(`/client/billing/methods/${id}/default`, {}, { preserveScroll: true })
}

function remove(): void {
  const method = removing.value

  if (method === null) return

  router.delete(`/client/billing/methods/${method.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}

function describe(method: StoredMethod): string {
  const name = method.brand ?? method.gateway

  return method.lastFour === null ? name : `${name} •••• ${method.lastFour}`
}
</script>

<template>
  <Head :title="t('billing.portal.details_title')" />

  <ClientLayout
    :heading="t('billing.portal.details_title')"
    :description="t('billing.portal.details_description')"
  >
    <BillingTabs current="details" />

    <div class="flex flex-col gap-8">
      <!--
        No heading: the page is already called Billing details, and a section
        titled the same as its page is a heading that says nothing twice. The
        note stays, because it is the one thing on this screen a customer
        misunderstands — an issued invoice keeps the details it was issued
        with.
      -->
      <div>
        <p class="text-content-muted text-body mb-5 max-w-[60ch] leading-relaxed">
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
          <AppInput v-model="form.tax_id" :label="taxIdLabel" :error="form.errors.tax_id" />
        </div>

        <h3 class="text-content-subtle text-label mt-6 mb-3 uppercase">
          {{ t('billing.portal.address') }}
        </h3>

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
      </div>

      <AppCard :flush="methods.length > 0" :title="t('billing.portal.methods_title')">
        <AppTable v-if="methods.length > 0" flush name="portal-methods" :columns="METHOD_COLUMNS">
          <AppTableRow v-for="method in methods" :key="method.id">
            <td data-col="method">
              <span class="font-medium">{{ describe(method) }}</span>
              <AppBadge v-if="method.isDefault" class="ml-2" tone="brand">
                {{ t('billing.portal.default') }}
              </AppBadge>
            </td>
            <td data-col="expiry" class="text-content-muted">{{ method.expiry ?? '—' }}</td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions inline-flex gap-1">
                <AppButton
                  v-if="!method.isDefault"
                  size="sm"
                  variant="ghost"
                  @click="makeDefault(method.id)"
                >
                  {{ t('billing.portal.make_default') }}
                </AppButton>
                <AppButton size="sm" variant="danger-subtle" @click="removing = method">
                  {{ t('billing.portal.remove') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          variant="plain"
          icon="billing"
          :title="t('billing.portal.methods_none')"
          :description="t('billing.portal.methods_add_note')"
        />
      </AppCard>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('billing.portal.remove_title', { method: removing ? describe(removing) : '' })"
      :description="t('billing.portal.remove_detail')"
      :confirm-label="t('billing.portal.remove')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </ClientLayout>
</template>
