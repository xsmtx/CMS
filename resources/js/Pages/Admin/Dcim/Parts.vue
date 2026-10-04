<script setup lang="ts">
/**
 * Parts, and where each of them is (§11).
 *
 * **About parts, not machines**, which is the whole of it: a disk outlives
 * the machine it was first fitted to, and "where has this serial been" is
 * what a warranty claim turns on.
 *
 * **Warranty has three answers, not two.** In, out, and nobody recorded one —
 * the third is a gap in the register, and drawing it as expired would send
 * somebody to argue with a vendor who is still obliged.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
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

interface PartRow {
  id: string
  kind: string
  kindLabel: string
  model: string | null
  serial: string | null
  assetTag: string | null
  vendor: string | null
  purchasedOn: string | null
  warrantyUntil: string | null
  outOfWarranty: boolean | null
  server: string | null
  serverId: string | null
  fittedAt: string | null
}

const props = defineProps<{
  parts: {
    data: PartRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { kind: string; q: string }
  kinds: { value: string; label: string }[]
  servers: { id: string; name: string }[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

// The reader's own locale, not the operating system's.
const locale = computed(() => usePage().props.locale ?? 'en')

const COLUMNS: TableColumn[] = [
  { key: 'part', label: t('dcim.parts.columns.part') },
  { key: 'kind', label: t('dcim.parts.columns.kind') },
  { key: 'where', label: t('dcim.parts.columns.where') },
  { key: 'warranty', label: t('dcim.parts.columns.warranty') },
]

const search = ref(props.filters.q)
const adding = ref(false)

const form = useForm({
  kind: 'disk',
  model: '',
  serial: '',
  asset_tag: '',
  vendor: '',
  purchased_on: '',
  warranty_until: '',
})

function go(changes: Record<string, string>): void {
  router.get(
    '/admin/infrastructure/parts',
    { kind: props.filters.kind, q: search.value, ...changes },
    { preserveState: true, replace: true },
  )
}

function add(): void {
  form.post('/admin/infrastructure/parts', {
    preserveScroll: true,
    onSuccess: () => {
      adding.value = false
      form.reset()
    },
  })
}

function day(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleDateString(locale.value)
}

function warrantyTone(part: PartRow): 'healthy' | 'warning' | 'unknown' {
  if (part.outOfWarranty === null) return 'unknown'

  return part.outOfWarranty ? 'warning' : 'healthy'
}

function warrantyLabel(part: PartRow): string {
  if (part.outOfWarranty === null) return t('dcim.parts.no_warranty')

  return part.outOfWarranty ? t('dcim.parts.out_of_warranty') : t('dcim.parts.in_warranty')
}
</script>

<template>
  <Head :title="t('dcim.parts.title')" />

  <AdminLayout :heading="t('ui.nav.parts')" :description="t('dcim.parts.intro')">
    <template v-if="can.manage" #actions>
      <AppButton variant="secondary" @click="adding = !adding">
        {{ t('dcim.parts.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection v-if="adding" :title="t('dcim.parts.add')">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="add">
          <AppSelect
            v-model="form.kind"
            :label="t('dcim.parts.fields.kind')"
            :options="kinds"
            :error="form.errors.kind"
          />
          <AppInput
            v-model="form.serial"
            :label="t('dcim.parts.fields.serial')"
            :error="form.errors.serial"
          />
          <AppInput
            v-model="form.model"
            :label="t('dcim.parts.fields.model')"
            :error="form.errors.model"
          />
          <AppInput
            v-model="form.vendor"
            :label="t('dcim.parts.fields.vendor')"
            :error="form.errors.vendor"
          />
          <AppInput
            v-model="form.warranty_until"
            type="date"
            :label="t('dcim.parts.fields.warranty_until')"
            :error="form.errors.warranty_until"
          />
          <div>
            <AppButton type="submit" :loading="form.processing">
              {{ t('dcim.parts.fields.save') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <FilterBar>
        <SearchInput
          v-model="search"
          :label="t('dcim.parts.search')"
          @update:model-value="go({})"
        />
        <!--
          The "any" choice is the first option with an empty value — the
          component has no prop for it, and reaching for one puts an
          attribute on a `<label>` and leaves the filter blank.
        -->
        <FilterSelect
          :model-value="filters.kind"
          :label="t('dcim.parts.columns.kind')"
          :options="[{ value: '', label: t('dcim.parts.any_kind') }, ...kinds]"
          @update:model-value="(kind: string) => go({ kind })"
        />
      </FilterBar>

      <EmptyState
        v-if="parts.data.length === 0"
        icon="servers"
        :title="t('dcim.parts.empty')"
        :description="t('dcim.parts.empty_detail')"
        boxed
      />

      <AppTable v-else name="hardware-parts" :columns="COLUMNS">
        <AppTableRow v-for="part in parts.data" :key="part.id">
          <td data-col="part">
            <Link
              :href="`/admin/infrastructure/parts/${part.id}`"
              class="text-brand font-mono font-medium hover:underline"
            >
              {{ part.serial ?? part.assetTag ?? part.model }}
            </Link>
            <span v-if="part.vendor || part.model" class="text-content-muted text-chrome block">
              {{ [part.vendor, part.model].filter(Boolean).join(' ') }}
            </span>
          </td>
          <td data-col="kind">{{ part.kindLabel }}</td>
          <td data-col="where">
            <span v-if="part.server" class="text-body">{{ part.server }}</span>
            <span v-else class="text-content-subtle">{{ t('dcim.parts.on_the_shelf') }}</span>
          </td>
          <td data-col="warranty">
            <AppStatus :tone="warrantyTone(part)" :label="warrantyLabel(part)" />
            <span
              v-if="part.warrantyUntil"
              class="text-content-subtle text-chrome block tabular-nums"
            >
              {{ day(part.warrantyUntil) }}
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="parts.links" :total="parts.total" />
    </div>
  </AdminLayout>
</template>
