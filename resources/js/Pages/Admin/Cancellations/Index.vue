<script setup lang="ts">
/**
 * The cancellation queue: who asked to stop, why, and by when.
 *
 * Outstanding requests first and oldest first, because that is what a queue
 * is for. A request nobody acted on is a customer who told you they were
 * leaving and heard nothing back — the one cancellation still worth a
 * telephone call.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppPagination from '../../../Components/AppPagination.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface RequestRow {
  id: string
  requestedAt: string
  service: string | null
  serviceId: string
  domain: string | null
  customer: string | null
  customerId: string
  reason: string | null
  type: string
  typeLabel: string
  status: string
  statusLabel: string
  requestedBy: string | null
  endsOn: string | null
}

interface Criteria {
  reason: string
  client: string
  domain: string
  type: string
  service: string
  status: string
}

const props = defineProps<{
  requests: {
    data: RequestRow[]
    currentPage: number
    lastPage: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
  }
  filters: Partial<Criteria>
  types: { value: string; label: string }[]
  statuses: { value: string; label: string }[]
  can: { manage: boolean }
}>()

const EMPTY: Criteria = { reason: '', client: '', domain: '', type: '', service: '', status: '' }

const form = ref<Criteria>({ ...EMPTY, ...props.filters })
const open = ref(Object.values(props.filters).some(Boolean))

const hasFilters = computed(() =>
  (Object.keys(EMPTY) as (keyof Criteria)[]).some((key) => form.value[key] !== ''),
)

function apply(): void {
  const params: Record<string, string> = {}

  for (const key of Object.keys(EMPTY) as (keyof Criteria)[]) {
    if (form.value[key] !== '') params[key] = form.value[key]
  }

  router.get('/admin/cancellations', params, { preserveState: true, replace: true })
}

function clear(): void {
  form.value = { ...EMPTY }
  apply()
}

function act(row: RequestRow, action: 'complete' | 'withdraw'): void {
  router.post(`/admin/cancellations/${row.id}/${action}`, {}, { preserveScroll: true })
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(options: { value: string; label: string }[], label = 'Any') {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head title="Cancellation requests" />

  <AdminLayout
    heading="Cancellation Requests"
    description="Who asked to stop, why, and whether they wanted it off today or at the end of the term they have paid for."
  >
    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton :aria-expanded="open" @click="open = !open">
        {{ open ? 'Hide search' : 'Search / filter' }}
      </AppButton>
      <span v-if="hasFilters" class="text-content-muted text-chrome"
        >{{ requests.total }} match</span
      >
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <AppInput v-model="form.reason" label="Reason" />
        <AppInput v-model="form.client" label="Client" />
        <AppInput v-model="form.domain" label="Domain" />
        <AppSelect v-model="form.type" label="Type" :options="withBlank(types)" />
        <AppInput v-model="form.service" label="Service ID" />
        <AppSelect
          v-model="form.status"
          label="Status"
          hint="Outstanding requests only, unless you ask otherwise."
          :options="withBlank(statuses, 'Pending')"
        />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">Search</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">Clear</AppButton>
      </div>
    </form>

    <AppTable
      v-if="requests.data.length > 0"
      :headers="['Date', 'Product / service', 'Client', 'Reason', 'Type', 'Ends', 'Status', '']"
    >
      <tr v-for="row in requests.data" :key="row.id">
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(row.requestedAt) }}
        </td>
        <td class="px-4 py-2.5">
          <Link
            :href="`/admin/services/${row.serviceId}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ row.service ?? '—' }}
          </Link>
          <span v-if="row.domain" class="text-content-muted text-chrome block">{{
            row.domain
          }}</span>
        </td>
        <td class="px-4 py-2.5">
          <Link
            :href="`/admin/customers/${row.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ row.customer ?? '—' }}
          </Link>
          <span v-if="row.requestedBy" class="text-content-muted text-chrome block">
            asked by {{ row.requestedBy }}
          </span>
        </td>
        <td class="text-content-muted text-body max-w-[32ch] px-4 py-2.5">
          {{ row.reason ?? '—' }}
        </td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="row.type === 'immediate' ? 'danger' : 'neutral'">
            {{ row.typeLabel }}
          </AppBadge>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ row.type === 'immediate' ? 'Now' : formatDate(row.endsOn) }}
        </td>
        <td class="px-4 py-2.5">
          <AppStatus :tone="statusTone(row.status)" :label="row.statusLabel" />
        </td>
        <td class="px-4 py-2.5 text-right">
          <div v-if="can.manage && row.status === 'pending'" class="flex justify-end gap-2">
            <AppButton size="sm" variant="ghost" @click="act(row, 'withdraw')">Withdraw</AppButton>
            <AppButton size="sm" variant="primary" @click="act(row, 'complete')"
              >Complete</AppButton
            >
          </div>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="Nothing outstanding"
      description="A request appears here when a customer asks to stop, whether they asked in the portal or told somebody on the telephone."
    />

    <AppPagination :links="requests.links" :total="requests.total" />
  </AdminLayout>
</template>
