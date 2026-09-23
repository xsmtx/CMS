<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface Option {
  value: string
  label: string
}

interface CustomFieldOption {
  key: string
  label: string
  type: string
  options: string[]
}

interface CustomerRow {
  id: string
  firstName: string | null
  lastName: string | null
  company: string | null
  name: string
  email: string | null
  activeServices: number
  inactiveServices: number
  status: string
  statusLabel: string
  tags: string[]
  createdAt: string | null
}

const props = defineProps<{
  customers: { data: CustomerRow[]; current_page: number; last_page: number; total: number }
  filters: Record<string, unknown>
  statuses: Option[]
  currencies: Option[]
  countries: Option[]
  tags: Option[]
  permissions: Option[]
  schema: { text: string[]; exact: string[]; customFields: CustomFieldOption[] }
  labels: Record<string, string> & {
    list: Record<string, string>
    permissions: Record<string, string>
  }
  can: { create: boolean }
}>()

function initial(key: string): string {
  const value = props.filters[key]

  return typeof value === 'string' ? value : ''
}

/**
 * The advanced panel opens by itself when the current search used it —
 * otherwise an operator who bookmarked a postcode search would see a plain
 * box and a result set they cannot explain.
 */
const advancedKeys = [
  ...props.schema.text.filter((key) => key !== 'email' && key !== 'phone'),
  ...props.schema.exact.filter((key) => key !== 'status'),
  'signed_up_from',
  'signed_up_to',
  'marketing_opt_in',
  'corporate',
  'tax_id_validated',
  'has_card',
]

const usedAdvanced =
  advancedKeys.some((key) => initial(key) !== '') ||
  (Array.isArray(props.filters.permissions) && props.filters.permissions.length > 0) ||
  Object.values((props.filters.custom ?? {}) as Record<string, string>).some(
    (value) => value !== '',
  )

const advanced = ref(usedAdvanced)

interface Criteria {
  search: string
  email: string
  phone: string
  tag: string
  status: string
  address_one: string
  address_two: string
  city: string
  region: string
  postcode: string
  country: string
  gateway: string
  card_brand: string
  card_last_four: string
  currency: string
  locale: string
  tax_id: string
  signed_up_from: string
  signed_up_to: string
  marketing_opt_in: string
  corporate: string
  tax_id_validated: string
  has_card: string
}

const form = reactive<Criteria>({
  search: initial('search'),
  email: initial('email'),
  phone: initial('phone'),
  tag: initial('tag'),
  status: initial('status'),
  address_one: initial('address_one'),
  address_two: initial('address_two'),
  city: initial('city'),
  region: initial('region'),
  postcode: initial('postcode'),
  country: initial('country'),
  gateway: initial('gateway'),
  card_brand: initial('card_brand'),
  card_last_four: initial('card_last_four'),
  currency: initial('currency'),
  locale: initial('locale'),
  tax_id: initial('tax_id'),
  signed_up_from: initial('signed_up_from'),
  signed_up_to: initial('signed_up_to'),
  marketing_opt_in: initial('marketing_opt_in'),
  corporate: initial('corporate'),
  tax_id_validated: initial('tax_id_validated'),
  has_card: initial('has_card'),
})

const selectedPermissions = ref<string[]>(
  Array.isArray(props.filters.permissions) ? (props.filters.permissions as string[]) : [],
)

// A list rather than a keyed object: `v-model` on an index signature is
// `string | undefined`, and a form field that might be undefined is a form
// field that will be one day.
const customValues = reactive(
  props.schema.customFields.map((field) => ({
    ...field,
    value: ((props.filters.custom ?? {}) as Record<string, string>)[field.key] ?? '',
  })),
)

const includeInactive = ref(props.filters.inactive === true)

const triState: Option[] = [
  { value: '', label: props.labels.any ?? 'Any' },
  { value: '1', label: props.labels.yes ?? 'Yes' },
  { value: '0', label: props.labels.no ?? 'No' },
]

function withBlank(options: Option[]): Option[] {
  return [{ value: '', label: props.labels.any ?? 'Any' }, ...options]
}

function submit(): void {
  const query: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(form)) {
    if (value !== '') query[key] = value
  }

  for (const field of customValues) {
    if (field.value !== '') query[`custom[${field.key}]`] = field.value
  }

  if (selectedPermissions.value.length > 0) query.permissions = selectedPermissions.value
  if (includeInactive.value) query.inactive = '1'

  router.get('/admin/customers', query, { preserveState: true, replace: true })
}

function clear(): void {
  for (const key of Object.keys(form) as (keyof Criteria)[]) form[key] = ''
  for (const field of customValues) field.value = ''

  selectedPermissions.value = []
  includeInactive.value = false

  router.get('/admin/customers', {}, { replace: true })
}

function togglePermission(slug: string, checked: boolean): void {
  selectedPermissions.value = checked
    ? [...selectedPermissions.value, slug]
    : selectedPermissions.value.filter((value) => value !== slug)
}

function toggleInactive(value: boolean): void {
  includeInactive.value = value
  submit()
}

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  if (status === 'suspended') return 'warning'
  if (status === 'closed') return 'danger'

  return 'neutral'
}

// Built here rather than inline, so the server's labels are the single
// source and a missing one degrades to a readable English word instead of
// an empty column heading.
const headers = [
  ['id', 'ID'],
  ['first_name', 'First name'],
  ['last_name', 'Last name'],
  ['company', 'Company'],
  ['name', 'Name'],
  ['email', 'Email address'],
  ['services', 'Services'],
  ['created_at', 'Created'],
  ['status', 'Status'],
].map(([key, fallback]) => props.labels.list[key as string] ?? (fallback as string))

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head title="Clients" />

  <AdminLayout heading="Clients">
    <form class="mb-6" @submit.prevent="submit">
      <!-- The plain box, because nine times out of ten a name is enough. -->
      <div class="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
        <AppInput
          v-model="form.search"
          :label="labels.name ?? 'Name'"
          :hint="labels.wildcard ?? 'A full name works. % anchors: Zeyn% or %nep.'"
        />
        <AppInput v-model="form.email" :label="labels.email ?? 'Email'" />
        <AppInput v-model="form.phone" :label="labels.phone ?? 'Phone'" />
        <div class="flex gap-2">
          <AppButton type="submit" variant="primary">{{ labels.apply ?? 'Search' }}</AppButton>
          <AppButton type="button" variant="ghost" @click="clear">
            {{ labels.clear ?? 'Clear' }}
          </AppButton>
        </div>
      </div>

      <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <AppSelect v-model="form.tag" :label="labels.tag ?? 'Group'" :options="withBlank(tags)" />
        <AppSelect
          v-model="form.status"
          :label="labels.status ?? 'Status'"
          :options="withBlank(statuses)"
        />

        <button
          type="button"
          class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] px-2 py-2 text-sm"
          :aria-expanded="advanced"
          @click="advanced = !advanced"
        >
          {{ advanced ? '−' : '+' }} {{ labels.advanced ?? 'Advanced' }}
        </button>
      </div>

      <!-- Everything an operator has ever had to look somebody up by: a
           postcode on a returned letter, four digits on a chargeback
           notice. Hidden until asked for, because it is the tenth case. -->
      <div v-if="advanced" class="border-line mt-4 rounded-[var(--radius-lg)] border p-4">
        <div class="grid gap-4 sm:grid-cols-3">
          <AppInput v-model="form.address_one" :label="labels.address_one ?? 'Address 1'" />
          <AppInput v-model="form.address_two" :label="labels.address_two ?? 'Address 2'" />
          <AppInput v-model="form.city" :label="labels.city ?? 'City'" />
          <AppInput v-model="form.region" :label="labels.region ?? 'Region'" />
          <AppInput v-model="form.postcode" :label="labels.postcode ?? 'Postcode'" />
          <AppSelect
            v-model="form.country"
            :label="labels.country ?? 'Country'"
            :options="withBlank(countries)"
          />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-4">
          <AppInput v-model="form.gateway" :label="labels.gateway ?? 'Payment method'" />
          <AppInput v-model="form.card_brand" :label="labels.card_brand ?? 'Card type'" />
          <AppInput v-model="form.card_last_four" :label="labels.card_last_four ?? 'Last four'" />
          <AppSelect
            v-model="form.has_card"
            :label="labels.has_card ?? 'Has a card'"
            :options="triState"
          />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-4">
          <AppSelect
            v-model="form.currency"
            :label="labels.currency ?? 'Currency'"
            :options="withBlank(currencies)"
          />
          <AppInput v-model="form.locale" :label="labels.locale ?? 'Language'" />
          <AppInput
            v-model="form.signed_up_from"
            type="date"
            :label="labels.signed_up_from ?? 'Signed up after'"
          />
          <AppInput
            v-model="form.signed_up_to"
            type="date"
            :label="labels.signed_up_to ?? 'Signed up before'"
          />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-4">
          <AppInput v-model="form.tax_id" :label="labels.tax_id ?? 'Tax number'" />
          <AppSelect
            v-model="form.tax_id_validated"
            :label="labels.tax_id_validated ?? 'Tax number validated'"
            :options="triState"
          />
          <AppSelect
            v-model="form.corporate"
            :label="labels.corporate ?? 'Business account'"
            :options="triState"
          />
          <AppSelect
            v-model="form.marketing_opt_in"
            :label="labels.marketing_opt_in ?? 'Marketing opt-in'"
            :options="triState"
          />
        </div>

        <div class="mt-5">
          <p class="text-sm font-medium">
            {{ labels.permissions_title ?? 'Has a contact allowed to' }}
          </p>

          <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
            <AppCheckbox
              v-for="permission in permissions"
              :key="permission.value"
              :model-value="selectedPermissions.includes(permission.value)"
              :label="permission.label"
              @update:model-value="
                (checked: boolean) => togglePermission(permission.value, checked)
              "
            />
          </div>
        </div>

        <!-- Anything local lives here rather than as a column: a national
             identity number, a tax office, a second phone. Every custom
             field this installation defined is searchable without anybody
             editing this screen. -->
        <div v-if="customValues.length > 0" class="mt-5">
          <p class="text-sm font-medium">{{ labels.custom_fields ?? 'Custom fields' }}</p>
          <p class="text-content-muted mt-1 text-xs leading-relaxed">
            {{ labels.custom_fields_hint }}
          </p>

          <div class="mt-3 grid gap-4 sm:grid-cols-3">
            <AppSelect
              v-for="field in customValues.filter((entry) => entry.type === 'select')"
              :key="field.key"
              v-model="field.value"
              :label="field.label"
              :options="
                withBlank(field.options.map((option) => ({ value: option, label: option })))
              "
            />
            <AppInput
              v-for="field in customValues.filter((entry) => entry.type !== 'select')"
              :key="field.key"
              v-model="field.value"
              :label="field.label"
            />
          </div>
        </div>
      </div>
    </form>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <!-- A default, not a filter: nothing has to be un-set to get back to
           the normal view. -->
      <AppCheckbox
        :model-value="includeInactive"
        :label="labels.show_inactive ?? 'Include closed accounts'"
        @update:model-value="toggleInactive"
      />

      <AppButton v-if="can.create" href="/admin/customers/create" variant="primary" size="sm">
        New client
      </AppButton>
    </div>

    <AppTable v-if="customers.data.length > 0" :headers="headers">
      <tr v-for="customer in customers.data" :key="customer.id">
        <td class="px-4 py-3">
          <Link
            :href="`/admin/customers/${customer.id}`"
            class="text-content-subtle font-mono text-xs underline-offset-4 hover:underline"
          >
            {{ customer.id.slice(-8) }}
          </Link>
        </td>
        <td class="px-4 py-3">{{ customer.firstName ?? '—' }}</td>
        <td class="px-4 py-3">{{ customer.lastName ?? '—' }}</td>
        <td class="text-content-muted px-4 py-3">{{ customer.company ?? '—' }}</td>
        <td class="px-4 py-3">
          <Link
            :href="`/admin/customers/${customer.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ customer.name }}
          </Link>
        </td>
        <td class="text-content-muted px-4 py-3">{{ customer.email ?? '—' }}</td>
        <td class="px-4 py-3 tabular-nums">
          {{ customer.activeServices }}
          <!-- Active, with anything not active in brackets: the shape an
               operator already reads at a glance. -->
          <span v-if="customer.inactiveServices > 0" class="text-content-muted">
            ({{ customer.inactiveServices }})
          </span>
        </td>
        <td class="text-content-muted px-4 py-3 whitespace-nowrap">
          {{ formatDate(customer.createdAt) }}
        </td>
        <td class="px-4 py-3">
          <AppBadge :tone="tone(customer.status)">{{ customer.statusLabel }}</AppBadge>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No clients match"
      description="Closed accounts are hidden unless you ask for them."
    />

    <p v-if="customers.last_page > 1" class="text-content-muted mt-4 text-xs">
      Page {{ customers.current_page }} of {{ customers.last_page }} — {{ customers.total }}
    </p>
  </AdminLayout>
</template>
