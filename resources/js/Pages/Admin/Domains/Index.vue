<script setup lang="ts">
/**
 * Domain registrations: the names this installation holds.
 *
 * The `+` opens what an operator needs when a customer rings about a name
 * they cannot reach — which order it came from, whether it was registered
 * or transferred in, and which of the registrar's extras they are paying
 * for. Sent with the list rather than fetched per row, because it is eight
 * fields already loaded.
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

interface DomainDetail {
  orderNumber: string | null
  orderId: string | null
  orderType: string
  registeredOn: string | null
  dnsManagement: boolean
  emailForwarding: boolean
  idProtection: boolean
  premium: boolean
  paymentMethod: string | null
}

interface DomainRow {
  id: string
  name: string
  status: string
  statusLabel: string
  customer: string | null
  customerId: string | null
  expiresOn: string | null
  daysUntilExpiry: number | null
  renewal: string
  years: number
  registrar: string | null
  nextDueOn: string | null
  detail: DomainDetail
}

interface Criteria {
  domain: string
  status: string
  registrar: string
  client: string
}

const props = defineProps<{
  domains: {
    data: DomainRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: Partial<Criteria>
  statuses: { value: string; label: string }[]
  registrars: { value: string; label: string }[]
  counts: { expiring: number; failed: number; pending: number }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'expand', label: '' },
  { key: 'id', label: t('domains.domains.id'), optional: true },
  { key: 'domain', label: t('domains.domains.name'), sticky: true },
  { key: 'client', label: t('domains.domains.customer') },
  { key: 'period', label: t('domains.domains.period'), optional: true },
  { key: 'registrar', label: t('domains.domains.registrar') },
  { key: 'price', label: t('domains.domains.renewal'), numeric: true },
  { key: 'due', label: t('domains.domains.next_due') },
  { key: 'expiry', label: t('domains.domains.expires') },
  { key: 'status', label: t('domains.domains.status') },
]

const EMPTY: Criteria = { domain: '', status: '', registrar: '', client: '' }

const form = ref<Criteria>({ ...EMPTY, ...props.filters })
const open = ref(Object.values(props.filters).some(Boolean))
const expanded = ref<string | null>(null)

const hasFilters = computed(() =>
  (Object.keys(EMPTY) as (keyof Criteria)[]).some((key) => form.value[key] !== ''),
)

function apply(): void {
  const params: Record<string, string> = {}

  for (const key of Object.keys(EMPTY) as (keyof Criteria)[]) {
    if (form.value[key] !== '') params[key] = form.value[key]
  }

  router.get('/admin/domains', params, { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
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

function withBlank(options: { value: string; label: string }[]) {
  return [{ value: '', label: 'Any' }, ...options]
}

/** The extras a customer is paying for, as words rather than four ticks. */
function addonsOf(detail: DomainDetail): string {
  const names = [
    detail.dnsManagement ? 'DNS management' : null,
    detail.emailForwarding ? 'Email forwarding' : null,
    detail.idProtection ? 'ID protection' : null,
    detail.premium ? 'Premium name' : null,
  ].filter(Boolean)

  return names.length > 0 ? names.join(', ') : 'None'
}
</script>

<template>
  <Head :title="t('ui.nav.domain_registrations')" />

  <AdminLayout
    :heading="t('ui.nav.domain_registrations')"
    description="The names this installation holds. A registry that did not answer has not said a name is free."
  >
    <div class="mb-7 flex flex-wrap gap-3">
      <AppStat
        :label="t('domains.domains.expiring_soon')"
        :value="counts.expiring"
        :tone="counts.expiring > 0 ? 'warning' : 'neutral'"
        @select="filterByStatus('')"
      />
      <AppStat
        :label="t('domains.domains.failed')"
        :value="counts.failed"
        :tone="counts.failed > 0 ? 'danger' : 'neutral'"
        :active="form.status === 'failed'"
        @select="filterByStatus('failed')"
      />
      <AppStat
        :label="t('domains.domains.pending')"
        :value="counts.pending"
        :active="form.status === 'pending'"
        @select="filterByStatus('pending')"
      />
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton :aria-expanded="open" @click="open = !open">
        {{ open ? 'Hide search' : 'Search / filter' }}
      </AppButton>
      <span v-if="hasFilters" class="text-content-muted text-chrome"
        >{{ domains.total }} match</span
      >
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4"
      >
        <AppInput
          v-model="form.domain"
          :label="t('domains.domains.name')"
          :hint="t('domains.domains.name_hint')"
        />
        <AppSelect
          v-model="form.status"
          :label="t('domains.domains.status')"
          :options="withBlank(statuses)"
        />
        <AppSelect
          v-model="form.registrar"
          :label="t('domains.domains.registrar')"
          :options="withBlank(registrars)"
        />
        <AppInput v-model="form.client" :label="t('domains.domains.customer')" />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">Search</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">Clear</AppButton>
      </div>
    </form>

    <AppTable v-if="domains.data.length > 0" name="admin-domains" :columns="COLUMNS">
      <template v-for="domain in domains.data" :key="domain.id">
        <AppTableRow>
          <td data-col="expand">
            <button
              type="button"
              class="pressable border-line text-content-muted hover:text-content text-chrome inline-flex size-5 items-center justify-center rounded-sm border font-mono leading-none"
              :aria-expanded="expanded === domain.id"
              :aria-label="
                expanded === domain.id
                  ? t('domains.domains.hide_detail')
                  : t('domains.domains.show_detail')
              "
              @click="toggleRow(domain.id)"
            >
              {{ expanded === domain.id ? '−' : '+' }}
            </button>
          </td>
          <td data-col="id" class="text-content-subtle text-chrome font-mono">
            {{ domain.id.slice(-8) }}
          </td>
          <td data-col="domain">
            <Link
              :href="`/admin/domains/${domain.id}`"
              class="font-medium underline-offset-4 hover:underline"
            >
              {{ domain.name }}
            </Link>
          </td>
          <td data-col="client">
            <Link
              v-if="domain.customerId"
              :href="`/admin/customers/${domain.customerId}`"
              class="underline-offset-4 hover:underline"
            >
              {{ domain.customer ?? '—' }}
            </Link>
            <span v-else>—</span>
          </td>
          <td data-col="period" class="text-content-muted whitespace-nowrap">
            {{ t('domains.domains.years', { count: domain.years }) }}
          </td>
          <td data-col="registrar" class="text-content-muted">{{ domain.registrar ?? '—' }}</td>
          <td data-col="price" class="numeric tabular-nums">{{ domain.renewal }}</td>
          <td data-col="due" class="text-content-muted whitespace-nowrap">
            {{ formatDate(domain.nextDueOn) }}
          </td>
          <td data-col="expiry" class="whitespace-nowrap">
            {{ formatDate(domain.expiresOn) }}
            <span
              v-if="domain.daysUntilExpiry !== null && domain.daysUntilExpiry <= 45"
              class="text-warning text-chrome block"
            >
              {{ t('domains.domains.days_left', { count: domain.daysUntilExpiry }) }}
            </span>
          </td>
          <td data-col="status">
            <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
          </td>
        </AppTableRow>

        <tr v-if="expanded === domain.id" class="bg-surface-secondary">
          <td :colspan="COLUMNS.length" class="px-5 py-4">
            <dl class="text-chrome grid gap-x-8 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
              <div>
                <dt class="text-content-muted">{{ t('domains.domains.order_number') }}</dt>
                <dd class="mt-0.5">
                  <Link
                    v-if="domain.detail.orderId"
                    :href="`/admin/orders/${domain.detail.orderId}`"
                    class="underline-offset-4 hover:underline"
                  >
                    {{ domain.detail.orderNumber ?? '—' }}
                  </Link>
                  <span v-else>—</span>
                </dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('domains.domains.order_type') }}</dt>
                <dd class="mt-0.5">{{ domain.detail.orderType }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('domains.domains.registered_on') }}</dt>
                <dd class="mt-0.5">{{ formatDate(domain.detail.registeredOn) }}</dd>
              </div>
              <div>
                <dt class="text-content-muted">{{ t('domains.domains.payment_method') }}</dt>
                <dd class="mt-0.5">{{ domain.detail.paymentMethod ?? '—' }}</dd>
              </div>
              <div class="sm:col-span-3 lg:col-span-4">
                <dt class="text-content-muted">{{ t('domains.domains.addons') }}</dt>
                <dd class="mt-0.5">{{ addonsOf(domain.detail) }}</dd>
              </div>
            </dl>
          </td>
        </tr>
      </template>
    </AppTable>

    <EmptyState
      v-else-if="hasFilters"
      icon="domains"
      :title="t('domains.domains.no_match')"
      :description="t('domains.domains.no_match_description')"
    />

    <EmptyState
      v-else
      icon="domains"
      :title="t('domains.domains.empty')"
      :description="t('domains.domains.empty_description')"
    />

    <AppPagination :links="domains.links" :total="domains.total" />
  </AdminLayout>
</template>
