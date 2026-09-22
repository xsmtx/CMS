<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface CustomerRow {
  id: string
  name: string
  status: string
  primaryContact: string | null
  tags: string[]
  createdAt: string
}

const props = defineProps<{
  customers: {
    data: CustomerRow[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
  }
  filters: { search: string; status: string }
  statuses: { value: string; label: string }[]
  can: { create: boolean }
}>()

const search = ref(props.filters.search)
const status = ref(props.filters.status)

let timeout: ReturnType<typeof setTimeout> | undefined

function reload(): void {
  router.get(
    '/admin/customers',
    { search: search.value, status: status.value },
    { preserveState: true, replace: true },
  )
}

watch(search, () => {
  clearTimeout(timeout)
  timeout = setTimeout(reload, 300)
})

watch(status, reload)

function tone(value: string): 'success' | 'warning' | 'danger' | 'neutral' {
  if (value === 'active') return 'success'
  if (value === 'suspended') return 'warning'
  if (value === 'closed') return 'danger'
  return 'neutral'
}
</script>

<template>
  <Head title="Customers" />

  <AdminLayout heading="Customers" description="Every account you or your resellers sell to.">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
      <div class="flex flex-wrap items-end gap-3">
        <div class="w-full max-w-xs">
          <AppInput v-model="search" label="Search" placeholder="Company, tax id or contact" />
        </div>
        <div class="w-40">
          <AppSelect
            v-model="status"
            label="Status"
            :options="[{ value: '', label: 'Any status' }, ...statuses]"
          />
        </div>
      </div>

      <AppButton v-if="can.create" href="/admin/customers/create" variant="primary">
        Add customer
      </AppButton>
    </div>

    <EmptyState
      v-if="customers.data.length === 0"
      title="No customers match this search"
      description="Customers appear here once they are created in admin or sign up through the storefront."
    />

    <template v-else>
      <AppTable :headers="['Customer', 'Primary contact', 'Tags', 'Status', '']">
        <tr v-for="customer in customers.data" :key="customer.id">
          <td class="px-4 py-3">
            <Link :href="`/admin/customers/${customer.id}`" class="font-medium hover:underline">
              {{ customer.name }}
            </Link>
          </td>
          <td class="text-content-muted px-4 py-3">{{ customer.primaryContact ?? 'None' }}</td>
          <td class="px-4 py-3">
            <span v-if="customer.tags.length === 0" class="text-content-subtle text-xs">None</span>
            <AppBadge v-for="tag in customer.tags" :key="tag" class="mr-1">{{ tag }}</AppBadge>
          </td>
          <td class="px-4 py-3">
            <AppBadge :tone="tone(customer.status)">{{ customer.status }}</AppBadge>
          </td>
          <td class="px-4 py-3 text-right">
            <Link
              :href="`/admin/customers/${customer.id}`"
              class="text-content-muted hover:text-content text-xs underline underline-offset-4"
            >
              Open
            </Link>
          </td>
        </tr>
      </AppTable>

      <AppPagination :links="customers.links" :total="customers.total" />
    </template>
  </AdminLayout>
</template>
