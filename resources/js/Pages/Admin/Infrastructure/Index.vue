<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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
    groupForm.post('/admin/infrastructure/groups', { preserveScroll: true, onSuccess: resetGroup })
    return
  }

  groupForm.put(`/admin/infrastructure/groups/${editingGroup.value}`, {
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
    serverForm.post('/admin/infrastructure/servers', {
      preserveScroll: true,
      onSuccess: resetServer,
    })
    return
  }

  serverForm.put(`/admin/infrastructure/servers/${editingServer.value}`, {
    preserveScroll: true,
    onSuccess: resetServer,
  })
}

function resetServer(): void {
  editingServer.value = null
  serverForm.reset()
}

function removeServer(server: ServerRow): void {
  router.delete(`/admin/infrastructure/servers/${server.id}`, { preserveScroll: true })
}

function removeGroup(group: GroupRow): void {
  router.delete(`/admin/infrastructure/groups/${group.id}`, { preserveScroll: true })
}

function test(server: ServerRow): void {
  router.post(`/admin/infrastructure/servers/${server.id}/test`, {}, { preserveScroll: true })
}

function healthTone(health: string): 'neutral' | 'success' | 'danger' {
  if (health === 'healthy') return 'success'
  if (health === 'unreachable') return 'danger'
  return 'neutral'
}

function usage(server: ServerRow): string {
  return server.maxServices === 0
    ? String(server.services)
    : `${server.services} / ${server.maxServices}`
}
</script>

<template>
  <Head title="Infrastructure" />

  <AdminLayout heading="Infrastructure" description="The nodes services are placed on.">
    <div class="flex flex-col gap-6">
      <AppCard title="Server groups" description="Placement belongs to the group, not the product.">
        <AppTable
          v-if="groups.length > 0"
          :headers="['Group', 'Placement', 'Region', 'Servers', '']"
        >
          <tr v-for="group in groups" :key="group.id">
            <td class="px-5 py-3.5 font-medium">{{ group.name }}</td>
            <td class="px-5 py-3.5">{{ group.strategyLabel }}</td>
            <td class="text-content-muted px-5 py-3.5">{{ group.region ?? '—' }}</td>
            <td class="px-5 py-3.5 tabular-nums">{{ group.servers }}</td>
            <td class="px-5 py-3.5 text-right">
              <div v-if="can.manage" class="flex justify-end gap-2">
                <AppButton size="sm" variant="ghost" @click="editGroup(group)">Edit</AppButton>
                <AppButton
                  v-if="group.servers === 0"
                  size="sm"
                  variant="ghost"
                  @click="removeGroup(group)"
                >
                  Delete
                </AppButton>
              </div>
            </td>
          </tr>
        </AppTable>

        <EmptyState
          v-else
          title="No server groups yet"
          description="A group holds the nodes a product's services are placed on, and the rule for choosing between them."
        />

        <div v-if="can.manage" class="border-line mt-5 border-t pt-5">
          <h3 class="mb-3 text-sm font-semibold">
            {{ editingGroup ? 'Edit group' : 'Add a group' }}
          </h3>
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput v-model="groupForm.name" label="Name" :error="groupForm.errors.name" />
            <AppSelect
              v-model="groupForm.placement_strategy"
              label="Placement"
              :options="options.strategies"
              :error="groupForm.errors.placement_strategy"
            />
            <AppInput
              v-model="groupForm.region"
              label="Region"
              :error="groupForm.errors.region"
              hint="Used by region-aware placement."
            />
            <AppTextarea
              v-model="groupForm.notes"
              class="sm:col-span-2"
              label="Notes"
              :error="groupForm.errors.notes"
            />
          </div>
          <div class="mt-4 flex gap-2">
            <AppButton variant="primary" :loading="groupForm.processing" @click="saveGroup">
              Save
            </AppButton>
            <AppButton v-if="editingGroup" variant="ghost" @click="resetGroup">Cancel</AppButton>
          </div>
        </div>
      </AppCard>

      <AppCard title="Servers">
        <AppTable
          v-if="servers.length > 0"
          :headers="['Server', 'Group', 'Module', 'Status', 'Health', 'In use', '']"
        >
          <tr v-for="server in servers" :key="server.id">
            <td class="px-5 py-3.5">
              <span class="font-medium">{{ server.name }}</span>
              <span class="text-content-muted block text-xs">{{ server.hostname }}</span>
            </td>
            <td class="text-content-muted px-5 py-3.5">{{ server.group ?? '—' }}</td>
            <td class="px-5 py-3.5">{{ server.module }}</td>
            <td class="px-5 py-3.5">
              <AppBadge :tone="server.status === 'active' ? 'success' : 'warning'">
                {{ server.statusLabel }}
              </AppBadge>
            </td>
            <td class="px-5 py-3.5">
              <AppBadge :tone="healthTone(server.health)">{{ server.healthLabel }}</AppBadge>
              <span v-if="server.healthMessage" class="text-content-muted block text-xs">
                {{ server.healthMessage }}
              </span>
            </td>
            <td class="px-5 py-3.5 tabular-nums">{{ usage(server) }}</td>
            <td class="px-5 py-3.5 text-right">
              <div v-if="can.manage" class="flex justify-end gap-2">
                <AppButton size="sm" variant="ghost" @click="test(server)">Test</AppButton>
                <AppButton size="sm" variant="ghost" @click="editServer(server)">Edit</AppButton>
                <AppButton
                  v-if="server.services === 0"
                  size="sm"
                  variant="ghost"
                  @click="removeServer(server)"
                >
                  Delete
                </AppButton>
              </div>
            </td>
          </tr>
        </AppTable>

        <EmptyState
          v-else
          title="No servers yet"
          description="Add the control panel a product's services should be created on. Credentials are stored encrypted and never shown again."
        />

        <div v-if="can.manage" class="border-line mt-5 border-t pt-5">
          <h3 class="mb-3 text-sm font-semibold">
            {{ editingServer ? 'Edit server' : 'Add a server' }}
          </h3>

          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput v-model="serverForm.name" label="Name" :error="serverForm.errors.name" />
            <AppSelect
              v-model="serverForm.server_group_id"
              label="Group"
              :options="[
                { value: '', label: 'None' },
                ...groups.map((group) => ({ value: group.id, label: group.name })),
              ]"
              :error="serverForm.errors.server_group_id"
            />
            <AppSelect
              v-model="serverForm.module"
              label="Module"
              :options="options.modules"
              :error="serverForm.errors.module"
            />
            <AppSelect
              v-model="serverForm.status"
              label="Status"
              :options="options.statuses"
              :error="serverForm.errors.status"
            />
            <AppInput
              v-model="serverForm.hostname"
              label="Hostname"
              :error="serverForm.errors.hostname"
            />
            <AppInput
              v-model="serverForm.ip_address"
              label="IP address"
              :error="serverForm.errors.ip_address"
            />
            <AppInput
              v-model="serverForm.port"
              type="number"
              label="Port"
              :error="serverForm.errors.port"
            />
            <AppInput
              v-model="serverForm.username"
              label="Username"
              :error="serverForm.errors.username"
            />
            <AppInput
              v-model="serverForm.secret"
              class="sm:col-span-2"
              type="password"
              label="API token"
              :error="serverForm.errors.secret"
              :hint="
                editingServer
                  ? 'A token is stored. Leave this empty to keep it.'
                  : 'A token, never a root password. Stored encrypted and never shown again.'
              "
            />
            <AppInput
              v-model="serverForm.region"
              label="Region"
              :error="serverForm.errors.region"
            />
            <AppInput
              v-model="serverForm.max_services"
              type="number"
              label="Maximum services"
              hint="Zero means no limit."
              :error="serverForm.errors.max_services"
            />
            <AppInput
              v-model="serverForm.weight"
              type="number"
              label="Weight"
              :error="serverForm.errors.weight"
            />
            <AppInput
              v-model="serverForm.nameservers"
              label="Nameservers"
              :error="serverForm.errors.nameservers"
            />
            <AppCheckbox v-model="serverForm.secure" label="Use TLS" class="sm:col-span-2" />
          </div>

          <div class="mt-4 flex gap-2">
            <AppButton variant="primary" :loading="serverForm.processing" @click="saveServer">
              Save
            </AppButton>
            <AppButton v-if="editingServer" variant="ghost" @click="resetServer">Cancel</AppButton>
          </div>
        </div>
      </AppCard>
    </div>
  </AdminLayout>
</template>
