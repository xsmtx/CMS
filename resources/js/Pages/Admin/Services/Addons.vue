<script setup lang="ts">
/**
 * Service addons: the things customers pay for on top of what they bought.
 *
 * The same panel as the products and services screen, because it is the
 * same question asked one level down. An operator who has learnt one of
 * these two screens should not have to learn the other.
 *
 * Read-only. An addon is sold with a product, at checkout or by an operator
 * building an order; a screen that let somebody conjure a billable row out
 * of nothing would produce a charge with no order behind it.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface Option {
  value: string
  label: string
}

interface AddonDetail {
  orderNumber: string | null
  orderId: string | null
  domain: string | null
  server: string | null
  product: string | null
  paymentMethod: string | null
  registeredOn: string | null
  quantity: number
  setup: string
  catalogName: string | null
}

interface AddonRow {
  id: string
  name: string
  status: string
  statusLabel: string
  service: string | null
  serviceId: string | null
  customer: string | null
  customerId: string | null
  billingCycleLabel: string | null
  recurring: string
  nextDueOn: string | null
  detail: AddonDetail
}

interface Criteria {
  product_type: string
  server: string
  product: string
  gateway: string
  billing_cycle: string
  status: string
  domain: string
  client: string
  custom_field: string
  custom_value: string
}

const props = defineProps<{
  addons: {
    data: AddonRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: Partial<Criteria> & { inactive: boolean }
  schema: {
    productTypes: Option[]
    billingCycles: Option[]
    statuses: Option[]
    servers: Option[]
    products: Option[]
    gateways: Option[]
    customFields: Option[]
  }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'expand', label: '' },
  { key: 'id', label: t('provisioning.addons.id'), optional: true },
  { key: 'addon', label: t('provisioning.addons.addon'), sticky: true },
  { key: 'service', label: t('provisioning.addons.service') },
  { key: 'client', label: t('provisioning.addons.customer') },
  { key: 'cycle', label: t('provisioning.addons.billing_cycle'), optional: true },
  { key: 'price', label: t('provisioning.addons.price'), numeric: true },
  { key: 'due', label: t('provisioning.addons.next_due') },
  { key: 'status', label: t('provisioning.addons.status') },
]

const EMPTY: Criteria = {
  product_type: '',
  server: '',
  product: '',
  gateway: '',
  billing_cycle: '',
  status: '',
  domain: '',
  client: '',
  custom_field: '',
  custom_value: '',
}

const form = ref<Criteria>({ ...EMPTY, ...props.filters })
const includeInactive = ref(props.filters.inactive)
const open = ref(Object.entries(props.filters).some(([key, value]) => key !== 'inactive' && value))
const expanded = ref<string | null>(null)

const hasFilters = computed(() =>
  (Object.keys(EMPTY) as (keyof Criteria)[]).some((key) => form.value[key] !== ''),
)

const activeFilterCount = computed(
  () => (Object.keys(EMPTY) as (keyof Criteria)[]).filter((key) => form.value[key] !== '').length,
)

function query(): Record<string, string> {
  const params: Record<string, string> = {}

  for (const key of Object.keys(EMPTY) as (keyof Criteria)[]) {
    const value = form.value[key]

    if (value !== '') params[key] = value
  }

  if (includeInactive.value) params.inactive = '1'

  return params
}

function apply(): void {
  router.get('/admin/services/addons', query(), { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
  apply()
}

function toggleInactive(): void {
  includeInactive.value = !includeInactive.value
  apply()
}

function toggleRow(id: string): void {
  expanded.value = expanded.value === id ? null : id
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(options: Option[], label = 'Any'): Option[] {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head :title="t('provisioning.addons.title')" />

  <AdminLayout
    :heading="t('provisioning.addons.title')"
    :description="t('provisioning.addons.subtitle')"
  >
    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton :aria-expanded="open" @click="open = !open">
        {{ open ? t('provisioning.addons.hide_search') : t('provisioning.addons.search_filter') }}
        <span
          v-if="hasFilters"
          class="bg-brand text-content-inverse text-label -mr-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 tabular-nums"
        >
          {{ activeFilterCount }}
        </span>
      </AppButton>

      <button
        type="button"
        role="switch"
        :aria-checked="!includeInactive"
        class="pressable border-line bg-surface-primary text-content-muted hover:text-content hover:border-line-strong text-chrome inline-flex items-center gap-2.5 rounded-sm border px-3.5 py-2 transition-colors duration-(--duration-fast)"
        @click="toggleInactive"
      >
        <span
          class="inline-flex h-4 w-7 shrink-0 items-center rounded-full transition-colors duration-(--duration-fast)"
          :class="!includeInactive ? 'bg-brand' : 'bg-line-strong'"
        >
          <span
            class="bg-surface-primary size-3 rounded-full shadow-(--shadow-raised) transition-transform duration-(--duration-fast) ease-(--ease-out)"
            :class="!includeInactive ? 'translate-x-3.5' : 'translate-x-0.5'"
          />
        </span>
        {{ t('provisioning.addons.hide_inactive') }}
      </button>

      <span v-if="hasFilters" class="text-content-muted text-chrome">{{
        t('provisioning.addons.matches', { count: addons.total })
      }}</span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <AppSelect
          v-model="form.product_type"
          label="Product type"
          :options="withBlank(schema.productTypes)"
        />
        <AppSelect v-model="form.server" label="Server" :options="withBlank(schema.servers)" />
        <AppSelect
          v-model="form.product"
          label="Product / service"
          :options="withBlank(schema.products)"
        />
        <AppSelect
          v-model="form.gateway"
          label="Payment method"
          hint="The card on the client's file."
          :options="withBlank(schema.gateways)"
        />
        <AppSelect
          v-model="form.billing_cycle"
          label="Billing cycle"
          :options="withBlank(schema.billingCycles)"
        />
        <AppSelect v-model="form.status" label="Status" :options="withBlank(schema.statuses)" />
        <AppInput v-model="form.domain" label="Domain" hint="Hostname counts too." />
        <AppInput
          v-model="form.client"
          label="Client name"
          hint="A whole name works. % anchors: Zeyn% or %nep."
        />
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-1">
          <AppSelect
            v-model="form.custom_field"
            label="Custom field"
            :options="withBlank(schema.customFields)"
          />
          <AppInput v-model="form.custom_value" label="Custom field value" />
        </div>
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">Search</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">Clear</AppButton>
      </div>
    </form>

    <AppTable v-if="addons.data.length > 0" name="admin-addons" :columns="COLUMNS">
      <template v-for="addon in addons.data" :key="addon.id">
        <AppTableRow>
          <td data-col="expand">
            <button
              type="button"
              class="pressable border-line text-content-muted hover:text-content text-chrome inline-flex size-5 items-center justify-center rounded-sm border font-mono leading-none"
              :aria-expanded="expanded === addon.id"
              :aria-label="
                expanded === addon.id
                  ? t('provisioning.addons.hide_detail')
                  : t('provisioning.addons.show_detail')
              "
              @click="toggleRow(addon.id)"
            >
              {{ expanded === addon.id ? '−' : '+' }}
            </button>
          </td>
          <td data-col="id" class="text-content-subtle text-chrome font-mono">
            {{ addon.id.slice(-8) }}
          </td>
          <td data-col="addon" class="font-medium">{{ addon.name }}</td>
          <td data-col="service">
            <Link
              v-if="addon.serviceId"
              :href="`/admin/services/${addon.serviceId}`"
              class="underline-offset-4 hover:underline"
            >
              {{ addon.service ?? '—' }}
            </Link>
            <span v-else>—</span>
          </td>
          <td data-col="client">
            <Link
              v-if="addon.customerId"
              :href="`/admin/customers/${addon.customerId}`"
              class="underline-offset-4 hover:underline"
            >
              {{ addon.customer ?? '—' }}
            </Link>
            <span v-else>—</span>
          </td>
          <td data-col="cycle" class="text-content-muted whitespace-nowrap">
            {{ addon.billingCycleLabel ?? t('provisioning.addons.one_time') }}
          </td>
          <td data-col="price" class="numeric tabular-nums">{{ addon.recurring }}</td>
          <td data-col="due" class="text-content-muted whitespace-nowrap">
            {{ formatDate(addon.nextDueOn) }}
          </td>
          <td data-col="status">
            <AppStatus :tone="statusTone(addon.status)" :label="addon.statusLabel" />
          </td>
        </AppTableRow>

        <tr v-if="expanded === addon.id" class="bg-surface-secondary">
          <td :colspan="COLUMNS.length" class="px-4 py-4">
            <dl class="text-chrome grid gap-x-8 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.order_number') }}</dt>
                <dd class="mt-0.5">
                  <Link
                    v-if="addon.detail.orderId"
                    :href="`/admin/orders/${addon.detail.orderId}`"
                    class="underline-offset-4 hover:underline"
                  >
                    {{ addon.detail.orderNumber ?? '—' }}
                  </Link>
                  <span v-else>—</span>
                </dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.domain') }}</dt>
                <dd class="mt-0.5">{{ addon.detail.domain ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.server') }}</dt>
                <dd class="mt-0.5">{{ addon.detail.server ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.payment_method') }}</dt>
                <dd class="mt-0.5">{{ addon.detail.paymentMethod ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.registered_on') }}</dt>
                <dd class="mt-0.5">{{ formatDate(addon.detail.registeredOn) }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.quantity') }}</dt>
                <dd class="mt-0.5 tabular-nums">{{ addon.detail.quantity }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.setup_fee') }}</dt>
                <dd class="mt-0.5 tabular-nums">{{ addon.detail.setup }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.addons.catalog_name') }}</dt>
                <dd class="mt-0.5">
                  {{ addon.detail.catalogName ?? t('provisioning.addons.retired') }}
                </dd>
              </div>
            </dl>
          </td>
        </tr>
      </template>
    </AppTable>

    <EmptyState
      v-else-if="hasFilters"
      :title="t('provisioning.addons.no_match')"
      :description="t('provisioning.addons.no_match_description')"
    />

    <EmptyState
      v-else
      :title="t('provisioning.addons.empty')"
      :description="t('provisioning.addons.empty_description')"
    />

    <AppPagination :links="addons.links" :total="addons.total" />
  </AdminLayout>
</template>
