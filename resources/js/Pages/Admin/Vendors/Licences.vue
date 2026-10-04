<script setup lang="ts">
/**
 * Operational licences, and which machine is using one (§24).
 *
 * **The screen opens on the difference**, the way backup coverage does. Every
 * vendor portal can say how many seats were bought; only this installation
 * holds the server list, so only it can say which of them are doing anything.
 *
 * The three figures are three different units on purpose — idle counts
 * *seats*, orphaned counts *allocations*, running-without counts *machines* —
 * and each one shows exactly the rows under it. They do not sum to anything.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import MoneyInput from '../../../Components/MoneyInput.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface PoolRow {
  id: string
  name: string
  vendor: string
  contract: string | null
  forModule: string | null
  seats: number
  used: number
  spare: number
  unitPrice: string
  unitAmountMinor: number
  currency: string
  total: string
  allocated: string[]
  note: string | null
}

interface OrphanRow {
  id: string
  pool: string
  vendor: string
  server: string
  reason: 'gone' | 'offline'
  reference: string | null
}

interface Gap {
  pool: string
  poolId: string
  module: string | null
  servers: { id: string; name: string; hostname: string }[]
}

interface Option {
  value: string
  label: string
}

const props = defineProps<{
  pools: PoolRow[]
  orphaned: OrphanRow[]
  missing: Gap[]
  summary: { spare: number; overage: number; orphaned: number; missing: number }
  options: {
    vendors: Option[]
    contracts: Option[]
    modules: Option[]
    servers: Option[]
  }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const locale = computed(() => usePage().props.locale ?? 'en')

const adding = ref(false)
const allocatingTo = ref<PoolRow | null>(null)
const releasing = ref<OrphanRow | null>(null)
const removing = ref<PoolRow | null>(null)

const poolForm = useForm({
  vendor_id: props.options.vendors[0]?.value ?? '',
  contract_id: '',
  name: '',
  for_module: '',
  seats: 0,
  currency_code: 'USD',
  unit_amount_minor: 0,
  note: '',
})

const allocationForm = useForm({
  server_id: props.options.servers[0]?.value ?? '',
  reference: '',
  note: '',
})

/*
 * "Do not say" is the first option with an empty value, which is how a
 * native select bound to '' shows anything at all — the ticket screen's
 * blank Department dropdown was this exact mistake.
 */
const MODULE_OPTIONS = computed<Option[]>(() => [
  { value: '', label: t('vendors.licences.for_module_any') },
  ...props.options.modules,
])

const CONTRACT_OPTIONS = computed<Option[]>(() => [
  // Its own sentence. "Do not say" is right for the module, where the
  // absence is a claim this platform declines to make; a licence with no
  // contract behind it is simply not under one.
  { value: '', label: t('vendors.licences.contract_any') },
  ...props.options.contracts,
])

/**
 * The machines that do not already hold a seat of this pool.
 *
 * A choice that is offered and then refused is a refusal the form could have
 * avoided asking for.
 */
const SERVER_OPTIONS = computed<Option[]>(() => {
  const pool = allocatingTo.value

  if (pool === null) return props.options.servers

  return props.options.servers.filter((server) => !pool.allocated.includes(server.value))
})

const POOL_COLUMNS: TableColumn[] = [
  { key: 'name', label: t('vendors.licences.name') },
  { key: 'vendor', label: t('vendors.licences.vendor') },
  { key: 'module', label: t('vendors.licences.for_module') },
  { key: 'seats', label: t('vendors.licences.seats'), numeric: true },
  { key: 'used', label: t('vendors.licences.used'), numeric: true },
  { key: 'spare', label: t('vendors.licences.spare'), numeric: true },
  { key: 'total', label: t('vendors.licences.total'), numeric: true },
  { key: 'actions', label: '' },
]

const ORPHAN_COLUMNS: TableColumn[] = [
  { key: 'server', label: t('vendors.licences.server') },
  { key: 'pool', label: t('vendors.licences.name') },
  { key: 'vendor', label: t('vendors.licences.vendor') },
  // Its own word. Borrowing the section heading would put "On a machine that
  // has gone" over the cell that says which of the two things happened.
  { key: 'reason', label: t('vendors.licences.reason') },
  { key: 'actions', label: '' },
]

const spare = computed(() => props.pools.filter((pool) => pool.spare > 0))

const FIGURES = computed<Metric[]>(() => [
  /*
   * In the order the sections below are in. A strip that is not a legend for
   * what follows it is a strip nobody reads twice — and the worst finding is
   * first in both, because running unlicensed costs an outage rather than
   * money.
   */
  {
    key: 'missing',
    label: t('vendors.licences.sections.missing'),
    value: count(props.summary.missing),
    tone: props.summary.missing > 0 ? 'critical' : undefined,
  },
  {
    key: 'orphaned',
    label: t('vendors.licences.sections.orphaned'),
    value: count(props.summary.orphaned),
    tone: props.summary.orphaned > 0 ? 'warning' : undefined,
  },
  {
    key: 'spare',
    label: t('vendors.licences.spare'),
    value: count(props.summary.spare),
    tone: props.summary.spare > 0 ? 'warning' : undefined,
  },
  {
    key: 'overage',
    label: t('vendors.licences.overage'),
    value: count(props.summary.overage),
    tone: props.summary.overage > 0 ? 'critical' : undefined,
  },
])

function count(value: number): string {
  return value.toLocaleString(locale.value)
}

function savePool(): void {
  poolForm.post('/admin/vendors/licences', {
    preserveScroll: true,
    onSuccess: () => {
      poolForm.reset()
      adding.value = false
    },
  })
}

function allocate(): void {
  const pool = allocatingTo.value

  if (pool === null) return

  allocationForm.post(`/admin/vendors/licences/${pool.id}/allocations`, {
    preserveScroll: true,
    onSuccess: () => {
      allocationForm.reset()
      allocatingTo.value = null
    },
  })
}

function release(): void {
  const row = releasing.value

  if (row === null) return

  releasing.value = null
  router.delete(`/admin/vendors/allocations/${row.id}`, { preserveScroll: true })
}

function deletePool(): void {
  const pool = removing.value

  if (pool === null) return

  removing.value = null
  router.delete(`/admin/vendors/licences/${pool.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t('vendors.licences.title')" />

  <AdminLayout :heading="t('ui.nav.licences')" :description="t('vendors.licences.intro')">
    <template v-if="can.manage" #actions>
      <AppButton variant="primary" icon="add" @click="adding = !adding">
        {{ t('vendors.licences.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        `MetricStrip`, not `AppStat`: these figures do not filter anything —
        every one of their lists is already on the page under its own
        heading — and `AppStat` is a `<button>` carrying `aria-pressed`. Four
        controls that report a pressed state and do nothing is a control that
        lies.

        A zero is left untoned, because nothing running unlicensed is the good
        answer and a screen that drew it in danger would teach an operator to
        stop reading the colour.
      -->
      <MetricStrip :items="FIGURES" />

      <DetailSection v-if="adding && can.manage" :title="t('vendors.licences.add')">
        <form class="flex max-w-xl flex-col gap-4" @submit.prevent="savePool">
          <AppInput
            v-model="poolForm.name"
            :label="t('vendors.licences.name')"
            :error="poolForm.errors.name"
          />
          <AppSelect
            v-model="poolForm.vendor_id"
            :label="t('vendors.licences.vendor')"
            :options="options.vendors"
            :error="poolForm.errors.vendor_id"
          />
          <AppSelect
            v-model="poolForm.contract_id"
            :label="t('vendors.licences.contract')"
            :options="CONTRACT_OPTIONS"
            :hint="t('vendors.licences.contract_hint')"
            :error="poolForm.errors.contract_id"
          />
          <AppSelect
            v-model="poolForm.for_module"
            :label="t('vendors.licences.for_module')"
            :options="MODULE_OPTIONS"
            :hint="t('vendors.licences.for_module_hint')"
            :error="poolForm.errors.for_module"
          />
          <AppInput
            v-model="poolForm.seats"
            type="number"
            :label="t('vendors.licences.seats')"
            :error="poolForm.errors.seats"
          />
          <!-- The currency first: it is the unit the figure under it is in. -->
          <AppInput
            v-model="poolForm.currency_code"
            :label="t('vendors.licences.currency')"
            :error="poolForm.errors.currency_code"
          />
          <MoneyInput
            v-model="poolForm.unit_amount_minor"
            :label="t('vendors.licences.unit_price')"
            :exponent="2"
            :error="poolForm.errors.unit_amount_minor"
          />
          <AppTextarea v-model="poolForm.note" :label="t('vendors.note')" :rows="2" />

          <div class="flex gap-2">
            <AppButton type="submit" variant="primary" :loading="poolForm.processing">
              {{ t('vendors.licences.add_submit') }}
            </AppButton>
            <AppButton variant="ghost" @click="adding = false">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <!-- The one that costs an outage rather than money, so it is first. -->
      <DetailSection
        :title="t('vendors.licences.sections.missing')"
        :description="t('vendors.licences.sections.missing_detail')"
        :divided="false"
      >
        <EmptyState
          v-if="missing.length === 0"
          variant="plain"
          icon="check"
          :title="t('vendors.licences.nothing_missing')"
          :description="t('vendors.licences.for_module_hint')"
        />

        <div v-else class="flex flex-col gap-5">
          <div v-for="gap in missing" :key="gap.poolId">
            <p class="text-body text-content mb-2 font-medium">
              {{ gap.pool }}
              <!--
                A separator, not two fonts. "cPanel Admin, 100 accounts
                cpanel" is two facts a reader has to split by weight alone.
              -->
              <span class="text-content-muted font-mono font-normal">· {{ gap.module }}</span>
            </p>
            <ul class="flex flex-wrap gap-2">
              <li
                v-for="server in gap.servers"
                :key="server.id"
                class="border-line text-body text-content rounded-md border px-2 py-1"
              >
                {{ server.name }}
                <span class="text-content-subtle font-mono">· {{ server.hostname }}</span>
              </li>
            </ul>
          </div>
        </div>
      </DetailSection>

      <DetailSection
        :title="t('vendors.licences.sections.orphaned')"
        :description="t('vendors.licences.sections.orphaned_detail')"
        :divided="false"
      >
        <EmptyState
          v-if="orphaned.length === 0"
          variant="plain"
          icon="check"
          :title="t('vendors.licences.nothing_orphaned')"
          :description="t('vendors.licences.sections.orphaned_detail')"
        />

        <AppTable v-else name="orphaned" :columns="ORPHAN_COLUMNS">
          <AppTableRow v-for="row in orphaned" :key="row.id">
            <td data-col="server">
              <span class="font-medium">{{ row.server }}</span>
            </td>
            <td data-col="pool" class="text-content-muted">{{ row.pool }}</td>
            <td data-col="vendor" class="text-content-muted">{{ row.vendor }}</td>
            <td data-col="reason">
              <AppStatus
                :tone="row.reason === 'gone' ? 'critical' : 'warning'"
                :label="t(`vendors.licences.reasons.${row.reason}`)"
              />
            </td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions">
                <AppButton size="sm" variant="danger-subtle" @click="releasing = row">
                  {{ t('vendors.licences.release') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <DetailSection
        :title="t('vendors.licences.sections.spare')"
        :description="t('vendors.licences.sections.spare_detail')"
        :divided="false"
      >
        <EmptyState
          v-if="spare.length === 0"
          variant="plain"
          icon="check"
          :title="t('vendors.licences.nothing_spare')"
          :description="t('vendors.licences.sections.spare_detail')"
        />

        <ul v-else class="flex flex-wrap gap-2">
          <li
            v-for="pool in spare"
            :key="pool.id"
            class="border-line text-body text-content rounded-md border px-2 py-1"
          >
            {{ pool.name }}
            <span class="text-warning numeric ml-1 font-medium">{{ count(pool.spare) }}</span>
          </li>
        </ul>
      </DetailSection>

      <DetailSection :title="t('vendors.licences.sections.pools')" :divided="false">
        <EmptyState
          v-if="pools.length === 0"
          icon="info"
          :title="t('vendors.licences.empty')"
          :description="t('vendors.licences.empty_detail')"
        />

        <AppTable v-else name="pools" :columns="POOL_COLUMNS">
          <AppTableRow v-for="pool in pools" :key="pool.id">
            <td data-col="name">
              <span class="font-medium">{{ pool.name }}</span>
              <div v-if="pool.contract" class="text-chrome text-content-muted">
                {{ pool.contract }}
              </div>
            </td>
            <td data-col="vendor" class="text-content-muted">{{ pool.vendor }}</td>
            <!--
              Said in words rather than left blank: an empty cell reads as a
              fact that failed to load, and "nobody said" is the answer.
            -->
            <td data-col="module" class="text-content-muted">
              <span v-if="pool.forModule" class="font-mono">{{ pool.forModule }}</span>
              <span v-else class="text-content-subtle">
                {{ t('vendors.licences.for_module_none') }}
              </span>
            </td>
            <td data-col="seats" class="numeric">{{ count(pool.seats) }}</td>
            <td data-col="used" class="numeric">{{ count(pool.used) }}</td>
            <!--
              Negative is the expensive finding and is shown as itself: more
              machines holding a seat than were bought is the thing an
              operator has to see, and a figure clamped at zero would say
              everything is fine.
            -->
            <td data-col="spare" class="numeric" :class="pool.spare < 0 ? 'text-danger' : ''">
              {{ count(pool.spare) }}
            </td>
            <td data-col="total" class="numeric">{{ pool.total }}</td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions flex justify-end gap-2">
                <AppButton size="sm" variant="ghost" @click="allocatingTo = pool">
                  {{ t('vendors.licences.allocate') }}
                </AppButton>
                <AppButton size="sm" variant="danger-subtle" @click="removing = pool">
                  {{ t('vendors.delete') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <!-- Named, so the form says which licence it is about. -->
      <DetailSection
        v-if="allocatingTo && can.manage"
        :title="t('vendors.licences.allocate')"
        :description="allocatingTo.name"
      >
        <!--
          A form that cannot be submitted is worse than no form: with every
          machine already holding a seat there is nothing to choose, and an
          empty required select above an enabled button would be refused on a
          field whose list was empty.
        -->
        <EmptyState
          v-if="SERVER_OPTIONS.length === 0"
          variant="plain"
          icon="check"
          :title="t('vendors.licences.nothing_missing')"
          :description="t('vendors.licences.sections.spare_detail')"
        />

        <form v-else class="flex max-w-xl flex-col gap-4" @submit.prevent="allocate">
          <AppSelect
            v-model="allocationForm.server_id"
            :label="t('vendors.licences.server')"
            :options="SERVER_OPTIONS"
            :error="allocationForm.errors.server_id"
          />
          <AppInput
            v-model="allocationForm.reference"
            :label="t('vendors.licences.reference')"
            :hint="t('vendors.licences.reference_hint')"
            :error="allocationForm.errors.reference"
          />
          <AppTextarea v-model="allocationForm.note" :label="t('vendors.note')" :rows="2" />

          <div class="flex gap-2">
            <AppButton type="submit" variant="primary" :loading="allocationForm.processing">
              {{ t('vendors.licences.allocate_submit') }}
            </AppButton>
            <AppButton variant="ghost" @click="allocatingTo = null">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>
    </div>

    <AppConfirm
      :open="releasing !== null"
      level="consequential"
      :title="t('vendors.licences.confirm.release_title')"
      :description="t('vendors.licences.confirm.release_body', { name: releasing?.server ?? '' })"
      :confirm-label="t('vendors.licences.release')"
      @close="releasing = null"
      @confirm="release"
    />

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('vendors.licences.confirm.delete_title')"
      :description="t('vendors.licences.confirm.delete_body', { name: removing?.name ?? '' })"
      :confirm-label="t('vendors.delete')"
      @close="removing = null"
      @confirm="deletePool"
    />
  </AdminLayout>
</template>
