<script setup lang="ts">
/**
 * One network, and the addresses somebody has done something with.
 *
 * **The free addresses are not listed and that is not a gap.** A /24 would draw
 * 254 rows of nothing and a /64 could not be drawn at all, so the list is what
 * exists — assigned, reserved, cooling off — and the button takes the next free
 * one when an operator needs it.
 *
 * Releasing is a confirmation rather than a click, and the sentence says the part
 * an operator would not otherwise know: that the address is put aside rather than
 * handed straight to the next customer, because it carries whatever reputation
 * the last holder earned.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface AddressRow {
  id: string
  address: string
  /** Two fields: the value to reason about and the word to print. */
  state: string
  stateLabel: string
  reverseDns: string | null
  note: string | null
  holder: string | null
  heldSince: string | null
}

const props = defineProps<{
  prefix: {
    id: string
    cidr: string
    familyLabel: string
    pool: string | null
    purposeLabel: string | null
    vlan: string | null
    site: string | null
    gateway: string | null
    note: string | null
    parent: string | null
    children: { id: string; cidr: string }[]
    used: number
    capacity: number | null
    utilisation: number | null
  }
  addresses: {
    data: AddressRow[]
    currentPage: number
    lastPage: number
    total: number
    links: PaginationLink[]
  }
  states: { value: string; label: string }[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const releasing = ref<AddressRow | null>(null)
const removing = ref(false)

const releaseForm = useForm({ quarantine: true, note: '' })

const COLUMNS: TableColumn[] = [
  { key: 'address', label: t('network.addresses.address') },
  { key: 'state', label: t('network.addresses.state') },
  { key: 'holder', label: t('network.addresses.holder') },
  { key: 'since', label: t('network.addresses.held_since') },
  { key: 'rdns', label: t('network.addresses.reverse_dns'), optional: true },
  { key: 'actions', label: '' },
]

const summary = computed<DescriptionItem[]>(() => [
  { key: 'pool', label: t('network.prefixes.pool'), value: props.prefix.pool },
  { key: 'purpose', label: t('network.pools.purpose'), value: props.prefix.purposeLabel },
  { key: 'gateway', label: t('network.prefixes.gateway'), value: props.prefix.gateway, mono: true },
  { key: 'vlan', label: t('network.prefixes.vlan'), value: props.prefix.vlan },
  { key: 'site', label: t('network.prefixes.site'), value: props.prefix.site },
  { key: 'parent', label: t('network.prefixes.parent'), value: props.prefix.parent, mono: true },
  {
    key: 'capacity',
    label: t('network.prefixes.capacity'),
    value:
      props.prefix.capacity === null
        ? t('network.prefixes.too_large')
        : `${props.prefix.used} / ${props.prefix.capacity}`,
  },
  { key: 'note', label: t('network.prefixes.note'), value: props.prefix.note },
])

function allocate(): void {
  router.post(`/admin/network/prefixes/${props.prefix.id}/allocate`, {}, { preserveScroll: true })
}

function release(): void {
  const address = releasing.value

  if (address === null) return

  releaseForm.post(`/admin/network/addresses/${address.id}/release`, {
    preserveScroll: true,
    onSuccess: () => {
      releasing.value = null
      releaseForm.reset()
    },
  })
}

function remove(): void {
  router.delete(`/admin/network/prefixes/${props.prefix.id}`)
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="prefix.cidr" />

  <AdminLayout :heading="prefix.cidr">
    <template #meta>
      <span class="text-content-muted">{{ prefix.familyLabel }}</span>
      <span v-if="prefix.pool" class="text-content-muted">· {{ prefix.pool }}</span>
    </template>

    <template #actions>
      <AppButton v-if="can.manage" variant="primary" icon="add" @click="allocate">
        {{ t('network.addresses.allocate') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection :title="t('network.prefixes.title')">
        <DescriptionList :items="summary" />
      </DetailSection>

      <DetailSection
        v-if="prefix.children.length > 0"
        :title="t('network.prefixes.children')"
        :divided="false"
      >
        <ul class="flex flex-wrap gap-2">
          <li v-for="child in prefix.children" :key="child.id">
            <AppButton
              variant="secondary"
              size="sm"
              :href="`/admin/network/addressing/${child.id}`"
            >
              {{ child.cidr }}
            </AppButton>
          </li>
        </ul>
      </DetailSection>

      <DetailSection
        :title="t('network.addresses.title')"
        :description="t('network.addresses.intro')"
        :divided="false"
      >
        <EmptyState
          v-if="addresses.data.length === 0"
          :title="t('network.addresses.title')"
          :description="t('network.addresses.empty')"
          icon="servers"
          plain
        />

        <template v-else>
          <AppTable name="addresses" :columns="COLUMNS">
            <AppTableRow v-for="row in addresses.data" :key="row.id">
              <td data-col="address" class="font-mono font-medium">{{ row.address }}</td>
              <td data-col="state">
                <AppStatus :tone="statusTone(row.state)" :label="row.stateLabel" />
              </td>
              <td data-col="holder">{{ row.holder ?? '—' }}</td>
              <td data-col="since" class="text-content-muted tabular-nums">
                {{ when(row.heldSince) }}
              </td>
              <td data-col="rdns" class="text-content-muted font-mono">
                {{ row.reverseDns ?? '—' }}
              </td>
              <td data-col="actions">
                <div class="row-actions">
                  <AppButton
                    v-if="can.manage && row.holder"
                    variant="danger-subtle"
                    size="sm"
                    @click="releasing = row"
                  >
                    {{ t('network.addresses.release') }}
                  </AppButton>
                </div>
              </td>
            </AppTableRow>
          </AppTable>

          <AppPagination :links="addresses.links" :total="addresses.total" />
        </template>
      </DetailSection>

      <DangerZone v-if="can.manage">
        <DangerZoneRow
          :title="t('network.prefixes.delete_title')"
          :description="t('network.prefixes.delete_body')"
        >
          <AppButton variant="danger-subtle" @click="removing = true">
            {{ t('network.prefixes.delete') }}
          </AppButton>
        </DangerZoneRow>
      </DangerZone>
    </div>

    <AppConfirm
      :open="releasing !== null"
      level="consequential"
      :title="t('network.addresses.release_title')"
      :body="t('network.addresses.release_body')"
      :confirm-label="t('network.addresses.release_confirm')"
      :loading="releaseForm.processing"
      @cancel="releasing = null"
      @confirm="release"
    >
      <AppCheckbox
        :model-value="!releaseForm.quarantine"
        :label="t('network.addresses.reuse_now')"
        @update:model-value="(value) => (releaseForm.quarantine = !value)"
      />
    </AppConfirm>

    <AppConfirm
      :open="removing"
      level="high-risk"
      :title="t('network.prefixes.delete_title')"
      :body="t('network.prefixes.delete_body')"
      :confirm-label="t('network.prefixes.delete_confirm')"
      @cancel="removing = false"
      @confirm="remove"
    />
  </AdminLayout>
</template>
