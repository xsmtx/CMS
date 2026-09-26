<script setup lang="ts">
/**
 * Every network this installation has, and how full each one is.
 *
 * The question an operator arrives with is "where is there room", so fullness is
 * the column the eye lands on and everything else is context. A pool is a filter
 * rather than a screen of its own: nobody opens a list of pools to read it, they
 * open it to narrow this one.
 *
 * **IPv6 has no percentage on purpose.** A /64's capacity is larger than any
 * number a browser should print, and a bar reading 0.0000000001% is a bar that
 * says nothing while looking like it says something — so those rows carry a count
 * and the words "too large to count".
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, nextTick, reactive, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import FilterBar from '../../../Components/FilterBar.vue'
import FilterSelect from '../../../Components/FilterSelect.vue'
import SearchInput from '../../../Components/SearchInput.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface Option {
  value: string
  label: string
}

interface PrefixRow {
  id: string
  cidr: string
  family: string
  familyLabel: string
  pool: string | null
  poolId: string
  purpose: string | null
  purposeLabel: string | null
  vlan: string | null
  site: string | null
  used: number
  reserved: number
  /** Null when the network is larger than a number worth printing. */
  capacity: number | null
  utilisation: number | null
}

interface PoolRow {
  id: string
  name: string
  family: string
  familyLabel: string
  purpose: string
  purposeLabel: string
}

const props = defineProps<{
  prefixes: {
    data: PrefixRow[]
    currentPage: number
    lastPage: number
    total: number
    links: PaginationLink[]
  }
  pools: PoolRow[]
  vlans: Option[]
  filters: { pool: string | null; q: string | null }
  families: Option[]
  purposes: Option[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const addingPool = ref(false)
const addingPrefix = ref(false)

const poolForm = useForm({
  name: '',
  family: 'v4',
  purpose: 'customer',
  note: '',
})

const prefixForm = useForm({
  ip_pool_id: props.pools[0]?.id ?? '',
  cidr: '',
  gateway: '',
  vlan_id: '',
  site: '',
  note: '',
})

const COLUMNS: TableColumn[] = [
  { key: 'cidr', label: t('network.prefixes.cidr') },
  { key: 'pool', label: t('network.prefixes.pool') },
  { key: 'vlan', label: t('network.prefixes.vlan'), optional: true },
  { key: 'site', label: t('network.prefixes.site'), optional: true },
  { key: 'used', label: t('network.prefixes.used'), numeric: true },
  { key: 'full', label: t('network.prefixes.utilisation'), numeric: true },
]

const poolOptions = computed<Option[]>(() =>
  props.pools.map((pool) => ({ value: pool.id, label: `${pool.name} · ${pool.familyLabel}` })),
)

/** Mirrored to the URL, so a filtered list is a link somebody can send. */
const filters = reactive({
  q: props.filters.q ?? '',
  pool: props.filters.pool ?? '',
})

function submit(): void {
  const query: Record<string, string> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value !== '') query[key] = value
  }

  router.get('/admin/network/addressing', query, { preserveState: true, replace: true })
}

/** A select applies on change; the model is written after this tick. */
function applySoon(): void {
  void nextTick(submit)
}

function withBlank(options: Option[]): Option[] {
  return [{ value: '', label: t('ui.common.any') }, ...options]
}

/**
 * How full, as a percentage — or the honest refusal.
 */
function fullness(row: PrefixRow): string {
  if (row.utilisation === null) return t('network.prefixes.too_large')

  return `${(row.utilisation * 100).toFixed(1)}%`
}

function submitPool(): void {
  poolForm.post('/admin/network/pools', {
    preserveScroll: true,
    onSuccess: () => {
      poolForm.reset()
      addingPool.value = false
    },
  })
}

function submitPrefix(): void {
  prefixForm.post('/admin/network/prefixes', {
    preserveScroll: true,
    onSuccess: () => {
      prefixForm.reset('cidr', 'gateway', 'site', 'note')
      addingPrefix.value = false
    },
  })
}
</script>

<template>
  <Head :title="t('network.title')" />

  <AdminLayout :heading="t('network.title')" :description="t('network.intro')">
    <template #actions>
      <AppButton v-if="can.manage" variant="secondary" icon="add" @click="addingPool = !addingPool">
        {{ t('network.pools.add') }}
      </AppButton>
      <AppButton
        v-if="can.manage && pools.length > 0"
        variant="primary"
        icon="add"
        @click="addingPrefix = !addingPrefix"
      >
        {{ t('network.prefixes.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        A pool has to exist before a network can, so when there is none the
        screen says that rather than offering a form whose only select is
        empty. A form that cannot be submitted is worse than no form.
      -->
      <EmptyState
        v-if="pools.length === 0"
        :title="t('network.pools.title')"
        :description="t('network.pools.empty')"
        icon="servers"
        boxed
      />

      <DetailSection
        v-if="addingPool && can.manage"
        :title="t('network.pools.add')"
        :description="t('network.pools.intro')"
      >
        <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submitPool">
          <AppInput
            v-model="poolForm.name"
            :label="t('network.pools.name')"
            :error="poolForm.errors.name"
          />
          <AppSelect
            v-model="poolForm.family"
            :label="t('network.pools.family')"
            :options="families"
            :error="poolForm.errors.family"
          />
          <AppSelect
            v-model="poolForm.purpose"
            :label="t('network.pools.purpose')"
            :options="purposes"
            :error="poolForm.errors.purpose"
          />
          <div class="md:col-span-2">
            <AppButton type="submit" variant="primary" :loading="poolForm.processing">
              {{ t('network.pools.add') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <DetailSection v-if="addingPrefix && can.manage" :title="t('network.prefixes.add')">
        <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submitPrefix">
          <AppSelect
            v-model="prefixForm.ip_pool_id"
            :label="t('network.prefixes.pool')"
            :options="poolOptions"
            :error="prefixForm.errors.ip_pool_id"
          />
          <AppInput
            v-model="prefixForm.cidr"
            :label="t('network.prefixes.cidr')"
            placeholder="192.0.2.0/24"
            :error="prefixForm.errors.cidr"
          />
          <AppInput
            v-model="prefixForm.gateway"
            :label="t('network.prefixes.gateway')"
            :error="prefixForm.errors.gateway"
          />
          <AppSelect
            v-if="vlans.length > 0"
            v-model="prefixForm.vlan_id"
            :label="t('network.prefixes.vlan')"
            :options="vlans"
            :error="prefixForm.errors.vlan_id"
          />
          <AppInput
            v-model="prefixForm.site"
            :label="t('network.prefixes.site')"
            :error="prefixForm.errors.site"
          />
          <AppTextarea
            v-model="prefixForm.note"
            :label="t('network.prefixes.note')"
            :rows="2"
            :error="prefixForm.errors.note"
          />
          <div class="md:col-span-2">
            <AppButton type="submit" variant="primary" :loading="prefixForm.processing">
              {{ t('network.prefixes.add') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <div v-if="pools.length > 0" class="flex flex-col gap-4">
        <form @submit.prevent="submit">
          <FilterBar>
            <SearchInput
              v-model="filters.q"
              :label="t('network.prefixes.search')"
              placeholder="192.0.2."
            />
            <FilterSelect
              v-model="filters.pool"
              :label="t('network.prefixes.pool')"
              :options="withBlank(poolOptions)"
              @update:model-value="applySoon"
            />
          </FilterBar>
        </form>

        <EmptyState
          v-if="prefixes.data.length === 0"
          :title="t('network.prefixes.title')"
          :description="
            filters.q !== '' || filters.pool !== ''
              ? t('network.prefixes.empty_filtered')
              : t('network.prefixes.empty')
          "
          icon="servers"
          boxed
        />

        <template v-else>
          <AppTable name="prefixes" :columns="COLUMNS">
            <AppTableRow v-for="row in prefixes.data" :key="row.id">
              <!--
                The link is in the identity cell, not on the row.
                `AppTableRow` has no `href` prop, so one passed to it fell
                through as an attribute on a `<tr>` and did nothing — which
                made the prefix screen a page nothing in the product linked
                to. A `<tr>` cannot be wrapped in an anchor, which is why the
                convention here is the first cell.
              -->
              <td data-col="cidr">
                <Link
                  :href="`/admin/network/addressing/${row.id}`"
                  class="text-brand font-mono font-medium hover:underline"
                >
                  {{ row.cidr }}
                </Link>
                <span class="text-content-subtle text-chrome block">{{ row.familyLabel }}</span>
              </td>
              <td data-col="pool">
                {{ row.pool }}
                <AppBadge v-if="row.purposeLabel" tone="neutral">{{ row.purposeLabel }}</AppBadge>
              </td>
              <td data-col="vlan" class="text-content-muted">{{ row.vlan ?? '—' }}</td>
              <td data-col="site" class="text-content-muted">{{ row.site ?? '—' }}</td>
              <td data-col="used" class="numeric tabular-nums">
                {{ row.used }}
                <span v-if="row.capacity !== null" class="text-content-subtle">
                  / {{ row.capacity }}
                </span>
              </td>
              <td data-col="full" class="numeric tabular-nums">{{ fullness(row) }}</td>
            </AppTableRow>
          </AppTable>

          <AppPagination :links="prefixes.links" :total="prefixes.total" />
        </template>
      </div>
    </div>
  </AdminLayout>
</template>
