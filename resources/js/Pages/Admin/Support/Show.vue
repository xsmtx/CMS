<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface Attachment {
  id: string
  name: string
  size: string
}

interface Reply {
  id: string
  author: string
  fromStaff: boolean
  isInternal: boolean
  body: string
  html: string
  createdAt: string
  attachments: Attachment[]
}

const props = defineProps<{
  ticket: {
    id: string
    number: string
    subject: string
    status: string
    statusLabel: string
    priority: string
    customer: string | null
    department: string | null
    departmentId: string | null
    assignee: string | null
    assignedTo: string | null
    service: string | null
    serviceId: string | null
    domain: string | null
    domainId: string | null
    invoice: string | null
    invoiceId: string | null
    openedAt: string | null
    dueAt: string | null
    hasBreached: boolean
    firstRespondedAt: string | null
    replies: Reply[]
    transitions: { value: string; label: string }[]
  }
  options: {
    departments: { value: string; label: string }[]
    agents: { value: string; label: string }[]
    canned: { id: string; name: string; body: string }[]
    priorities: { value: string; label: string }[]
  }
  can: { manage: boolean }
}>()

const replyForm = useForm({ body: '', internal: false })

const settingsForm = useForm({
  department_id: props.ticket.departmentId ?? '',
  assigned_to: props.ticket.assignedTo ?? '',
  priority: props.ticket.priority,
  status: '',
})

const showCanned = ref(false)

function send(): void {
  replyForm.post(`/admin/support/${props.ticket.id}/replies`, {
    preserveScroll: true,
    onSuccess: () => replyForm.reset(),
  })
}

function insert(body: string): void {
  replyForm.body = replyForm.body === '' ? body : `${replyForm.body}\n\n${body}`
  showCanned.value = false
}

function saveSettings(): void {
  settingsForm.put(`/admin/support/${props.ticket.id}`, { preserveScroll: true })
}

function transition(status: string): void {
  settingsForm.status = status
  saveSettings()
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="ticket.subject" />

  <AdminLayout
    :heading="ticket.subject"
    :description="`${ticket.number} · ${ticket.customer ?? ''}`"
  >
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <ul class="flex flex-col gap-4">
          <li
            v-for="reply in ticket.replies"
            :key="reply.id"
            class="rounded-lg border p-4"
            :class="
              reply.isInternal
                ? 'border-warning/40 bg-surface-secondary'
                : 'border-line bg-surface-primary'
            "
          >
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <p class="text-body font-medium">
                {{ reply.author }}
                <!-- Loud on purpose: an agent must never be in doubt about
                     whether the customer can read what they wrote. -->
                <AppBadge v-if="reply.isInternal" class="ml-2" tone="warning">Internal</AppBadge>
              </p>
              <p class="text-content-subtle text-chrome">{{ formatDateTime(reply.createdAt) }}</p>
            </div>

            <!-- Rendered by TicketMarkdown, which strips author HTML
                 rather than escaping it. The raw body is never
                 interpolated anywhere. -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div class="prose-article text-body leading-relaxed" v-html="reply.html" />

            <ul v-if="reply.attachments.length > 0" class="mt-3 flex flex-wrap gap-2">
              <li v-for="file in reply.attachments" :key="file.id">
                <a
                  :href="`/attachments/${file.id}`"
                  class="border-line hover:bg-surface-secondary text-chrome inline-flex items-center gap-2 rounded-sm border px-2.5 py-1"
                >
                  {{ file.name }}
                  <span class="text-content-subtle">{{ file.size }}</span>
                </a>
              </li>
            </ul>
          </li>
        </ul>

        <AppCard v-if="can.manage" title="Reply">
          <AppTextarea
            v-model="replyForm.body"
            label="Message"
            :error="replyForm.errors.body"
            :rows="6"
          />

          <div class="mt-3">
            <AppCheckbox
              v-model="replyForm.internal"
              label="Internal note"
              description="Only staff see this, and it does not stop the customer's clock."
            />
          </div>

          <div class="mt-4 flex flex-wrap gap-2">
            <AppButton variant="primary" :loading="replyForm.processing" @click="send">
              {{ replyForm.internal ? 'Add note' : 'Send reply' }}
            </AppButton>
            <AppButton
              v-if="options.canned.length > 0"
              variant="ghost"
              @click="showCanned = !showCanned"
            >
              Canned responses
            </AppButton>
          </div>

          <ul v-if="showCanned" class="divide-line border-line mt-4 divide-y rounded-sm border">
            <li
              v-for="canned in options.canned"
              :key="canned.id"
              class="flex items-center justify-between gap-3 px-3 py-2"
            >
              <span class="text-body">{{ canned.name }}</span>
              <AppButton size="sm" variant="ghost" @click="insert(canned.body)">Insert</AppButton>
            </li>
          </ul>
        </AppCard>
      </div>

      <div class="flex flex-col gap-6">
        <AppCard title="Status">
          <div class="flex flex-wrap items-center gap-2">
            <AppStatus :tone="statusTone(ticket.status)" :label="ticket.statusLabel" />
            <AppBadge v-if="ticket.hasBreached" tone="danger">Overdue</AppBadge>
          </div>

          <dl class="divide-line text-body mt-4 divide-y">
            <div class="flex justify-between gap-4 py-2 first:pt-0">
              <dt class="text-content-muted">Opened</dt>
              <dd>{{ formatDateTime(ticket.openedAt) }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2">
              <dt class="text-content-muted">First response due</dt>
              <dd>{{ formatDateTime(ticket.dueAt) }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2 last:pb-0">
              <dt class="text-content-muted">First answered</dt>
              <dd>{{ formatDateTime(ticket.firstRespondedAt) }}</dd>
            </div>
          </dl>

          <div v-if="can.manage" class="mt-4 flex flex-wrap gap-2">
            <AppButton
              v-for="target in ticket.transitions"
              :key="target.value"
              size="sm"
              variant="ghost"
              @click="transition(target.value)"
            >
              {{ target.label }}
            </AppButton>
          </div>
        </AppCard>

        <AppCard v-if="can.manage" title="Assignment">
          <div class="flex flex-col gap-3">
            <AppSelect
              v-model="settingsForm.department_id"
              label="Department"
              :options="options.departments"
            />
            <AppSelect
              v-model="settingsForm.assigned_to"
              label="Assigned to"
              :options="[{ value: '', label: 'Unassigned' }, ...options.agents]"
            />
            <AppSelect
              v-model="settingsForm.priority"
              label="Priority"
              :options="options.priorities"
            />
            <div>
              <AppButton size="sm" :loading="settingsForm.processing" @click="saveSettings">
                Save
              </AppButton>
            </div>
          </div>
        </AppCard>

        <AppCard v-if="ticket.service || ticket.domain || ticket.invoice" title="Related">
          <dl class="divide-line text-body divide-y">
            <div v-if="ticket.service" class="flex justify-between gap-4 py-2 first:pt-0">
              <dt class="text-content-muted">Service</dt>
              <dd>
                <a
                  :href="`/admin/services/${ticket.serviceId}`"
                  class="underline-offset-4 hover:underline"
                >
                  {{ ticket.service }}
                </a>
              </dd>
            </div>
            <div v-if="ticket.domain" class="flex justify-between gap-4 py-2">
              <dt class="text-content-muted">Domain</dt>
              <dd>
                <a
                  :href="`/admin/domains/${ticket.domainId}`"
                  class="underline-offset-4 hover:underline"
                >
                  {{ ticket.domain }}
                </a>
              </dd>
            </div>
            <div v-if="ticket.invoice" class="flex justify-between gap-4 py-2 last:pb-0">
              <dt class="text-content-muted">Invoice</dt>
              <dd>
                <a
                  :href="`/admin/invoices/${ticket.invoiceId}`"
                  class="underline-offset-4 hover:underline"
                >
                  {{ ticket.invoice }}
                </a>
              </dd>
            </div>
          </dl>
        </AppCard>
      </div>
    </div>
  </AdminLayout>
</template>
