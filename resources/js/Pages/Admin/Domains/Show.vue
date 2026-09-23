<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface DomainEvent {
  id: string
  operation: string
  outcome: string
  outcomeLabel: string
  actor: string | null
  message: string | null
  occurredAt: string
}

const props = defineProps<{
  domain: {
    id: string
    name: string
    status: string
    statusLabel: string
    customer: string | null
    registrar: string | null
    externalId: string | null
    orderNumber: string | null
    registeredOn: string | null
    expiresOn: string | null
    daysUntilExpiry: number | null
    renewal: string
    years: number
    autoRenew: boolean
    registrarLock: boolean
    whoisPrivacy: boolean
    nameservers: string[]
    failureReason: string | null
    syncedAt: string | null
    events: DomainEvent[]
    transitions: { value: string; label: string }[]
  }
  can: {
    update: boolean
    register: boolean
    renew: boolean
    nameservers: boolean
    lock: boolean
    autoRenew: boolean
    sync: boolean
  }
}>()

const nameserverForm = useForm({
  operation: 'set_nameservers',
  nameservers: props.domain.nameservers.length > 0 ? [...props.domain.nameservers] : ['', ''],
})

const statusForm = useForm({
  status: props.domain.transitions[0]?.value ?? '',
  reason: '',
})

const renewing = ref(false)
const renewForm = useForm({ operation: 'renew', years: 1 })

function registerNow(): void {
  router.post(`/admin/domains/${props.domain.id}/register`, {}, { preserveScroll: true })
}

function act(operation: string, flag?: boolean): void {
  router.post(
    `/admin/domains/${props.domain.id}/actions`,
    flag === undefined ? { operation } : { operation, flag },
    { preserveScroll: true },
  )
}

function saveNameservers(): void {
  nameserverForm.post(`/admin/domains/${props.domain.id}/actions`, { preserveScroll: true })
}

function renew(): void {
  renewForm.post(`/admin/domains/${props.domain.id}/actions`, {
    preserveScroll: true,
    onSuccess: () => (renewing.value = false),
  })
}

function changeStatus(): void {
  statusForm.put(`/admin/domains/${props.domain.id}/status`, { preserveScroll: true })
}

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active' || status === 'succeeded' || status === 'already_done') return 'success'
  if (status === 'failed' || status === 'deleted' || status === 'redemption') return 'danger'
  if (status === 'expired' || status === 'pending' || status === 'registering') return 'warning'
  return 'neutral'
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="domain.name" />

  <AdminLayout :heading="domain.name" :description="domain.customer ?? undefined">
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppCard>
          <div class="mb-4 flex flex-wrap items-center gap-3">
            <AppBadge :tone="tone(domain.status)">{{ domain.statusLabel }}</AppBadge>
            <span v-if="domain.registrar" class="text-content-muted text-xs">
              {{ domain.registrar }}
            </span>
          </div>

          <AppAlert v-if="domain.failureReason" tone="danger" class="mb-4">
            {{ domain.failureReason }}
          </AppAlert>

          <dl class="divide-line divide-y text-sm">
            <div class="flex justify-between gap-4 py-2.5 first:pt-0">
              <dt class="text-content-muted">Registered</dt>
              <dd>{{ domain.registeredOn ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Expires</dt>
              <dd>
                {{ domain.expiresOn ?? '—' }}
                <span
                  v-if="domain.daysUntilExpiry !== null"
                  class="text-content-muted ml-1 text-xs"
                >
                  ({{ domain.daysUntilExpiry }} days)
                </span>
              </dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Term</dt>
              <dd>{{ domain.years }} year(s)</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Renewal</dt>
              <dd class="tabular-nums">{{ domain.renewal }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Registrar reference</dt>
              <dd class="font-mono text-xs">{{ domain.externalId ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Order</dt>
              <dd>{{ domain.orderNumber ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5 last:pb-0">
              <dt class="text-content-muted">Last synced</dt>
              <dd>{{ formatDateTime(domain.syncedAt) }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard v-if="can.nameservers" title="Nameservers">
          <div class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-for="(_, index) in nameserverForm.nameservers"
              :key="index"
              :model-value="nameserverForm.nameservers[index] ?? ''"
              :label="`Nameserver ${index + 1}`"
              :error="
                nameserverForm.errors[`nameservers.${index}` as keyof typeof nameserverForm.errors]
              "
              @update:model-value="(value) => (nameserverForm.nameservers[index] = String(value))"
            />
          </div>

          <div class="mt-4 flex gap-2">
            <AppButton :loading="nameserverForm.processing" @click="saveNameservers"
              >Save</AppButton
            >
            <AppButton variant="ghost" @click="nameserverForm.nameservers.push('')">
              Add another
            </AppButton>
          </div>
        </AppCard>

        <AppCard title="Activity">
          <ul v-if="domain.events.length > 0" class="divide-line divide-y">
            <li v-for="event in domain.events" :key="event.id" class="py-3 first:pt-0 last:pb-0">
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-sm font-medium">
                    {{ event.operation }}
                    <AppBadge class="ml-2" :tone="tone(event.outcome)">
                      {{ event.outcomeLabel }}
                    </AppBadge>
                  </p>
                  <p
                    v-if="event.message"
                    class="text-content-muted mt-1 text-xs leading-relaxed break-words"
                  >
                    {{ event.message }}
                  </p>
                </div>
                <p class="text-content-subtle shrink-0 text-xs whitespace-nowrap">
                  {{ formatDateTime(event.occurredAt) }}
                </p>
              </div>
              <p v-if="event.actor" class="text-content-subtle mt-1 text-xs">{{ event.actor }}</p>
            </li>
          </ul>
          <p v-else class="text-content-muted text-sm">Nothing has been attempted yet.</p>
        </AppCard>
      </div>

      <div class="flex flex-col gap-6">
        <AppCard title="Actions">
          <div class="flex flex-col gap-2">
            <AppButton v-if="can.register" variant="primary" @click="registerNow">
              Register now
            </AppButton>

            <template v-if="can.renew">
              <template v-if="renewing">
                <AppInput v-model="renewForm.years" type="number" label="Years" />
                <div class="flex gap-2">
                  <AppButton
                    size="sm"
                    variant="primary"
                    :loading="renewForm.processing"
                    @click="renew"
                  >
                    Renew
                  </AppButton>
                  <AppButton size="sm" variant="ghost" @click="renewing = false">Cancel</AppButton>
                </div>
              </template>
              <AppButton v-else @click="renewing = true">Renew</AppButton>
            </template>

            <AppButton v-if="can.sync" variant="ghost" @click="act('sync')">
              Sync from registrar
            </AppButton>

            <AppButton
              v-if="can.lock"
              variant="ghost"
              @click="act('set_lock', !domain.registrarLock)"
            >
              {{ domain.registrarLock ? 'Unlock at registrar' : 'Lock at registrar' }}
            </AppButton>

            <AppButton
              v-if="can.autoRenew"
              variant="ghost"
              @click="act('set_auto_renew', !domain.autoRenew)"
            >
              {{ domain.autoRenew ? 'Turn auto-renew off' : 'Turn auto-renew on' }}
            </AppButton>
          </div>
        </AppCard>

        <AppCard title="Settings">
          <dl class="divide-line divide-y text-sm">
            <div class="flex justify-between gap-4 py-2.5 first:pt-0">
              <dt class="text-content-muted">Auto-renew</dt>
              <dd>{{ domain.autoRenew ? 'On' : 'Off' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Registrar lock</dt>
              <dd>{{ domain.registrarLock ? 'On' : 'Off' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5 last:pb-0">
              <dt class="text-content-muted">WHOIS privacy</dt>
              <dd>{{ domain.whoisPrivacy ? 'On' : 'Off' }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard v-if="can.update && domain.transitions.length > 0" title="Change status">
          <div class="flex flex-col gap-3">
            <AppSelect
              v-model="statusForm.status"
              label="New status"
              :options="domain.transitions"
              :error="statusForm.errors.status"
            />
            <AppInput
              v-model="statusForm.reason"
              label="Reason"
              :error="statusForm.errors.reason"
              hint="Optional, and kept forever."
            />
            <div>
              <AppButton size="sm" :loading="statusForm.processing" @click="changeStatus">
                Apply
              </AppButton>
            </div>
          </div>
        </AppCard>
      </div>
    </div>
  </AdminLayout>
</template>
