<script setup lang="ts">
/**
 * The organization tree: the provider, its resellers, their customers.
 *
 * Read-only, and not as a placeholder. An organization is created by
 * whatever needs one — a customer when a client is added, a reseller when
 * Phase 13 arrives. A screen that let somebody conjure one would produce a
 * node with nothing hanging off it and no way to tell what it was for.
 *
 * Indented by depth rather than nested in markup: the rows arrive already
 * in tree order, sorted by the materialised path, so drawing the shape
 * costs a padding value and no recursion.
 */
import { Head } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppTable from '../../../Components/AppTable.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface OrganizationRow {
  id: string
  name: string
  slug: string
  type: string
  typeLabel: string
  isActive: boolean
  depth: number
  children: number
  createdAt: string
}

defineProps<{ organizations: OrganizationRow[] }>()

function tone(type: string): 'neutral' | 'accent' | 'success' {
  if (type === 'provider') return 'accent'
  if (type === 'reseller') return 'success'
  return 'neutral'
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head title="Organizations" />

  <AdminLayout
    heading="Organizations"
    description="Everything this installation owns, in the shape ownership actually has. A customer is an organization of its own."
  >
    <AppTable :headers="['Name', 'Type', 'Slug', 'Below it', 'Active', 'Created']">
      <tr v-for="organization in organizations" :key="organization.id">
        <td class="px-4 py-2.5">
          <span class="font-medium" :style="{ paddingLeft: `${organization.depth * 1.25}rem` }">
            <span v-if="organization.depth > 0" class="text-content-subtle" aria-hidden="true"
              >└ </span
            >{{ organization.name }}
          </span>
        </td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="tone(organization.type)">{{ organization.typeLabel }}</AppBadge>
        </td>
        <td class="text-content-muted px-4 py-2.5 font-mono text-xs">{{ organization.slug }}</td>
        <td class="px-4 py-2.5 tabular-nums">{{ organization.children }}</td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="organization.isActive ? 'success' : 'warning'">
            {{ organization.isActive ? 'Active' : 'Inactive' }}
          </AppBadge>
        </td>
        <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
          {{ formatDate(organization.createdAt) }}
        </td>
      </tr>
    </AppTable>
  </AdminLayout>
</template>
