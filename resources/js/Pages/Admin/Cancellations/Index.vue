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
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'date', label: t('crm.cancellations.date') },
  { key: 'service', label: t('crm.cancellations.service'), sticky: true },
  { key: 'client', label: t('crm.cancellations.client') },
  { key: 'reason', label: t('crm.cancellations.reason') },
  { key: 'type', label: t('crm.cancellations.type') },
  { key: 'ends', label: t('crm.cancellations.ends') },
  { key: 'status', label: t('crm.cancellations.status') },
  { key: 'actions', label: '' },
]

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

/*
 * Completing an immediate request **terminates the service**, which used to
 * happen on the first click of a solid primary button. It is level 4 now —
 * the reason is recorded on the transition and the service's own name has to
 * be typed — while an end-of-term request, which only marks the service to
 * stop renewing, is level 2. Withdrawing puts a service back and asks once.
 */
const completing = ref<RequestRow | null>(null)
const withdrawing = ref<RequestRow | null>(null)

function complete(reason: string | null): void {
  const row = completing.value

  if (row === null) return

  router.post(
    `/admin/cancellations/${row.id}/complete`,
    { reason },
    {
      preserveScroll: true,
      onFinish: () => {
        completing.value = null
      },
    },
  )
}

function withdraw(): void {
  const row = withdrawing.value

  if (row === null) return

  router.post(
    `/admin/cancellations/${row.id}/withdraw`,
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        withdrawing.value = null
      },
    },
  )
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

function withBlank(
  options: { value: string; label: string }[],
  label = t('crm.cancellations.any'),
) {
  return [{ value: '', label }, ...options]
}
</script>

<template>
  <Head :title="t('ui.nav.cancellation_requests')" />

  <AdminLayout
    :heading="t('ui.nav.cancellation_requests')"
    :description="t('crm.cancellations.subtitle')"
  >
    <div class="mb-5 flex flex-wrap items-center gap-2.5">
      <AppButton :aria-expanded="open" @click="open = !open">
        {{ open ? t('crm.cancellations.hide_search') : t('crm.cancellations.search_filter') }}
      </AppButton>
      <span v-if="hasFilters" class="text-content-muted text-chrome">{{
        t('crm.cancellations.matches', { count: requests.total })
      }}</span>
    </div>

    <form v-if="open" class="mb-6" @submit.prevent="apply">
      <div
        class="border-line bg-surface-primary grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <AppInput v-model="form.reason" :label="t('crm.cancellations.reason')" />
        <AppInput v-model="form.client" :label="t('crm.cancellations.client')" />
        <AppInput v-model="form.domain" :label="t('crm.cancellations.domain')" />
        <AppSelect
          v-model="form.type"
          :label="t('crm.cancellations.type')"
          :options="withBlank(types)"
        />
        <AppInput v-model="form.service" :label="t('crm.cancellations.service_id')" />
        <AppSelect
          v-model="form.status"
          :label="t('crm.cancellations.status')"
          :hint="t('crm.cancellations.status_hint')"
          :options="withBlank(statuses, t('crm.cancellations.pending'))"
        />
      </div>

      <div class="mt-3 flex gap-2">
        <AppButton type="submit" variant="primary">{{ t('crm.cancellations.search') }}</AppButton>
        <AppButton type="button" variant="ghost" @click="clear">{{
          t('crm.cancellations.clear')
        }}</AppButton>
      </div>
    </form>

    <AppTable v-if="requests.data.length > 0" name="admin-cancellations" :columns="COLUMNS">
      <AppTableRow v-for="row in requests.data" :key="row.id">
        <td data-col="date" class="text-content-muted whitespace-nowrap">
          {{ formatDate(row.requestedAt) }}
        </td>
        <td data-col="service">
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
        <td data-col="client">
          <Link
            :href="`/admin/customers/${row.customerId}`"
            class="underline-offset-4 hover:underline"
          >
            {{ row.customer ?? '—' }}
          </Link>
          <span v-if="row.requestedBy" class="text-content-muted text-chrome block">
            {{ t('crm.cancellations.asked_by', { name: row.requestedBy }) }}
          </span>
        </td>
        <td data-col="reason" class="text-content-muted text-body max-w-[32ch]">
          {{ row.reason ?? '—' }}
        </td>
        <td data-col="type">
          <AppBadge :tone="row.type === 'immediate' ? 'danger' : 'neutral'">
            {{ row.typeLabel }}
          </AppBadge>
        </td>
        <td data-col="ends" class="text-content-muted whitespace-nowrap">
          {{ row.type === 'immediate' ? t('crm.cancellations.now') : formatDate(row.endsOn) }}
        </td>
        <td data-col="status">
          <AppStatus :tone="statusTone(row.status)" :label="row.statusLabel" />
        </td>
        <td data-col="actions" class="text-right">
          <span
            v-if="can.manage && row.status === 'pending'"
            class="row-actions inline-flex justify-end gap-1"
          >
            <AppButton size="sm" variant="ghost" @click="withdrawing = row">
              {{ t('crm.cancellations.withdraw') }}
            </AppButton>
            <AppButton
              size="sm"
              :variant="row.type === 'immediate' ? 'danger-subtle' : 'secondary'"
              @click="completing = row"
            >
              {{ t('crm.cancellations.complete') }}
            </AppButton>
          </span>
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="services"
      :title="t('crm.cancellations.empty')"
      :description="t('crm.cancellations.empty_description')"
    />

    <AppPagination :links="requests.links" :total="requests.total" />

    <AppConfirm
      :open="completing !== null"
      :level="completing?.type === 'immediate' ? 'destructive' : 'consequential'"
      :title="t('crm.cancellations.complete_title', { service: completing?.service ?? '' })"
      :description="
        completing?.type === 'immediate'
          ? t('crm.cancellations.complete_now_detail')
          : t('crm.cancellations.complete_term_detail')
      "
      :phrase="completing?.type === 'immediate' ? (completing?.service ?? undefined) : undefined"
      :confirm-label="t('crm.cancellations.complete')"
      @update:open="(value: boolean) => (completing = value ? completing : null)"
      @confirm="complete"
    />

    <AppConfirm
      :open="withdrawing !== null"
      level="consequential"
      :title="t('crm.cancellations.withdraw_title', { service: withdrawing?.service ?? '' })"
      :description="t('crm.cancellations.withdraw_detail')"
      :confirm-label="t('crm.cancellations.withdraw')"
      @update:open="(value: boolean) => (withdrawing = value ? withdrawing : null)"
      @confirm="withdraw"
    />
  </AdminLayout>
</template>
