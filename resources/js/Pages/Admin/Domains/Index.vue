<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface DomainRow {
  id: string
  name: string
  status: string
  statusLabel: string
  customer: string | null
  expiresOn: string | null
  daysUntilExpiry: number | null
  renewal: string
}

const props = defineProps<{
  domains: { data: DomainRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null; expiring: boolean }
  statuses: { value: string; label: string }[]
  counts: { expiring: number; failed: number; pending: number }
}>()

const active = computed(() => props.filters.status)

function filterBy(status: string | null): void {
  router.get('/admin/domains', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
  })
}

function showExpiring(): void {
  router.get('/admin/domains', { expiring: 1 }, { preserveState: true, replace: true })
}

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  if (status === 'failed' || status === 'deleted' || status === 'redemption') return 'danger'
  if (status === 'expired' || status === 'pending' || status === 'registering') return 'warning'
  return 'neutral'
}
</script>

<template>
  <Head title="Domains" />

  <AdminLayout heading="Domains" description="Every name this installation holds.">
    <div class="mb-6 flex flex-wrap gap-6">
      <button type="button" class="text-left" @click="showExpiring">
        <p class="text-content-muted text-xs">Expiring within 45 days</p>
        <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ counts.expiring }}</p>
      </button>
      <button type="button" class="text-left" @click="filterBy('failed')">
        <p class="text-content-muted text-xs">Failed</p>
        <p
          class="mt-0.5 text-xl font-semibold tabular-nums"
          :class="counts.failed > 0 ? 'text-danger' : ''"
        >
          {{ counts.failed }}
        </p>
      </button>
      <button type="button" class="text-left" @click="filterBy('pending')">
        <p class="text-content-muted text-xs">Pending</p>
        <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ counts.pending }}</p>
      </button>
    </div>

    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          active === null && !filters.expiring
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
        "
        @click="filterBy(null)"
      >
        All
      </button>
      <button
        v-for="status in statuses"
        :key="status.value"
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          active === status.value
            ? 'bg-surface-sunken text-content font-medium'
            : 'text-content-muted hover:bg-surface-sunken'
        "
        @click="filterBy(status.value)"
      >
        {{ status.label }}
      </button>
    </div>

    <AppTable
      v-if="domains.data.length > 0"
      :headers="['Domain', 'Customer', 'Status', 'Expires', 'Renewal']"
    >
      <tr v-for="domain in domains.data" :key="domain.id">
        <td class="px-4 py-3 font-medium">
          <Link :href="`/admin/domains/${domain.id}`" class="underline-offset-4 hover:underline">
            {{ domain.name }}
          </Link>
        </td>
        <td class="px-4 py-3">{{ domain.customer ?? '—' }}</td>
        <td class="px-4 py-3">
          <AppBadge :tone="tone(domain.status)">{{ domain.statusLabel }}</AppBadge>
        </td>
        <td class="px-4 py-3 whitespace-nowrap">
          {{ domain.expiresOn ?? '—' }}
          <span
            v-if="domain.daysUntilExpiry !== null && domain.daysUntilExpiry < 45"
            class="block text-xs"
            :class="domain.daysUntilExpiry < 0 ? 'text-danger' : 'text-content-muted'"
          >
            {{ domain.daysUntilExpiry }} days
          </span>
        </td>
        <td class="px-4 py-3 tabular-nums">{{ domain.renewal }}</td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No domains yet"
      description="A domain appears here as soon as an order that includes one is paid for."
    />

    <p v-if="domains.lastPage > 1" class="text-content-muted mt-4 text-xs">
      Page {{ domains.currentPage }} of {{ domains.lastPage }} — {{ domains.total }} domains
    </p>
  </AdminLayout>
</template>
