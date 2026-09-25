<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface GroupRow {
  id: string
  name: string
  strategy: string
  strategyLabel: string
  region: string | null
  servers: number
  notes: string | null
}

interface ServerRow {
  id: string
  name: string
  group: string | null
  groupId: string | null
  module: string
  hostname: string
  ipAddress: string | null
  port: number
  secure: boolean
  username: string
  hasSecret: boolean
  status: string
  statusLabel: string
  region: string | null
  maxServices: number
  weight: number
  nameservers: string | null
  services: number
  health: string
  healthLabel: string
  healthMessage: string | null
  healthCheckedAt: string | null
}

const props = defineProps<{
  groups: GroupRow[]
  servers: ServerRow[]
  options: {
    modules: { value: string; label: string; needsServer: boolean }[]
    strategies: { value: string; label: string }[]
    statuses: { value: string; label: string }[]
  }
  can: { manage: boolean }
}>()

const editingGroup = ref<string | null>(null)
const editingServer = ref<string | null>(null)

const groupForm = useForm({
  name: '',
  placement_strategy: props.options.strategies[0]?.value ?? 'least_accounts',
  region: '',
  notes: '',
})

const serverForm = useForm({
  name: '',
  server_group_id: '',
  module: props.options.modules[0]?.value ?? 'manual',
  hostname: '',
  ip_address: '',
  port: 2087,
  secure: true,
  username: 'root',
  secret: '',
  status: 'active',
  region: '',
  max_services: 0,
  weight: 1,
  nameservers: '',
})

function editGroup(group: GroupRow): void {
  editingGroup.value = group.id
  groupForm.name = group.name
  groupForm.placement_strategy = group.strategy
  groupForm.region = group.region ?? ''
  groupForm.notes = group.notes ?? ''
}

function saveGroup(): void {
  if (editingGroup.value === null) {
    groupForm.post('/admin/apps/infrastructure/groups', {
      preserveScroll: true,
      onSuccess: resetGroup,
    })
    return
  }

  groupForm.put(`/admin/apps/infrastructure/groups/${editingGroup.value}`, {
    preserveScroll: true,
    onSuccess: resetGroup,
  })
}

function resetGroup(): void {
  editingGroup.value = null
  groupForm.reset()
}

function editServer(server: ServerRow): void {
  editingServer.value = server.id
  serverForm.name = server.name
  serverForm.server_group_id = server.groupId ?? ''
  serverForm.module = server.module
  serverForm.hostname = server.hostname
  serverForm.ip_address = server.ipAddress ?? ''
  serverForm.port = server.port
  serverForm.secure = server.secure
  serverForm.username = server.username
  // Never sent back to the browser, so never pre-filled. An operator who
  // needs to change it types a new one.
  serverForm.secret = ''
  serverForm.status = server.status
  serverForm.region = server.region ?? ''
  serverForm.max_services = server.maxServices
  serverForm.weight = server.weight
  serverForm.nameservers = server.nameservers ?? ''
}

function saveServer(): void {
  if (editingServer.value === null) {
    serverForm.post('/admin/apps/infrastructure/servers', {
      preserveScroll: true,
      onSuccess: resetServer,
    })
    return
  }

  serverForm.put(`/admin/apps/infrastructure/servers/${editingServer.value}`, {
    preserveScroll: true,
    onSuccess: resetServer,
  })
}

function resetServer(): void {
  editingServer.value = null
  serverForm.reset()
}

/**
 * Both deletions used to happen on the first click, from a ghost button in a
 * row of ghost buttons beside Edit.
 *
 * Only an empty one can be removed at all — the button is not rendered while
 * anything is placed on it — so this is configuration going, not a customer's
 * data. That makes it level 2: say what happens and ask, rather than asking
 * for the name typed back.
 */
const removingServer = ref<ServerRow | null>(null)
const removingGroup = ref<GroupRow | null>(null)

function removeServer(): void {
  const server = removingServer.value

  if (server === null) return

  router.delete(`/admin/apps/infrastructure/servers/${server.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removingServer.value = null
    },
  })
}

function removeGroup(): void {
  const group = removingGroup.value

  if (group === null) return

  router.delete(`/admin/apps/infrastructure/groups/${group.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removingGroup.value = null
    },
  })
}

const { t } = useTranslations()

const GROUP_COLUMNS: TableColumn[] = [
  { key: 'group', label: t('ui.infrastructure.group') },
  { key: 'placement', label: t('ui.infrastructure.placement') },
  { key: 'region', label: t('ui.infrastructure.region') },
  { key: 'servers', label: t('ui.infrastructure.servers'), numeric: true },
  { key: 'actions', label: '' },
]

const SERVER_COLUMNS: TableColumn[] = [
  { key: 'server', label: t('ui.infrastructure.server') },
  { key: 'group', label: t('ui.infrastructure.group'), optional: true },
  { key: 'module', label: t('ui.infrastructure.module') },
  { key: 'status', label: t('ui.infrastructure.status') },
  { key: 'health', label: t('ui.infrastructure.health') },
  { key: 'usage', label: t('ui.infrastructure.in_use'), numeric: true },
  { key: 'actions', label: '' },
]

function test(server: ServerRow): void {
  router.post(`/admin/apps/infrastructure/servers/${server.id}/test`, {}, { preserveScroll: true })
}

function usage(server: ServerRow): string {
  return server.maxServices === 0
    ? String(server.services)
    : `${server.services} / ${server.maxServices}`
}
</script>

<template>
  <Head :title="t('ui.infrastructure.title')" />

  <AdminLayout :heading="t('ui.infrastructure.title')">
    <template #header>
      <PageHeader
        :title="t('ui.infrastructure.title')"
        :description="t('ui.infrastructure.intro')"
      />
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        :title="t('ui.infrastructure.groups')"
        :description="t('ui.infrastructure.groups_intro')"
        :divided="groups.length === 0"
      >
        <AppTable v-if="groups.length > 0" name="server-groups" :columns="GROUP_COLUMNS">
          <AppTableRow v-for="group in groups" :key="group.id">
            <td data-col="group" class="font-medium">{{ group.name }}</td>
            <td data-col="placement">{{ group.strategyLabel }}</td>
            <td data-col="region" class="text-content-muted">{{ group.region ?? '—' }}</td>
            <td data-col="servers" class="numeric tabular-nums">{{ group.servers }}</td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions inline-flex gap-1">
                <AppButton size="sm" variant="ghost" @click="editGroup(group)">
                  {{ t('ui.infrastructure.edit') }}
                </AppButton>
                <AppButton
                  v-if="group.servers === 0"
                  size="sm"
                  variant="danger-subtle"
                  @click="removingGroup = group"
                >
                  {{ t('ui.infrastructure.delete') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          variant="plain"
          icon="servers"
          :title="t('ui.infrastructure.no_groups')"
          :description="t('ui.infrastructure.no_groups_detail')"
        />
      </DetailSection>

      <DetailSection
        v-if="can.manage"
        :title="editingGroup ? t('ui.infrastructure.edit_group') : t('ui.infrastructure.add_group')"
      >
        <form class="flex flex-col gap-4" @submit.prevent="saveGroup">
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="groupForm.name"
              :label="t('ui.infrastructure.name')"
              :error="groupForm.errors.name"
            />
            <AppSelect
              v-model="groupForm.placement_strategy"
              :label="t('ui.infrastructure.placement')"
              :options="options.strategies"
              :error="groupForm.errors.placement_strategy"
            />
            <AppInput
              v-model="groupForm.region"
              :label="t('ui.infrastructure.region')"
              :error="groupForm.errors.region"
              :hint="t('ui.infrastructure.region_hint')"
            />
            <AppTextarea
              v-model="groupForm.notes"
              class="sm:col-span-2"
              :label="t('ui.infrastructure.notes')"
              :error="groupForm.errors.notes"
            />
          </div>
          <div class="flex gap-2">
            <AppButton type="submit" variant="primary" :loading="groupForm.processing">
              {{ t('ui.infrastructure.save') }}
            </AppButton>
            <AppButton v-if="editingGroup" variant="ghost" @click="resetGroup">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <DetailSection
        :title="t('ui.infrastructure.servers')"
        :description="t('ui.infrastructure.servers_intro')"
        :divided="servers.length === 0"
      >
        <AppTable v-if="servers.length > 0" name="servers" :columns="SERVER_COLUMNS">
          <AppTableRow v-for="server in servers" :key="server.id">
            <td data-col="server">
              <span class="font-medium">{{ server.name }}</span>
              <span class="text-content-muted text-chrome block">{{ server.hostname }}</span>
            </td>
            <td data-col="group" class="text-content-muted">{{ server.group ?? '—' }}</td>
            <td data-col="module">{{ server.module }}</td>
            <td data-col="status">
              <AppStatus :tone="statusTone(server.status)" :label="server.statusLabel" />
            </td>
            <td data-col="health">
              <AppStatus :tone="statusTone(server.health)" :label="server.healthLabel" />
              <span v-if="server.healthMessage" class="text-content-muted text-chrome block">
                {{ server.healthMessage }}
              </span>
            </td>
            <td data-col="usage" class="numeric tabular-nums">{{ usage(server) }}</td>
            <td data-col="actions" class="text-right">
              <span v-if="can.manage" class="row-actions inline-flex gap-1">
                <AppButton size="sm" variant="ghost" @click="test(server)">
                  {{ t('ui.infrastructure.test') }}
                </AppButton>
                <AppButton size="sm" variant="ghost" @click="editServer(server)">
                  {{ t('ui.infrastructure.edit') }}
                </AppButton>
                <AppButton
                  v-if="server.services === 0"
                  size="sm"
                  variant="danger-subtle"
                  @click="removingServer = server"
                >
                  {{ t('ui.infrastructure.delete') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          variant="plain"
          icon="servers"
          :title="t('ui.infrastructure.no_servers')"
          :description="t('ui.infrastructure.no_servers_detail')"
        />
      </DetailSection>

      <DetailSection
        v-if="can.manage"
        :title="
          editingServer ? t('ui.infrastructure.edit_server') : t('ui.infrastructure.add_server')
        "
        :description="t('ui.infrastructure.server_form_intro')"
      >
        <form class="flex flex-col gap-4" @submit.prevent="saveServer">
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="serverForm.name"
              :label="t('ui.infrastructure.name')"
              :error="serverForm.errors.name"
            />
            <AppSelect
              v-model="serverForm.server_group_id"
              :label="t('ui.infrastructure.group')"
              :options="[
                { value: '', label: t('ui.infrastructure.none') },
                ...groups.map((group) => ({ value: group.id, label: group.name })),
              ]"
              :error="serverForm.errors.server_group_id"
            />
            <AppSelect
              v-model="serverForm.module"
              :label="t('ui.infrastructure.module')"
              :options="options.modules"
              :error="serverForm.errors.module"
            />
            <AppSelect
              v-model="serverForm.status"
              :label="t('ui.infrastructure.status')"
              :options="options.statuses"
              :error="serverForm.errors.status"
            />
            <AppInput
              v-model="serverForm.hostname"
              :label="t('ui.infrastructure.hostname')"
              :error="serverForm.errors.hostname"
            />
            <AppInput
              v-model="serverForm.ip_address"
              :label="t('ui.infrastructure.ip')"
              :error="serverForm.errors.ip_address"
            />
            <AppInput
              v-model="serverForm.port"
              type="number"
              :label="t('ui.infrastructure.port')"
              :error="serverForm.errors.port"
            />
            <AppInput
              v-model="serverForm.username"
              :label="t('ui.infrastructure.username')"
              :error="serverForm.errors.username"
            />
            <AppInput
              v-model="serverForm.secret"
              class="sm:col-span-2"
              type="password"
              :label="t('ui.infrastructure.token')"
              :error="serverForm.errors.secret"
              :hint="
                editingServer
                  ? t('ui.infrastructure.token_kept')
                  : t('ui.infrastructure.token_hint')
              "
            />
            <AppInput
              v-model="serverForm.region"
              :label="t('ui.infrastructure.region')"
              :error="serverForm.errors.region"
            />
            <AppInput
              v-model="serverForm.max_services"
              type="number"
              :label="t('ui.infrastructure.max_services')"
              :hint="t('ui.infrastructure.max_services_hint')"
              :error="serverForm.errors.max_services"
            />
            <AppInput
              v-model="serverForm.weight"
              type="number"
              :label="t('ui.infrastructure.weight')"
              :error="serverForm.errors.weight"
            />
            <AppInput
              v-model="serverForm.nameservers"
              :label="t('ui.infrastructure.nameservers')"
              :error="serverForm.errors.nameservers"
            />
            <AppCheckbox
              v-model="serverForm.secure"
              :label="t('ui.infrastructure.tls')"
              class="sm:col-span-2"
            />
          </div>

          <div class="flex gap-2">
            <AppButton type="submit" variant="primary" :loading="serverForm.processing">
              {{ t('ui.infrastructure.save') }}
            </AppButton>
            <AppButton v-if="editingServer" variant="ghost" @click="resetServer">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>
    </div>

    <AppConfirm
      :open="removingGroup !== null"
      level="consequential"
      :title="t('ui.infrastructure.delete_group_title', { name: removingGroup?.name ?? '' })"
      :description="t('ui.infrastructure.delete_group_detail')"
      :confirm-label="t('ui.infrastructure.delete_group_confirm')"
      @update:open="(value: boolean) => (removingGroup = value ? removingGroup : null)"
      @confirm="removeGroup"
    />

    <AppConfirm
      :open="removingServer !== null"
      level="consequential"
      :title="t('ui.infrastructure.delete_server_title', { name: removingServer?.name ?? '' })"
      :description="t('ui.infrastructure.delete_server_detail')"
      :confirm-label="t('ui.infrastructure.delete_server_confirm')"
      @update:open="(value: boolean) => (removingServer = value ? removingServer : null)"
      @confirm="removeServer"
    />
  </AdminLayout>
</template>
