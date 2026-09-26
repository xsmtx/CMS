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
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
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

interface GrantRow {
  id: string
  holder: string | null
  granter: string | null
  capability: string
  capabilityLabel: string
  reason: string
  ticket: string | null
  expiresAt: string
}

interface Option {
  value: string
  label: string
}

const props = defineProps<{
  brandName: string
  platform: { version: string; entitlements: { key: string; label: string; allowed: boolean }[] }
  servers: ServerRow[]
  grants: GrantRow[]
  grantable: Option[]
  staff: Option[]
  can: { grant: boolean }
}>()

const { t } = useTranslations()

const granting = ref(false)
const ending = ref<GrantRow | null>(null)

const grantForm = useForm({
  staff: props.staff[0]?.value ?? '',
  capability: props.grantable[0]?.value ?? '',
  minutes: 120,
  reason: '',
  ticket: '',
})

const GRANT_COLUMNS: TableColumn[] = [
  { key: 'holder', label: t('network.access.holder') },
  { key: 'capability', label: t('network.access.capability') },
  { key: 'reason', label: t('network.access.reason') },
  { key: 'granter', label: t('network.access.granter') },
  { key: 'expires', label: t('network.access.expires') },
  { key: 'actions', label: '' },
]

function submitGrant(): void {
  grantForm.post('/admin/apps/connect/grants', {
    preserveScroll: true,
    onSuccess: () => {
      grantForm.reset('reason', 'ticket')
      granting.value = false
    },
  })
}

function endGrant(): void {
  const grant = ending.value

  if (grant === null) return

  router.delete(`/admin/apps/connect/grants/${grant.id}`, {
    preserveScroll: true,
    onFinish: () => (ending.value = null),
  })
}

/** An instant the server sent, read where the operator is. */
function when(value: string): string {
  return new Date(value).toLocaleString()
}

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

      <!--
        Just-in-time access, on the screen it extends. Connect is already the
        answer to "somebody needs into a panel without being handed a root
        password"; this is the answer to "and they do not hold the permission".
      -->
      <DetailSection :title="t('network.access.title')" :description="t('network.access.intro')">
        <template #actions>
          <AppButton
            v-if="can.grant && staff.length > 0"
            variant="secondary"
            icon="add"
            @click="granting = !granting"
          >
            {{ t('network.access.grant') }}
          </AppButton>
        </template>

        <form
          v-if="granting && can.grant"
          class="border-line mb-6 flex flex-col gap-4 border-b pb-6"
          @submit.prevent="submitGrant"
        >
          <p class="text-content-muted text-chrome max-w-[74ch]">
            {{ t('network.access.grant_intro') }}
          </p>

          <div class="grid gap-4 md:grid-cols-3">
            <AppSelect
              v-model="grantForm.staff"
              :label="t('network.access.holder')"
              :options="staff"
              :error="grantForm.errors.staff"
            />
            <AppSelect
              v-model="grantForm.capability"
              :label="t('network.access.capability')"
              :options="grantable"
              :error="grantForm.errors.capability"
            />
            <AppInput
              v-model.number="grantForm.minutes"
              type="number"
              :label="t('network.access.minutes')"
              :hint="t('network.access.minutes_hint')"
              :error="grantForm.errors.minutes"
            />
          </div>

          <AppTextarea
            v-model="grantForm.reason"
            :label="t('network.access.reason')"
            :rows="2"
            :error="grantForm.errors.reason"
          />

          <AppInput
            v-model="grantForm.ticket"
            :label="t('network.access.ticket')"
            :error="grantForm.errors.ticket"
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="grantForm.processing">
              {{ t('network.access.grant') }}
            </AppButton>
          </div>
        </form>

        <AppTable v-if="grants.length > 0" name="access-grants" :columns="GRANT_COLUMNS">
          <AppTableRow v-for="grant in grants" :key="grant.id">
            <td data-col="holder" class="font-medium">{{ grant.holder ?? '—' }}</td>
            <td data-col="capability">{{ grant.capabilityLabel }}</td>
            <td data-col="reason" class="text-content-muted max-w-[32rem] truncate">
              {{ grant.reason }}
              <span v-if="grant.ticket" class="text-content-subtle">· {{ grant.ticket }}</span>
            </td>
            <td data-col="granter" class="text-content-muted">{{ grant.granter ?? '—' }}</td>
            <td data-col="expires" class="text-content-muted text-chrome tabular-nums">
              {{ when(grant.expiresAt) }}
            </td>
            <td data-col="actions" class="text-right whitespace-nowrap">
              <span class="row-actions">
                <AppButton
                  v-if="can.grant"
                  size="sm"
                  variant="danger-subtle"
                  @click="ending = grant"
                >
                  {{ t('network.access.revoke') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <p v-else class="text-content-muted text-body">{{ t('network.access.empty') }}</p>
      </DetailSection>
    </div>

    <AppConfirm
      :open="ending !== null"
      level="consequential"
      :title="t('network.access.revoke_title')"
      :description="t('network.access.revoke_body')"
      :confirm-label="t('network.access.revoke')"
      @close="ending = null"
      @confirm="endGrant"
    />
  </AdminLayout>
</template>
