<script setup lang="ts">
/**
 * Who this business buys from, and what it agreed (§24).
 *
 * **Contracts first, soonest decision at the top**, because that is the one
 * question somebody opens this screen for. A list of vendors alphabetically
 * is an address book; a list of what has to be decided this month is the
 * reason the feature exists.
 *
 * The date in the first column is the **decision** date, not the end date: on
 * an auto-renewing contract those are different days, and showing the end
 * would be telling somebody about a deadline they had already missed.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MoneyInput from '../../../Components/MoneyInput.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { type StatusTone } from '../../../status'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface VendorRow {
  id: string
  name: string
  kind: string
  kindLabel: string
  contactName: string | null
  contactEmail: string | null
  contactPhone: string | null
  reference: string | null
  contracts: number
}

interface ContractRow {
  id: string
  vendorId: string
  vendor: string
  title: string
  reference: string | null
  term: string
  termLabel: string
  amount: string
  amountMinor: number
  currency: string
  startsOn: string | null
  endsOn: string | null
  autoRenews: boolean
  noticeDays: number | null
  decideBy: string | null
  daysRemaining: number | null
  note: string | null
}

interface Option {
  value: string
  label: string
}

defineProps<{
  vendors: VendorRow[]
  contracts: ContractRow[]
  kinds: Option[]
  terms: Option[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

/*
 * The reader's own locale, not the operating system's. `toLocaleDateString()`
 * with no argument follows the machine, which is invisible on `01.11.2026`
 * and glaring the moment a month name appears.
 */
const locale = computed(() => usePage().props.locale ?? 'en')

const addingVendor = ref(false)
const addingContractFor = ref<VendorRow | null>(null)
const removingVendor = ref<VendorRow | null>(null)
const removingContract = ref<ContractRow | null>(null)

const vendorForm = useForm({
  name: '',
  kind: 'software',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  account_reference: '',
  note: '',
})

const contractForm = useForm({
  title: '',
  reference: '',
  term: 'yearly',
  currency_code: 'EUR',
  amount_minor: 0,
  starts_on: '',
  ends_on: '',
  auto_renews: false,
  notice_days: '',
  note: '',
})

const CONTRACT_COLUMNS: TableColumn[] = [
  { key: 'decide', label: t('vendors.contracts.decide_by') },
  { key: 'title', label: t('vendors.contracts.contract_title') },
  // Singular: a column header names what is in a row, and the section
  // heading above it is not that.
  { key: 'vendor', label: t('vendors.contracts.vendor') },
  { key: 'term', label: t('vendors.contracts.term') },
  { key: 'amount', label: t('vendors.contracts.amount'), numeric: true },
  { key: 'actions', label: '' },
]

const VENDOR_COLUMNS: TableColumn[] = [
  { key: 'name', label: t('vendors.name') },
  { key: 'kind', label: t('vendors.kind') },
  { key: 'contact', label: t('vendors.contact_name') },
  { key: 'reference', label: t('vendors.account_reference') },
  { key: 'actions', label: '' },
]

/**
 * A contract with no end date is not overdue and never will be, so it gets
 * the neutral mark rather than a figure. Drawing it as zero days would make
 * a rolling agreement look like the most urgent row on the screen.
 */
function tone(row: ContractRow): StatusTone {
  if (row.daysRemaining === null) return 'neutral'
  if (row.daysRemaining < 0) return 'critical'
  if (row.daysRemaining <= 30) return 'warning'

  return 'healthy'
}

function remaining(row: ContractRow): string {
  if (row.daysRemaining === null) return t('vendors.contracts.rolling')
  if (row.daysRemaining < 0) return t('vendors.contracts.overdue')

  return t('vendors.contracts.days_left', { count: row.daysRemaining })
}

function when(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleDateString(locale.value)
}

function saveVendor(): void {
  vendorForm.post('/admin/vendors', {
    preserveScroll: true,
    onSuccess: () => {
      vendorForm.reset()
      addingVendor.value = false
    },
  })
}

function saveContract(): void {
  const vendor = addingContractFor.value

  if (vendor === null) return

  contractForm.post(`/admin/vendors/${vendor.id}/contracts`, {
    preserveScroll: true,
    onSuccess: () => {
      contractForm.reset()
      addingContractFor.value = null
    },
  })
}

function deleteVendor(): void {
  const vendor = removingVendor.value

  if (vendor === null) return

  removingVendor.value = null
  router.delete(`/admin/vendors/${vendor.id}`, { preserveScroll: true })
}

function deleteContract(): void {
  const contract = removingContract.value

  if (contract === null) return

  removingContract.value = null
  router.delete(`/admin/vendors/contracts/${contract.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t('vendors.title')" />

  <AdminLayout :heading="t('ui.nav.vendors')" :description="t('vendors.intro')">
    <template v-if="can.manage" #actions>
      <AppButton variant="primary" icon="add" @click="addingVendor = !addingVendor">
        {{ t('vendors.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection v-if="addingVendor && can.manage" :title="t('vendors.add')">
        <form class="flex max-w-xl flex-col gap-4" @submit.prevent="saveVendor">
          <AppInput
            v-model="vendorForm.name"
            :label="t('vendors.name')"
            :error="vendorForm.errors.name"
          />
          <AppSelect v-model="vendorForm.kind" :label="t('vendors.kind')" :options="kinds" />
          <AppInput v-model="vendorForm.contact_name" :label="t('vendors.contact_name')" />
          <AppInput
            v-model="vendorForm.contact_email"
            :label="t('vendors.contact_email')"
            :error="vendorForm.errors.contact_email"
          />
          <AppInput v-model="vendorForm.contact_phone" :label="t('vendors.contact_phone')" />
          <AppInput
            v-model="vendorForm.account_reference"
            :label="t('vendors.account_reference')"
            :hint="t('vendors.account_reference_hint')"
          />
          <AppTextarea v-model="vendorForm.note" :label="t('vendors.note')" :rows="2" />

          <div class="flex gap-2">
            <!-- Labelled with what pressing it does, not with the heading. -->
            <AppButton type="submit" variant="primary" :loading="vendorForm.processing">
              {{ t('vendors.add_submit') }}
            </AppButton>
            <AppButton variant="ghost" @click="addingVendor = false">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <DetailSection
        :title="t('vendors.contracts.title')"
        :description="t('vendors.contracts.intro')"
        :divided="false"
      >
        <EmptyState
          v-if="contracts.length === 0"
          variant="plain"
          icon="info"
          :title="t('vendors.contracts.empty')"
          :description="t('vendors.contracts.empty_detail')"
        />

        <AppTable v-else name="contracts" :columns="CONTRACT_COLUMNS">
          <AppTableRow v-for="row in contracts" :key="row.id">
            <td data-col="decide">
              <AppStatus :tone="tone(row)" :label="remaining(row)" />
              <!--
                No line at all for a rolling agreement. A dash under "No end
                date" reads as a date that failed to load, when the absence is
                the whole answer.
              -->
              <div v-if="row.decideBy" class="text-chrome text-content-muted mt-0.5">
                {{ when(row.decideBy) }}
              </div>
            </td>
            <td data-col="title">
              <span class="font-medium">{{ row.title }}</span>
              <!--
                Said on the row rather than left to the dates: a contract that
                renews itself is not a crisis and is still the last moment to
                leave, and one that does not is a service that stops.
              -->
              <AppBadge v-if="row.autoRenews" tone="info" class="ml-2">
                {{ t('vendors.contracts.renews') }}
              </AppBadge>
            </td>
            <td data-col="vendor" class="text-content-muted">{{ row.vendor }}</td>
            <td data-col="term" class="text-content-muted">{{ row.termLabel }}</td>
            <td data-col="amount" class="numeric">{{ row.amount }}</td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions">
                <AppButton size="sm" variant="danger-subtle" @click="removingContract = row">
                  {{ t('vendors.delete') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <DetailSection :title="t('vendors.title')" :divided="false">
        <EmptyState
          v-if="vendors.length === 0"
          icon="info"
          :title="t('vendors.empty')"
          :description="t('vendors.empty_detail')"
        />

        <AppTable v-else name="vendors" :columns="VENDOR_COLUMNS">
          <AppTableRow v-for="vendor in vendors" :key="vendor.id">
            <td data-col="name">
              <span class="font-medium">{{ vendor.name }}</span>
            </td>
            <td data-col="kind" class="text-content-muted">{{ vendor.kindLabel }}</td>
            <td data-col="contact" class="text-content-muted">
              {{ vendor.contactName ?? '—' }}
              <!--
                The telephone number beside the name: the moment it is wanted
                is a Sunday, and a second click to find it is a second click
                somebody does not have.
              -->
              <span v-if="vendor.contactPhone" class="font-mono">· {{ vendor.contactPhone }}</span>
            </td>
            <td data-col="reference" class="text-content-muted font-mono">
              {{ vendor.reference ?? '—' }}
            </td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions flex justify-end gap-2">
                <AppButton size="sm" variant="ghost" @click="addingContractFor = vendor">
                  {{ t('vendors.add_contract') }}
                </AppButton>
                <AppButton size="sm" variant="danger-subtle" @click="removingVendor = vendor">
                  {{ t('vendors.delete') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <!-- Named, so the form says which vendor it is about. -->
      <DetailSection
        v-if="addingContractFor && can.manage"
        :title="t('vendors.add_contract')"
        :description="addingContractFor.name"
      >
        <form class="flex max-w-xl flex-col gap-4" @submit.prevent="saveContract">
          <AppInput
            v-model="contractForm.title"
            :label="t('vendors.contracts.contract_title')"
            :error="contractForm.errors.title"
          />
          <AppInput v-model="contractForm.reference" :label="t('vendors.contracts.reference')" />
          <AppSelect
            v-model="contractForm.term"
            :label="t('vendors.contracts.term')"
            :options="terms"
          />
          <!--
            The currency is its own field and it comes **first**, because it
            is the unit the figure under it is in. A seller invoicing in euros
            still pays a transit provider in dollars, so it is typed rather
            than chosen from what this installation sells in.

            `MoneyInput` takes an exponent rather than a code: how many minor
            units a currency has is a fact about the currency and not a thing
            a control can guess.
          -->
          <AppInput
            v-model="contractForm.currency_code"
            :label="t('vendors.contracts.currency')"
            :error="contractForm.errors.currency_code"
          />
          <MoneyInput
            v-model="contractForm.amount_minor"
            :label="t('vendors.contracts.amount')"
            :exponent="2"
            :error="contractForm.errors.amount_minor"
          />
          <AppInput
            v-model="contractForm.starts_on"
            type="date"
            :label="t('vendors.contracts.starts_on')"
          />
          <AppInput
            v-model="contractForm.ends_on"
            type="date"
            :label="t('vendors.contracts.ends_on')"
            :hint="t('vendors.contracts.ends_on_hint')"
            :error="contractForm.errors.ends_on"
          />
          <AppCheckbox
            v-model="contractForm.auto_renews"
            :label="t('vendors.contracts.auto_renews')"
            :description="t('vendors.contracts.auto_renews_hint')"
          />
          <AppInput
            v-model="contractForm.notice_days"
            type="number"
            :label="t('vendors.contracts.notice_days')"
            :hint="t('vendors.contracts.notice_days_hint')"
            :error="contractForm.errors.notice_days"
          />
          <AppTextarea v-model="contractForm.note" :label="t('vendors.note')" :rows="2" />

          <div class="flex gap-2">
            <AppButton type="submit" variant="primary" :loading="contractForm.processing">
              {{ t('vendors.contracts.add_submit') }}
            </AppButton>
            <!--
              A form that can only be closed by submitting it is a form
              somebody opened by mistake and is now stuck with.
            -->
            <AppButton variant="ghost" @click="addingContractFor = null">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>
    </div>

    <AppConfirm
      :open="removingVendor !== null"
      level="consequential"
      :title="t('vendors.confirm.vendor_title')"
      :description="t('vendors.confirm.vendor_body', { name: removingVendor?.name ?? '' })"
      :confirm-label="t('vendors.delete')"
      @close="removingVendor = null"
      @confirm="deleteVendor"
    />

    <AppConfirm
      :open="removingContract !== null"
      level="consequential"
      :title="t('vendors.confirm.contract_title')"
      :description="t('vendors.confirm.contract_body', { name: removingContract?.title ?? '' })"
      :confirm-label="t('vendors.delete')"
      @close="removingContract = null"
      @confirm="deleteContract"
    />
  </AdminLayout>
</template>
