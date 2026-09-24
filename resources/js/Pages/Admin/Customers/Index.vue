<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, nextTick, reactive, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import FilterBar from '../../../Components/FilterBar.vue'
import FilterSelect from '../../../Components/FilterSelect.vue'
import SearchInput from '../../../Components/SearchInput.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { usePermissions } from '../../../composables/usePermissions'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

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
  customers: {
    data: CustomerRow[]
    current_page: number
    last_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
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
}>()

const { can } = usePermissions()
const { t } = useTranslations()

function initial(key: string): string {
  const value = props.filters[key]

  return typeof value === 'string' ? value : ''
}

/**
 * The advanced panel opens by itself when the current search used it —
 * otherwise an operator who bookmarked a postcode search would see a plain
 * box and a result set they cannot explain.
 *
 * Phone moved in here with the filter bar: the bar keeps the two lookups
 * made all day (a name, an address), and a phone number is the tenth case.
 */
const advancedKeys = [
  ...props.schema.text.filter((key) => key !== 'email'),
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

/**
 * A filter select applies itself. Waiting for a Search press after picking
 * "Suspended" is a second action for a decision already made. `nextTick`
 * because `v-model` writes the value in the same event this runs in.
 */
function applySoon(): void {
  void nextTick(submit)
}

/** The advanced criteria in use — the count on the "More filters" button. */
const advancedInUse = computed(
  () =>
    advancedKeys.filter((key) => (form as unknown as Record<string, string>)[key] !== '').length +
    (selectedPermissions.value.length > 0 ? 1 : 0) +
    customValues.filter((field) => field.value !== '').length,
)

const filtered = computed(
  () =>
    form.search !== '' ||
    form.email !== '' ||
    form.status !== '' ||
    form.tag !== '' ||
    advancedInUse.value > 0,
)

/**
 * The columns, in the order an operator scans: who, their state, how to
 * reach them, what they have. First and last name are kept for anybody who
 * sorts a mail merge by surname, but off by default — they repeat the Client
 * column, and a table that says one thing three times says less.
 */
const columns = computed<TableColumn[]>(() => [
  { key: 'name', label: props.labels.list.name ?? 'Client', sticky: true },
  { key: 'status', label: props.labels.list.status ?? 'Status' },
  { key: 'email', label: props.labels.list.email ?? 'Email address', optional: true },
  { key: 'company', label: props.labels.list.company ?? 'Company', optional: true },
  { key: 'services', label: props.labels.list.services ?? 'Services', numeric: true },
  {
    key: 'created',
    label: props.labels.list.created_at ?? 'Created',
    optional: true,
    hideBelow: 'lg',
  },
  { key: 'id', label: props.labels.list.id ?? 'ID', optional: true, hideBelow: 'xl' },
  {
    key: 'first',
    label: props.labels.list.first_name ?? 'First name',
    optional: true,
    offByDefault: true,
  },
  {
    key: 'last',
    label: props.labels.list.last_name ?? 'Last name',
    optional: true,
    offByDefault: true,
  },
])

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('ui.clients.title')" />

  <AdminLayout :heading="t('ui.clients.title')">
    <template #meta>
      <span class="tabular-nums">
        {{
          t(customers.total === 1 ? 'ui.clients.count_one' : 'ui.clients.count_other', {
            count: customers.total,
          })
        }}
      </span>
      <template v-if="!includeInactive">
        <span aria-hidden="true">·</span>
        <span>{{ t('ui.clients.closed_hidden') }}</span>
      </template>
    </template>

    <template v-if="can('crm.customers.manage')" #actions>
      <AppButton href="/admin/clients/create" variant="primary" icon="add">
        {{ t('ui.clients.add') }}
      </AppButton>
    </template>

    <!--
      One row of filters, not a search page. The two lookups an operator
      makes all day — a name, an address — are boxes; status and group apply
      themselves; everything else is behind More filters, which opens by
      itself when the current search used it.
    -->
    <form
      class="mb-4"
      role="search"
      :aria-label="t('ui.clients.filter_label')"
      @submit.prevent="submit"
    >
      <FilterBar :expanded="advanced">
        <SearchInput
          v-model="form.search"
          :label="labels.name ?? 'Name or company'"
          :placeholder="t('ui.clients.search_placeholder')"
        />
        <SearchInput
          v-model="form.email"
          :label="labels.email ?? 'Email address'"
          :placeholder="t('ui.clients.email_placeholder')"
          width="w-56"
        />
        <FilterSelect
          v-model="form.status"
          :label="labels.status ?? 'Status'"
          :options="withBlank(statuses)"
          @update:model-value="applySoon"
        />
        <FilterSelect
          v-model="form.tag"
          :label="labels.tag ?? 'Group'"
          :options="withBlank(tags)"
          @update:model-value="applySoon"
        />

        <AppButton
          variant="ghost"
          icon="filter"
          :aria-expanded="advanced"
          aria-controls="client-filters-more"
          @click="advanced = !advanced"
        >
          {{ t('ui.common.more_filters') }}
          <span v-if="advancedInUse > 0" class="text-brand tabular-nums">{{ advancedInUse }}</span>
        </AppButton>

        <AppButton type="submit">{{ labels.apply ?? 'Search' }}</AppButton>
        <AppButton v-if="filtered" variant="ghost" @click="clear">
          {{ labels.clear ?? 'Clear' }}
        </AppButton>

        <template #end>
          <!-- A default, not a filter: nothing has to be un-set to get back
               to the normal view. -->
          <AppCheckbox
            :model-value="includeInactive"
            :label="labels.show_inactive ?? 'Include closed accounts'"
            @update:model-value="toggleInactive"
          />
        </template>

        <!-- Everything an operator has ever had to look somebody up by: a
             postcode on a returned letter, four digits on a chargeback
             notice. Hidden until asked for, because it is the tenth case. -->
        <template #more>
          <div id="client-filters-more" class="flex flex-col gap-5">
            <p class="text-content-muted text-chrome">
              {{ labels.wildcard ?? 'A full name works. % anchors: Zeyn% or %nep.' }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <AppInput v-model="form.phone" :label="labels.phone ?? 'Phone'" />
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
              <AppSelect
                v-model="form.currency"
                :label="labels.currency ?? 'Currency'"
                :options="withBlank(currencies)"
              />
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <AppInput v-model="form.gateway" :label="labels.gateway ?? 'Payment method'" />
              <AppInput v-model="form.card_brand" :label="labels.card_brand ?? 'Card type'" />
              <AppInput
                v-model="form.card_last_four"
                :label="labels.card_last_four ?? 'Last four'"
              />
              <AppSelect
                v-model="form.has_card"
                :label="labels.has_card ?? 'Has a card'"
                :options="triState"
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

            <fieldset>
              <legend class="text-body font-medium">
                {{ labels.permissions_title ?? 'Has a contact allowed to' }}
              </legend>
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
            </fieldset>

            <!-- Anything local lives here rather than as a column: a national
                 identity number, a tax office, a second phone. Every custom
                 field this installation defined is searchable without anybody
                 editing this screen. -->
            <fieldset v-if="customValues.length > 0">
              <legend class="text-body font-medium">
                {{ labels.custom_fields ?? 'Custom fields' }}
              </legend>
              <p class="text-content-muted text-chrome mt-0.5">{{ labels.custom_fields_hint }}</p>

              <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
            </fieldset>

            <div class="flex gap-2">
              <AppButton type="submit" variant="primary">{{ labels.apply ?? 'Search' }}</AppButton>
              <AppButton variant="ghost" @click="clear">{{ labels.clear ?? 'Clear' }}</AppButton>
            </div>
          </div>
        </template>
      </FilterBar>
    </form>

    <AppTable
      v-if="customers.data.length > 0"
      name="admin.customers"
      :columns="columns"
      noun="client"
    >
      <tr v-for="customer in customers.data" :key="customer.id">
        <td data-col="name" class="max-w-[22rem]">
          <Link
            :href="`/admin/customers/${customer.id}`"
            class="block truncate font-medium underline-offset-4 hover:underline"
          >
            {{ customer.name }}
          </Link>
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(customer.status)" :label="customer.statusLabel" />
        </td>
        <td data-col="email" class="text-content-muted">{{ customer.email ?? '—' }}</td>
        <td data-col="company" class="text-content-muted">{{ customer.company ?? '—' }}</td>
        <td data-col="services" class="numeric">
          {{ customer.activeServices }}
          <!-- Active, with anything not active in brackets: the shape an
               operator already reads at a glance. -->
          <span v-if="customer.inactiveServices > 0" class="text-content-subtle">
            ({{ customer.inactiveServices }})
          </span>
        </td>
        <td data-col="created" class="text-content-muted whitespace-nowrap tabular-nums">
          {{ formatDate(customer.createdAt) }}
        </td>
        <td data-col="id">
          <AppCopy
            :value="customer.id"
            :label="customer.id.slice(-8)"
            :noun="t('ui.clients.client_id')"
            mono
          />
        </td>
        <td data-col="first">{{ customer.firstName ?? '—' }}</td>
        <td data-col="last">{{ customer.lastName ?? '—' }}</td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      icon="clients"
      :title="filtered ? t('ui.clients.empty_filtered') : t('ui.clients.empty')"
      :description="filtered ? t('ui.clients.empty_filtered_detail') : t('ui.clients.empty_detail')"
    >
      <AppButton v-if="filtered" size="sm" @click="clear">
        {{ t('ui.common.clear_filters') }}
      </AppButton>
    </EmptyState>

    <AppPagination :links="customers.links" :total="customers.total" />
  </AdminLayout>
</template>
