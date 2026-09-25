<script setup lang="ts">
/**
 * Connect: what this installation is joined to, and a way in.
 *
 * The button here does not reveal a password, and there is nowhere on this
 * page that one could appear. The platform asks the panel to issue a
 * short-lived session with the API token it already holds for
 * provisioning: the token never reaches this browser, the session expires,
 * and every one is audited against the operator who asked for it.
 *
 * A server whose panel cannot do that says so rather than offering a button
 * that fails.
 */
import { Head, router } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface ServerRow {
  id: string
  name: string
  hostname: string
  ipAddress: string | null
  group: string | null
  module: string | null
  status: string
  statusLabel: string
  canOpenSession: boolean
  hasSecret: boolean
}

const props = defineProps<{
  brandName: string
  platform: { version: string; entitlements: { key: string; label: string; allowed: boolean }[] }
  servers: ServerRow[]
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'server', label: t('provisioning.connect.columns.server') },
  { key: 'hostname', label: t('provisioning.connect.columns.hostname') },
  { key: 'group', label: t('provisioning.connect.columns.group') },
  { key: 'module', label: t('provisioning.connect.columns.module') },
  { key: 'status', label: t('provisioning.connect.columns.status') },
  { key: 'actions', label: '' },
]

/**
 * The version, then one row per feature. The state is given through a slot
 * named after the feature's own key, so a licence that gains a feature
 * gains a row without this page knowing the word for it.
 */
const facts: DescriptionItem[] = [
  { key: 'version', label: t('provisioning.connect.platform_version'), mono: true },
  ...props.platform.entitlements.map((entitlement) => ({
    key: entitlement.key,
    label: entitlement.label,
  })),
]

function openSession(server: ServerRow): void {
  router.post(`/admin/apps/connect/servers/${server.id}/session`)
}
</script>

<template>
  <Head :title="`${brandName} Connect`" />

  <AdminLayout :heading="`${brandName} Connect`" :description="t('provisioning.connect.intro')">
    <div class="flex flex-col gap-8">
      <DetailSection
        :title="t('provisioning.connect.installation')"
        :description="t('provisioning.connect.installation_hint')"
      >
        <DescriptionList :items="facts">
          <template #version>{{ platform.version }}</template>

          <template
            v-for="entitlement in platform.entitlements"
            :key="entitlement.key"
            #[entitlement.key]
          >
            <AppStatus
              :tone="entitlement.allowed ? 'healthy' : 'neutral'"
              :label="
                entitlement.allowed
                  ? t('provisioning.connect.allowed')
                  : t('provisioning.connect.not_allowed')
              "
            />
          </template>
        </DescriptionList>
      </DetailSection>

      <AppTable v-if="servers.length > 0" name="connect" :columns="COLUMNS">
        <AppTableRow v-for="server in servers" :key="server.id">
          <td data-col="server" class="font-medium">{{ server.name }}</td>
          <td data-col="hostname" class="text-content-muted">
            {{ server.hostname }}
            <span v-if="server.ipAddress" class="text-chrome block font-mono">
              {{ server.ipAddress }}
            </span>
          </td>
          <td data-col="group" class="text-content-muted">{{ server.group ?? '—' }}</td>
          <td data-col="module" class="text-content-muted">{{ server.module ?? '—' }}</td>
          <td data-col="status">
            <AppStatus :tone="statusTone(server.status)" :label="server.statusLabel" />
          </td>
          <td data-col="actions" class="text-right">
            <AppButton v-if="server.canOpenSession" size="sm" @click="openSession(server)">
              {{ t('provisioning.connect.open_panel') }}
            </AppButton>
            <span v-else-if="!server.hasSecret" class="text-content-muted text-chrome">
              {{ t('provisioning.connect.no_credential') }}
            </span>
            <span v-else class="text-content-muted text-chrome">
              {{ t('provisioning.connect.cannot_issue') }}
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <EmptyState
        v-else
        icon="servers"
        :title="t('provisioning.connect.empty')"
        :description="t('provisioning.connect.empty_description')"
      />

      <p class="text-content-muted text-chrome max-w-[74ch] leading-relaxed">
        {{ t('provisioning.connect.footnote') }}
      </p>
    </div>
  </AdminLayout>
</template>
