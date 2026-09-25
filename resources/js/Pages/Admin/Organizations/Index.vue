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
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'name', label: t('organizations.tree.name'), sticky: true },
  { key: 'type', label: t('organizations.tree.type') },
  { key: 'slug', label: t('organizations.tree.slug'), optional: true },
  { key: 'below', label: t('organizations.tree.below'), numeric: true },
  { key: 'active', label: t('organizations.tree.active') },
  { key: 'created', label: t('organizations.tree.created') },
]

function tone(type: string): 'neutral' | 'brand' | 'success' {
  if (type === 'provider') return 'brand'
  if (type === 'reseller') return 'success'
  return 'neutral'
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('organizations.tree.title')" />

  <AdminLayout
    :heading="t('organizations.tree.title')"
    :description="t('organizations.tree.subtitle')"
  >
    <AppTable name="admin-organizations" :columns="COLUMNS">
      <AppTableRow v-for="organization in organizations" :key="organization.id">
        <td data-col="name">
          <span class="font-medium" :style="{ paddingLeft: `${organization.depth * 1.25}rem` }">
            <span v-if="organization.depth > 0" class="text-content-subtle" aria-hidden="true"
              >└ </span
            >{{ organization.name }}
          </span>
        </td>
        <td data-col="type">
          <AppBadge :tone="tone(organization.type)">{{ organization.typeLabel }}</AppBadge>
        </td>
        <td data-col="slug" class="text-content-muted text-chrome font-mono">
          {{ organization.slug }}
        </td>
        <td data-col="below" class="tabular-nums">{{ organization.children }}</td>
        <td data-col="active">
          <!-- A status, so it carries a shape: a green chip and an amber one
               are the same chip to a reader who cannot tell them apart. -->
          <AppStatus
            :tone="organization.isActive ? 'healthy' : 'warning'"
            :label="
              organization.isActive
                ? t('organizations.tree.is_active')
                : t('organizations.tree.is_inactive')
            "
          />
        </td>
        <td data-col="created" class="text-content-muted whitespace-nowrap">
          {{ formatDate(organization.createdAt) }}
        </td>
      </AppTableRow>
    </AppTable>
  </AdminLayout>
</template>
