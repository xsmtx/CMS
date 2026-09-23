<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ServiceEvent {
  id: string
  operation: string
  outcome: string
  outcomeLabel: string
  actor: string | null
  message: string | null
  occurredAt: string
}

const props = defineProps<{
  service: {
    id: string
    name: string
    status: string
    statusLabel: string
    customer: string | null
    customerId: string | null
    domain: string | null
    server: string | null
    recurring: string
    nextDueOn: string | null
    package: string | null
    module: string | null
    externalId: string | null
    username: string | null
    orderNumber: string | null
    startsOn: string | null
    provisionedAt: string | null
    suspensionReason: string | null
    failureReason: string | null
    syncedAt: string | null
    options: { group: string; label: string }[]
    events: ServiceEvent[]
    transitions: { value: string; label: string }[]
  }
  can: {
    update: boolean
    provision: boolean
    suspend: boolean
    unsuspend: boolean
    terminate: boolean
    sync: boolean
  }
}>()

const page = usePage()

const suspending = ref(false)
const terminating = ref(false)
const terminatingBusy = ref(false)
const suspendForm = useForm({ operation: 'suspend', reason: '' })
const statusForm = useForm({
  status: props.service.transitions[0]?.value ?? '',
  reason: '',
})

// Flashed by the credentials endpoint, so reading somebody's control panel
// password is an action in the audit log rather than a side effect of
// opening this page.
const credentials = computed(
  () => page.props.flash.credentials as { username: string | null; password: string | null } | null,
)

function provision(): void {
  router.post(`/admin/services/${props.service.id}/provision`, {}, { preserveScroll: true })
}

function act(operation: string): void {
  router.post(
    `/admin/services/${props.service.id}/actions`,
    { operation },
    { preserveScroll: true },
  )
}

function suspend(): void {
  suspendForm.post(`/admin/services/${props.service.id}/actions`, {
    preserveScroll: true,
    onSuccess: () => {
      suspending.value = false
      suspendForm.reset()
    },
  })
}

/**
 * Level 4 (§8): a reason, and the service's own name typed out.
 *
 * It used to be a sentence and two buttons in the same row as Suspend and
 * Sync. That is a button muscle memory reaches on a Friday afternoon —
 * distance and a different surface are what make the hand stop, and typing
 * the name is the only guard there is nothing to click through.
 *
 * The reason goes to the audit record and to the operation's own row, which
 * is where somebody will look when the customer asks why their account is
 * gone.
 */
function terminate(reason: string | null): void {
  terminatingBusy.value = true

  router.post(
    `/admin/services/${props.service.id}/actions`,
    { operation: 'terminate', reason },
    {
      preserveScroll: true,
      onFinish: () => {
        terminatingBusy.value = false
        terminating.value = false
      },
    },
  )
}

function revealCredentials(): void {
  router.post(`/admin/services/${props.service.id}/credentials`, {}, { preserveScroll: true })
}

function changeStatus(): void {
  statusForm.put(`/admin/services/${props.service.id}/status`, { preserveScroll: true })
}

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active' || status === 'succeeded' || status === 'already_done') return 'success'
  if (status === 'failed' || status === 'terminated') return 'danger'
  if (status === 'suspended' || status === 'grace_period' || status === 'pending') return 'warning'
  return 'neutral'
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="service.name" />

  <AdminLayout :heading="service.name" :description="service.customer ?? undefined">
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppCard>
          <div class="mb-4 flex flex-wrap items-center gap-3">
            <AppBadge :tone="tone(service.status)">{{ service.statusLabel }}</AppBadge>
            <span v-if="service.module" class="text-content-muted text-xs">
              {{ service.module }}
            </span>
          </div>

          <AppAlert v-if="service.failureReason" tone="danger" class="mb-4">
            {{ service.failureReason }}
          </AppAlert>
          <AppAlert v-else-if="service.suspensionReason" class="mb-4">
            {{ service.suspensionReason }}
          </AppAlert>

          <dl class="divide-line divide-y text-sm">
            <div class="flex justify-between gap-4 py-2.5 first:pt-0">
              <dt class="text-content-muted">Domain</dt>
              <dd>{{ service.domain ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Package</dt>
              <dd>{{ service.package ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Server</dt>
              <dd>{{ service.server ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Account</dt>
              <dd class="font-mono text-xs">{{ service.externalId ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Recurring</dt>
              <dd class="tabular-nums">{{ service.recurring }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Next due</dt>
              <dd>{{ service.nextDueOn ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">Order</dt>
              <dd>{{ service.orderNumber ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5 last:pb-0">
              <dt class="text-content-muted">Set up</dt>
              <dd>{{ formatDateTime(service.provisionedAt) }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard v-if="service.options.length > 0" title="Configuration">
          <dl class="divide-line divide-y text-sm">
            <div
              v-for="option in service.options"
              :key="option.group + option.label"
              class="flex justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
            >
              <dt class="text-content-muted">{{ option.group }}</dt>
              <dd>{{ option.label }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard title="Activity">
          <ul v-if="service.events.length > 0" class="divide-line divide-y">
            <li v-for="event in service.events" :key="event.id" class="py-3 first:pt-0 last:pb-0">
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
            <AppButton v-if="can.provision" variant="primary" @click="provision">
              Set up now
            </AppButton>

            <template v-if="can.suspend && service.status === 'active'">
              <template v-if="suspending">
                <AppInput
                  v-model="suspendForm.reason"
                  label="Reason"
                  :error="suspendForm.errors.reason"
                  hint="Shown to the customer and kept on the record."
                />
                <div class="flex gap-2">
                  <AppButton
                    size="sm"
                    variant="primary"
                    :loading="suspendForm.processing"
                    @click="suspend"
                  >
                    Suspend
                  </AppButton>
                  <AppButton size="sm" variant="ghost" @click="suspending = false"
                    >Cancel</AppButton
                  >
                </div>
              </template>
              <AppButton v-else @click="suspending = true">Suspend</AppButton>
            </template>

            <AppButton
              v-if="can.unsuspend && service.status === 'suspended'"
              @click="act('unsuspend')"
            >
              Unsuspend
            </AppButton>

            <AppButton v-if="can.sync" variant="ghost" @click="act('sync')">
              Sync from provider
            </AppButton>

            <!-- Terminate is not here. It is in the danger zone at the
                 foot of the page (§8), away from the buttons an operator
                 presses every day. -->
          </div>
        </AppCard>

        <AppCard v-if="can.update && service.username" title="Credentials">
          <p class="text-content-muted mb-3 text-xs leading-relaxed">
            Reading this is recorded in the audit log.
          </p>

          <dl v-if="credentials" class="text-sm">
            <dt class="text-content-muted text-xs">Username</dt>
            <dd class="mb-2 font-mono text-xs">{{ credentials.username }}</dd>
            <dt class="text-content-muted text-xs">Password</dt>
            <dd class="font-mono text-xs break-all">{{ credentials.password ?? '—' }}</dd>
          </dl>

          <AppButton v-else size="sm" @click="revealCredentials">Reveal</AppButton>
        </AppCard>

        <AppCard v-if="can.update && service.transitions.length > 0" title="Change status">
          <div class="flex flex-col gap-3">
            <AppSelect
              v-model="statusForm.status"
              label="New status"
              :options="service.transitions"
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

    <!--
      Last on the page, always. Anything below this would be a reason to
      scroll past it, and a thing people scroll past is a thing they stop
      reading.
    -->
    <DangerZone
      v-if="can.terminate"
      description="These cannot be undone from here, and some of them cannot be undone at all."
    >
      <DangerZoneRow
        title="Terminate this service"
        description="The account is destroyed at the provider. Files, mailboxes and databases go with it, and nothing on this platform can bring them back. Billing stops."
      >
        <AppButton variant="danger" size="sm" @click="terminating = true">Terminate</AppButton>
      </DangerZoneRow>
    </DangerZone>

    <AppConfirm
      v-model:open="terminating"
      level="destructive"
      :title="`Terminate ${service.name}?`"
      description="The account is destroyed at the provider and cannot be recovered. The reason is written to the audit record."
      :phrase="service.name"
      confirm-label="Terminate"
      :busy="terminatingBusy"
      @confirm="terminate"
    />
  </AdminLayout>
</template>
