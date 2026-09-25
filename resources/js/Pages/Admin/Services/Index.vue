<script setup lang="ts">
/**
 * Products and services, the shape a WHMCS operator knows.
 *
 * Three things they reach for, in the order they reach for them: the
 * product type across the top to drill into, the filter panel that folds
 * away because most of the time a name is enough, and a row that opens in
 * place rather than costing a page load to answer "which server is this
 * on".
 *
 * Closed accounts are hidden until asked for. Their services are a record
 * the accounts department keeps, and after a few years they are most of
 * the table.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStat from '../../../Components/AppStat.vue'
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

interface ServiceDetail {
  orderNumber: string | null
  orderId: string | null
  server: string | null
  paymentMethod: string | null
  registeredOn: string | null
  dedicatedIp: string | null
  username: string | null
  promotionCode: string | null
  product: string | null
  productType: string | null
}

interface ServiceRow {
  id: string
  name: string
  status: string
  statusLabel: string
  customer: string | null
  customerId: string | null
  domain: string | null
  server: string | null
  recurring: string
  billingCycle: string | null
  billingCycleLabel: string | null
  nextDueOn: string | null
  createdAt: string | null
  detail: ServiceDetail
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
  services: {
    data: ServiceRow[]
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
  types: { value: string; label: string; total: number }[]
  counts: { pending: number; failed: number; suspended: number }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'expand', label: '' },
  { key: 'id', label: t('provisioning.services.id'), optional: true },
  { key: 'name', label: t('provisioning.services.name'), sticky: true },
  { key: 'domain', label: t('provisioning.services.domain') },
  { key: 'client', label: t('provisioning.services.customer') },
  { key: 'price', label: t('provisioning.services.price'), numeric: true },
  { key: 'cycle', label: t('provisioning.services.billing_cycle'), optional: true },
  { key: 'due', label: t('provisioning.services.next_due') },
  { key: 'status', label: t('provisioning.services.status') },
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

// Open when the operator arrived with something in it, so a bookmarked
// search does not look like an empty list.
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
  router.get('/admin/services', query(), { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
  apply()
}

function toggleInactive(): void {
  includeInactive.value = !includeInactive.value
  apply()
}

function pickType(value: string): void {
  form.value.product_type = form.value.product_type === value ? '' : value
  apply()
}

function filterByStatus(status: string): void {
  form.value = { ...EMPTY, status }
  apply()
}

function toggleRow(id: string): void {
  expanded.value = expanded.value === id ? null : id
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(options: Option[], label = t('provisioning.services.any')): Option[] {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head :title="t('ui.nav.products_services')" />

  <!--
    The heading is the nav map's own words on purpose: the breadcrumb drops
    its last crumb when the two match, and "Clients › Products/Services ›
    Services" is a trail that names the same screen twice.
  -->
  <AdminLayout
    :heading="t('ui.nav.products_services')"
    :description="t('provisioning.services.subtitle')"
  >
    <!--
      Three counts, because they are the three questions an operator opens
      this screen to answer: what is stuck, what broke, what is off.
    -->
    <div class="mb-7 flex flex-wrap gap-3">
      <AppStat
        :label="t('provisioning.services.pending_queue')"
        :value="counts.pending"
        :active="form.status === 'pending'"
        @select="filterByStatus('pending')"
      />
      <AppStat
        :label="t('provisioning.services.failed_queue')"
        :value="counts.failed"
        :tone="counts.failed > 0 ? 'danger' : 'neutral'"
        :active="form.status === 'failed'"
        @select="filterByStatus('failed')"
      />
      <AppStat
        :label="t('provisioning.services.suspended_queue')"
        :value="counts.suspended"
        :tone="counts.suspended > 0 ? 'warning' : 'neutral'"
        :active="form.status === 'suspended'"
        @select="filterByStatus('suspended')"
      />
    </div>

    <!--
      The product types that actually have something running, built from
      the rows: a drill-down that offers a type nobody sells is a
      drill-down into an empty page.
    -->
    <div v-if="types.length > 0" class="mb-5 flex flex-wrap gap-1.5">
      <button
        v-for="type in types"
        :key="type.value"
        type="button"
        class="pressable text-chrome inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 transition-colors duration-(--duration-fast)"
        :class="
          form.product_type === type.value
            ? 'bg-surface-secondary text-content font-medium'
            : 'text-content-muted hover:bg-surface-secondary'
        "
        @click="pickType(type.value)"
      >
        <span aria-hidden="true">›</span>
        {{ type.label }}
        <span class="text-content-subtle tabular-nums">{{ type.total }}</span>
      </button>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton :aria-expanded="open" @click="open = !open">
        {{
          open ? t('provisioning.services.hide_search') : t('provisioning.services.search_filter')
        }}
        <span
          v-if="hasFilters"
          class="bg-brand text-content-inverse text-label -mr-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 tabular-nums"
        >
          {{ activeFilterCount }}
        </span>
      </AppButton>

      <!-- A switch rather than a checkbox: it changes what the list is,
           not what a form will submit. -->
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
        {{ t('provisioning.services.hide_inactive') }}
      </button>

      <span v-if="hasFilters" class="text-content-muted text-chrome">
        {{ t('provisioning.services.matches', { count: services.total }) }}
      </span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <AppSelect
          v-model="form.product_type"
          :label="t('provisioning.services.product_type')"
          :options="withBlank(schema.productTypes)"
        />
        <AppSelect
          v-model="form.server"
          :label="t('provisioning.services.server')"
          :options="withBlank(schema.servers)"
        />
        <AppSelect
          v-model="form.product"
          :label="t('provisioning.services.name')"
          :options="withBlank(schema.products)"
        />
        <AppSelect
          v-model="form.gateway"
          :label="t('provisioning.services.payment_method')"
          :hint="t('provisioning.services.payment_method_hint')"
          :options="withBlank(schema.gateways)"
        />
        <AppSelect
          v-model="form.billing_cycle"
          :label="t('provisioning.services.billing_cycle')"
          :options="withBlank(schema.billingCycles)"
        />
        <AppSelect
          v-model="form.status"
          :label="t('provisioning.services.status')"
          :options="withBlank(schema.statuses)"
        />
        <AppInput
          v-model="form.domain"
          :label="t('provisioning.services.domain')"
          :hint="t('provisioning.services.domain_hint')"
        />
        <AppInput
          v-model="form.client"
          :label="t('provisioning.services.customer')"
          :hint="t('provisioning.services.client_hint')"
        />
        <div class="grid gap-4 sm:grid-cols-2 lg:col-span-1">
          <AppSelect
            v-model="form.custom_field"
            :label="t('provisioning.services.custom_field')"
            :options="withBlank(schema.customFields)"
          />
          <AppInput v-model="form.custom_value" :label="t('provisioning.services.custom_value')" />
        </div>
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">{{
          t('provisioning.services.search')
        }}</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">
          {{ t('provisioning.services.clear') }}
        </AppButton>
      </div>
    </form>

    <AppTable v-if="services.data.length > 0" name="admin-services" :columns="COLUMNS">
      <template v-for="service in services.data" :key="service.id">
        <AppTableRow>
          <td data-col="expand">
            <button
              type="button"
              class="pressable border-line text-content-muted hover:text-content text-chrome inline-flex size-5 items-center justify-center rounded-sm border font-mono leading-none"
              :aria-expanded="expanded === service.id"
              :aria-label="
                expanded === service.id
                  ? t('provisioning.services.hide_detail')
                  : t('provisioning.services.show_detail')
              "
              @click="toggleRow(service.id)"
            >
              {{ expanded === service.id ? '−' : '+' }}
            </button>
          </td>
          <td data-col="id" class="text-content-subtle text-chrome font-mono">
            {{ service.id.slice(-8) }}
          </td>
          <td data-col="name">
            <Link
              :href="`/admin/services/${service.id}`"
              class="font-medium underline-offset-4 hover:underline"
            >
              {{ service.name }}
            </Link>
          </td>
          <td data-col="domain" class="text-content-muted">{{ service.domain ?? '—' }}</td>
          <td data-col="client">
            <Link
              v-if="service.customerId"
              :href="`/admin/customers/${service.customerId}`"
              class="underline-offset-4 hover:underline"
            >
              {{ service.customer ?? '—' }}
            </Link>
            <span v-else>—</span>
          </td>
          <td data-col="price" class="numeric tabular-nums">{{ service.recurring }}</td>
          <td data-col="cycle" class="text-content-muted whitespace-nowrap">
            {{ service.billingCycleLabel ?? t('provisioning.services.one_time') }}
          </td>
          <td data-col="due" class="text-content-muted whitespace-nowrap">
            {{ formatDate(service.nextDueOn) }}
          </td>
          <td data-col="status">
            <AppStatus :tone="statusTone(service.status)" :label="service.statusLabel" />
          </td>
        </AppTableRow>

        <tr v-if="expanded === service.id" class="bg-surface-secondary">
          <td :colspan="COLUMNS.length" class="px-4 py-4">
            <dl class="text-chrome grid gap-x-8 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.order_number') }}</dt>
                <dd class="mt-0.5">
                  <Link
                    v-if="service.detail.orderId"
                    :href="`/admin/orders/${service.detail.orderId}`"
                    class="underline-offset-4 hover:underline"
                  >
                    {{ service.detail.orderNumber ?? '—' }}
                  </Link>
                  <span v-else>—</span>
                </dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.server') }}</dt>
                <dd class="mt-0.5">{{ service.detail.server ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.payment_method') }}</dt>
                <dd class="mt-0.5">{{ service.detail.paymentMethod ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.registered_on') }}</dt>
                <dd class="mt-0.5">{{ formatDate(service.detail.registeredOn) }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.dedicated_ip') }}</dt>
                <dd class="mt-0.5 font-mono">{{ service.detail.dedicatedIp ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.username') }}</dt>
                <dd class="mt-0.5 font-mono">{{ service.detail.username ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.promotion_code') }}</dt>
                <dd class="mt-0.5 font-mono">{{ service.detail.promotionCode ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('provisioning.services.product') }}</dt>
                <dd class="mt-0.5">
                  {{ service.detail.product ?? '—' }}
                  <span v-if="service.detail.productType" class="text-content-muted">
                    ({{ service.detail.productType }})
                  </span>
                </dd>
              </div>
            </dl>
          </td>
        </tr>
      </template>
    </AppTable>

    <EmptyState
      v-else-if="hasFilters"
      icon="services"
      :title="t('provisioning.services.no_match')"
      :description="t('provisioning.services.no_match_description')"
    />

    <EmptyState
      v-else
      icon="services"
      :title="t('provisioning.services.empty')"
      :description="t('provisioning.services.empty_description')"
    />

    <AppPagination :links="services.links" :total="services.total" />
  </AdminLayout>
</template>
