<script setup lang="ts">
/**
 * Plan changes that are waiting on somebody.
 *
 * The queue an operator opens to answer one question — is anything stuck —
 * so the default is the open states and "everything" is a toggle. A move that
 * went through on the same day never stops here at all, which is why an empty
 * list is the ordinary case and says so.
 *
 * Asking for a change is on the service's own screen rather than here: it is
 * a thing somebody does *to a service*, and a form on this page would be a
 * second place to start one.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { asTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface UpgradeRow {
  id: string
  serviceId: string
  service: string | null
  customer: string | null
  from: string
  to: string
  status: string
  statusLabel: string
  /** Decided by the server, so `asTone` reads it rather than `statusTone`. */
  tone: string
  difference: string
  isDowngrade: boolean
  result: string | null
  canApply: boolean
  canWithdraw: boolean
  requestedAt: string | null
}

const props = defineProps<{
  upgrades: {
    data: UpgradeRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const locale = computed(() => usePage().props.locale ?? 'en')

const withdrawing = ref<UpgradeRow | null>(null)

const columns: TableColumn[] = [
  { key: 'service', label: t('provisioning.upgrades.service') },
  { key: 'move', label: t('provisioning.upgrades.to') },
  { key: 'state', label: t('provisioning.upgrades.state') },
  { key: 'difference', label: t('provisioning.upgrades.difference'), numeric: true },
  { key: 'requested', label: t('provisioning.upgrades.requested') },
  { key: 'actions', label: '' },
]

function toggleAll(): void {
  router.get(
    '/admin/services/upgrades',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function apply(row: UpgradeRow): void {
  router.post(`/admin/services/upgrades/${row.id}/apply`, {}, { preserveScroll: true })
}

function withdraw(): void {
  const row = withdrawing.value

  withdrawing.value = null

  if (row === null) return

  router.delete(`/admin/services/upgrades/${row.id}`, { preserveScroll: true })
}

/** An instant the server sent, read where the operator is. */
function when(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleString(locale.value)
}
</script>

<template>
  <Head :title="t('provisioning.upgrades.queue')" />

  <AdminLayout
    :heading="t('provisioning.upgrades.queue')"
    :description="t('provisioning.upgrades.queue_intro')"
  >
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{
          filters.all ? t('provisioning.upgrades.show_open') : t('provisioning.upgrades.show_all')
        }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <EmptyState
        v-if="upgrades.data.length === 0"
        :title="t('provisioning.upgrades.empty')"
        :description="t('provisioning.upgrades.withdraw_body')"
        icon="services"
        boxed
      />

      <AppTable v-else name="service-upgrades" :columns="columns">
        <AppTableRow v-for="row in upgrades.data" :key="row.id">
          <!-- The link is in the identity cell: a `<tr>` cannot be wrapped in
               an anchor, and `AppTableRow` has no `href`. -->
          <td data-col="service">
            <Link
              :href="`/admin/services/${row.serviceId}`"
              class="text-brand font-medium hover:underline"
            >
              {{ row.service }}
            </Link>
            <span class="text-content-subtle text-chrome block">{{ row.customer }}</span>
          </td>
          <td data-col="move" class="text-content-muted">
            {{ row.from }}
            <span class="text-content-subtle">→</span>
            {{ row.to }}
          </td>
          <td data-col="state">
            <AppStatus :tone="asTone(row.tone)" :label="row.statusLabel" />
            <span v-if="row.result" class="text-content-subtle text-chrome block max-w-[40ch]">
              {{ row.result }}
            </span>
          </td>
          <!--
            A downgrade is a credit rather than a charge, and the sign is what
            says so: a figure with no direction would read as money owed.
          -->
          <td data-col="difference" class="numeric">
            {{ row.isDowngrade ? `−${row.difference}` : row.difference }}
          </td>
          <td data-col="requested" class="text-content-muted text-chrome tabular-nums">
            {{ when(row.requestedAt) }}
          </td>
          <td data-col="actions" class="text-right">
            <span v-if="can.manage" class="row-actions flex justify-end gap-2">
              <AppButton v-if="row.canApply" size="sm" variant="secondary" @click="apply(row)">
                {{ t('provisioning.upgrades.confirm_submit') }}
              </AppButton>
              <AppButton
                v-if="row.canWithdraw"
                size="sm"
                variant="danger-subtle"
                @click="withdrawing = row"
              >
                {{ t('provisioning.upgrades.withdraw') }}
              </AppButton>
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="upgrades.links" :total="upgrades.total" />
    </div>

    <AppConfirm
      :open="withdrawing !== null"
      level="consequential"
      :title="t('provisioning.upgrades.withdraw')"
      :description="t('provisioning.upgrades.withdraw_body')"
      :confirm-label="t('provisioning.upgrades.withdraw')"
      @close="withdrawing = null"
      @confirm="withdraw"
    />
  </AdminLayout>
</template>
