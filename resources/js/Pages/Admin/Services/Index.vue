<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ServiceRow {
  id: string
  name: string
  status: string
  statusLabel: string
  customer: string | null
  domain: string | null
  server: string | null
  recurring: string
  nextDueOn: string | null
  createdAt: string | null
}

const props = defineProps<{
  services: { data: ServiceRow[]; currentPage: number; lastPage: number; total: number }
  filters: { status: string | null }
  statuses: { value: string; label: string }[]
  counts: { pending: number; failed: number; suspended: number }
}>()

const active = computed(() => props.filters.status)

function filterBy(status: string | null): void {
  router.get('/admin/services', status === null ? {} : { status }, {
    preserveState: true,
    replace: true,
  })
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
</script>

<template>
  <Head title="Services" />

  <AdminLayout heading="Services" description="Everything customers are running.">
    <!--
      Three counts, because they are the three questions an operator opens
      this screen to answer: what is stuck, what broke, what is off.
    -->
    <div class="mb-6 flex flex-wrap gap-6">
      <button type="button" class="text-left" @click="filterBy('pending')">
        <p class="text-content-muted text-xs">Pending setup</p>
        <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ counts.pending }}</p>
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
      <button type="button" class="text-left" @click="filterBy('suspended')">
        <p class="text-content-muted text-xs">Suspended</p>
        <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ counts.suspended }}</p>
      </button>
    </div>

    <div class="mb-4 flex flex-wrap gap-1.5">
      <button
        type="button"
        class="pressable rounded-[var(--radius-sm)] px-2.5 py-1 text-xs transition-colors duration-(--duration-fast)"
        :class="
          active === null
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
      v-if="services.data.length > 0"
      :headers="['Service', 'Customer', 'Status', 'Server', 'Recurring', 'Next due']"
    >
      <tr v-for="service in services.data" :key="service.id">
        <td class="px-4 py-3">
          <Link
            :href="`/admin/services/${service.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ service.name }}
          </Link>
          <span v-if="service.domain" class="text-content-muted block text-xs">
            {{ service.domain }}
          </span>
        </td>
        <td class="px-4 py-3">{{ service.customer ?? '—' }}</td>
        <td class="px-4 py-3">
          <AppBadge :tone="tone(service.status)">{{ service.statusLabel }}</AppBadge>
        </td>
        <td class="text-content-muted px-4 py-3">{{ service.server ?? '—' }}</td>
        <td class="px-4 py-3 tabular-nums">{{ service.recurring }}</td>
        <td class="text-content-muted px-4 py-3 whitespace-nowrap">
          {{ formatDate(service.nextDueOn) }}
        </td>
      </tr>
    </AppTable>

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
