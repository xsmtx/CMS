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

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ServerRow {
  id: string
  name: string
  hostname: string
  ipAddress: string | null
  group: string | null
  module: string | null
  status: string
  canOpenSession: boolean
  hasSecret: boolean
}

defineProps<{
  brand: string
  platform: { version: string; entitlements: { key: string; allowed: boolean }[] }
  servers: ServerRow[]
}>()

function openSession(server: ServerRow): void {
  router.post(`/admin/apps/connect/servers/${server.id}/session`)
}
</script>

<template>
  <Head :title="`${brand} Connect`" />

  <AdminLayout
    :heading="`${brand} Connect`"
    description="What this installation is joined to, and a way into the machines it already holds credentials for."
  >
    <AppCard
      class="mb-6"
      title="This installation"
      description="What the licence says this installation may do. A seam with a dull default: a gate whose default is deny turns an unreachable licence service into an outage."
    >
      <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-content-muted text-xs">Platform version</dt>
          <dd class="mt-0.5 font-mono">{{ platform.version }}</dd>
        </div>
        <div v-for="entitlement in platform.entitlements" :key="entitlement.key">
          <dt class="text-content-muted text-xs">{{ entitlement.key }}</dt>
          <dd class="mt-0.5">
            <AppBadge :tone="entitlement.allowed ? 'success' : 'neutral'">
              {{ entitlement.allowed ? 'Allowed' : 'Not allowed' }}
            </AppBadge>
          </dd>
        </div>
      </dl>
    </AppCard>

    <AppTable
      v-if="servers.length > 0"
      :headers="['Server', 'Hostname', 'Group', 'Module', 'Status', '']"
    >
      <tr v-for="server in servers" :key="server.id">
        <td class="px-4 py-2.5 font-medium">{{ server.name }}</td>
        <td class="text-content-muted px-4 py-2.5">
          {{ server.hostname }}
          <span v-if="server.ipAddress" class="block font-mono text-xs">
            {{ server.ipAddress }}
          </span>
        </td>
        <td class="text-content-muted px-4 py-2.5">{{ server.group ?? '—' }}</td>
        <td class="text-content-muted px-4 py-2.5">{{ server.module ?? '—' }}</td>
        <td class="px-4 py-2.5">
          <AppBadge :tone="server.status === 'active' ? 'success' : 'warning'">
            {{ server.status }}
          </AppBadge>
        </td>
        <td class="px-4 py-2.5 text-right">
          <AppButton v-if="server.canOpenSession" size="sm" @click="openSession(server)">
            Open panel
          </AppButton>
          <span v-else-if="!server.hasSecret" class="text-content-muted text-xs">
            No credential stored
          </span>
          <span v-else class="text-content-muted text-xs">Panel cannot issue a session</span>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      title="No servers yet"
      description="Add one under Apps & Integrations. Its credential stays on this machine; what travels is a session the panel issued."
    />

    <p class="text-content-muted mt-6 max-w-[74ch] text-xs leading-relaxed">
      Opening a panel never reveals a stored password. The platform asks the panel for a short-lived
      session using the API credential it already holds, and that credential never reaches a
      browser. Every session is recorded against the operator who asked for it.
    </p>
  </AdminLayout>
</template>
