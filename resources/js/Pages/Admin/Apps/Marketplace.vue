<script setup lang="ts">
/**
 * The vendor's catalogue.
 *
 * One list, not two. "Available", "Installed" and "A newer version exists" are
 * three states of the same row, because an operator comparing two lists by eye
 * is an operator who installs the thing they already have.
 *
 * The sentence about what installing does is on the page rather than only in an
 * ADR. Somebody pressing a button called Install on a page called Marketplace
 * has every reason to assume the thing then runs, and it does not — enabling is
 * a separate, deliberate act on a different screen.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'

interface Package {
  slug: string
  name: string
  type: string
  version: string
  summary: string
  provider: string
  sizeBytes: number
  infoUrl: string | null
  dependencies: string[]
  installed: boolean
  installedVersion: string | null
  state: string | null
  ours: boolean
  outdated: boolean
}

const props = defineProps<{
  packages: Package[]
  state: { modulesEnabled: boolean; configured: boolean; signable: boolean }
}>()

const { t } = useTranslations()

const form = useForm({ slug: '' })

function install(slug: string): void {
  form.slug = slug
  form.post('/admin/apps/marketplace', { preserveScroll: true })
}

const columns: TableColumn[] = [
  { key: 'package', label: t('marketplace.columns.package') },
  { key: 'kind', label: t('marketplace.columns.kind'), optional: true },
  { key: 'version', label: t('marketplace.columns.version') },
  { key: 'size', label: t('marketplace.columns.size'), numeric: true, optional: true },
  { key: 'state', label: t('marketplace.columns.state') },
  { key: 'actions', label: '' },
]

/** Megabytes to one decimal place, because a package is never bytes to a human. */
function size(bytes: number): string {
  if (bytes <= 0) return '—'

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/**
 * Derived from flags rather than a status word, so the tone is chosen here —
 * by the same meanings as `status.ts`: not installed is out of play, a
 * package somebody else installed or a stale one wants a look, a current
 * one of ours is healthy.
 */
function stateOf(item: Package): { label: string; tone: StatusTone } {
  if (!item.installed) return { label: t('marketplace.states.available'), tone: 'neutral' }
  if (!item.ours) return { label: t('marketplace.states.foreign'), tone: 'warning' }
  if (item.outdated) return { label: t('marketplace.states.outdated'), tone: 'warning' }

  return { label: t('marketplace.states.installed'), tone: 'healthy' }
}

const blocked = computed(() => !props.state.modulesEnabled)
</script>

<template>
  <Head :title="t('marketplace.title')" />

  <AdminLayout :heading="t('marketplace.title')" :description="t('marketplace.intro')">
    <div class="flex flex-col gap-4">
      <AppAlert v-if="form.errors.slug" tone="danger">{{ form.errors.slug }}</AppAlert>

      <!-- What installing actually does. On the page, not only in an ADR. -->
      <AppAlert tone="info">{{ t('marketplace.how_it_works') }}</AppAlert>

      <AppAlert v-if="!state.signable" tone="warning">
        {{ t('marketplace.unsigned.description') }}
      </AppAlert>

      <EmptyState
        v-if="blocked"
        :title="t('marketplace.disabled.title')"
        :description="t('marketplace.disabled.description')"
        icon="modules"
      />

      <EmptyState
        v-else-if="!state.configured"
        :title="t('marketplace.unconfigured.title')"
        :description="t('marketplace.unconfigured.description')"
        icon="modules"
      />

      <EmptyState
        v-else-if="packages.length === 0"
        :title="t('marketplace.empty.title')"
        :description="t('marketplace.empty.description')"
        icon="modules"
      />

      <AppTable v-else name="marketplace" :columns="columns">
        <AppTableRow v-for="item in packages" :key="item.slug">
          <td data-col="package" class="px-4 py-3">
            <span class="font-medium">{{ item.name }}</span>
            <span class="text-content-muted text-chrome mt-0.5 block">{{ item.summary }}</span>
            <span v-if="item.provider" class="text-content-subtle text-chrome mt-0.5 block">
              {{ t('marketplace.by', { provider: item.provider }) }}
            </span>
            <span
              v-if="item.dependencies.length > 0"
              class="text-content-subtle text-chrome mt-0.5 block"
            >
              {{ t('marketplace.needs', { packages: item.dependencies.join(', ') }) }}
            </span>
          </td>
          <td data-col="kind" class="text-content-muted text-chrome px-4 py-3">{{ item.type }}</td>
          <td data-col="version" class="text-chrome px-4 py-3 font-mono">
            {{ item.version }}
            <span v-if="item.installedVersion && item.outdated" class="text-content-muted block">
              {{ item.installedVersion }}
            </span>
          </td>
          <td data-col="size" class="numeric px-4 py-3 tabular-nums">{{ size(item.sizeBytes) }}</td>
          <td data-col="state" class="px-4 py-3">
            <AppStatus :tone="stateOf(item).tone" :label="stateOf(item).label" />
          </td>
          <td data-col="actions" class="px-4 py-3">
            <div class="flex items-center justify-end gap-2">
              <AppButton v-if="item.infoUrl" size="sm" variant="ghost" :href="item.infoUrl">
                {{ t('marketplace.read_more') }}
              </AppButton>
              <AppButton
                v-if="!item.installed"
                size="sm"
                variant="primary"
                :loading="form.processing && form.slug === item.slug"
                @click="install(item.slug)"
              >
                {{ t('marketplace.install') }}
              </AppButton>
            </div>
          </td>
        </AppTableRow>
      </AppTable>

      <p v-if="packages.some((item) => !item.ours)" class="text-content-muted text-chrome">
        {{ t('marketplace.foreign_note') }}
      </p>
    </div>
  </AdminLayout>
</template>
