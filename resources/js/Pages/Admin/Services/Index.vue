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

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStat from '../../../Components/AppStat.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
  services: { data: ServiceRow[]; currentPage: number; lastPage: number; total: number }
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

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  if (status === 'failed' || status === 'terminated') return 'danger'
  if (status === 'suspended' || status === 'grace_period' || status === 'pending') return 'warning'
  return 'neutral'
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(options: Option[], label = 'Any'): Option[] {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head title="Products and services" />

  <AdminLayout heading="Products and services" description="Everything customers are running.">
    <!--
      Three counts, because they are the three questions an operator opens
      this screen to answer: what is stuck, what broke, what is off.
    -->
    <div class="mb-7 flex flex-wrap gap-3">
      <AppStat
        label="Pending setup"
        :value="counts.pending"
        :active="form.status === 'pending'"
        @select="filterByStatus('pending')"
      />
      <AppStat
        label="Failed"
        :value="counts.failed"
        :tone="counts.failed > 0 ? 'danger' : 'neutral'"
        :active="form.status === 'failed'"
        @select="filterByStatus('failed')"
      />
      <AppStat
        label="Suspended"
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
        class="pressable inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
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
        {{ open ? 'Hide search' : 'Search / filter' }}
        <span
          v-if="hasFilters"
          class="bg-brand text-content-inverse -mr-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] tabular-nums"
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
        class="pressable border-line bg-surface-primary text-content-muted hover:text-content hover:border-line-strong inline-flex items-center gap-2.5 rounded-[var(--radius-sm)] border px-3.5 py-2 text-xs transition-colors duration-(--duration-fast)"
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
        Hide inactive clients
      </button>

      <span v-if="hasFilters" class="text-content-muted text-xs"> {{ services.total }} match </span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-[var(--radius-lg)] border p-4 sm:grid-cols-2 lg:grid-cols-3"
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

    <AppTable
      v-if="services.data.length > 0"
      :headers="[
        '',
        'ID',
        'Product / service',
        'Domain',
        'Client name',
        'Price',
        'Billing cycle',
        'Next due date',
        'Status',
      ]"
    >
      <template v-for="service in services.data" :key="service.id">
        <tr>
          <td class="py-3.5 pl-5">
            <button
              type="button"
              class="pressable border-line text-content-muted hover:text-content inline-flex size-5 items-center justify-center rounded-[var(--radius-sm)] border font-mono text-xs leading-none"
              :aria-expanded="expanded === service.id"
              :aria-label="expanded === service.id ? 'Hide details' : 'Show details'"
              @click="toggleRow(service.id)"
            >
              {{ expanded === service.id ? '−' : '+' }}
            </button>
          </td>
          <td class="text-content-subtle px-4 py-2.5 font-mono text-xs">
            {{ service.id.slice(-8) }}
          </td>
          <td class="px-4 py-2.5">
            <Link
              :href="`/admin/services/${service.id}`"
              class="font-medium underline-offset-4 hover:underline"
            >
              {{ service.name }}
            </Link>
          </td>
          <td class="text-content-muted px-4 py-2.5">{{ service.domain ?? '—' }}</td>
          <td class="px-4 py-2.5">
            <Link
              v-if="service.customerId"
              :href="`/admin/customers/${service.customerId}`"
              class="underline-offset-4 hover:underline"
            >
              {{ service.customer ?? '—' }}
            </Link>
            <span v-else>—</span>
          </td>
          <td class="px-4 py-2.5 tabular-nums">{{ service.recurring }}</td>
          <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
            {{ service.billingCycleLabel ?? 'One time' }}
          </td>
          <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
            {{ formatDate(service.nextDueOn) }}
          </td>
          <td class="px-4 py-2.5">
            <AppBadge :tone="tone(service.status)">{{ service.statusLabel }}</AppBadge>
          </td>
        </tr>

        <tr v-if="expanded === service.id" class="bg-surface-secondary">
          <td colspan="9" class="px-4 py-4">
            <dl class="grid gap-x-8 gap-y-3 text-xs sm:grid-cols-3 lg:grid-cols-4">
              <div>
                <dt class="text-content-muted">Order #</dt>
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
                <dt class="text-content-muted">Server</dt>
                <dd class="mt-0.5">{{ service.detail.server ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">Payment method</dt>
                <dd class="mt-0.5">{{ service.detail.paymentMethod ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">Registration date</dt>
                <dd class="mt-0.5">{{ formatDate(service.detail.registeredOn) }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">Dedicated IP</dt>
                <dd class="mt-0.5 font-mono">{{ service.detail.dedicatedIp ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">Username</dt>
                <dd class="mt-0.5 font-mono">{{ service.detail.username ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">Promotion code</dt>
                <dd class="mt-0.5 font-mono">{{ service.detail.promotionCode ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">Product</dt>
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
      title="No service matches"
      description="Closed accounts are hidden unless the toggle above says otherwise."
    />

    <EmptyState
      v-else
      title="No services yet"
      description="A service appears here as soon as an order that needs setting up is paid for."
    />

    <p v-if="services.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ services.currentPage }} of {{ services.lastPage }} — {{ services.total }} services
    </p>
  </AdminLayout>
</template>
