<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
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
  // Whether this seller has turned the assistant on for replies. Sent per
  // feature rather than as one flag, because drafting what a customer reads
  // and summarising for a colleague are different decisions (ADR 0050).
  ai: { reply: boolean; summary: boolean; triage: boolean }
}>()

const page = usePage()
const { t } = useTranslations()

const replyForm = useForm({ body: '', internal: false })

/**
 * The clock, which is the thing a support screen is actually about.
 *
 * `First response due` carries the breach rather than a badge in the header
 * doing it: the deadline and the fact it was missed are the same fact, and
 * separating them makes an operator look in two places.
 */
const clock = computed<DescriptionItem[]>(() => [
  { key: 'opened', label: t('ui.ticket.opened'), value: formatDateTime(props.ticket.openedAt) },
  { key: 'due', label: t('ui.ticket.due') },
  {
    key: 'answered',
    label: t('ui.ticket.answered'),
    value: formatDateTime(props.ticket.firstRespondedAt),
  },
])

const settingsForm = useForm({
  department_id: props.ticket.departmentId ?? '',
  assigned_to: props.ticket.assignedTo ?? '',
  priority: props.ticket.priority,
  status: '',
})

const showCanned = ref(false)

/*
 * The draft, which is flashed rather than stored: a draft is not a reply
 * until somebody sends it, and a table of drafts nobody sent would be a
 * second copy of customers' correspondence (ADR 0050).
 */
const drafting = ref(false)

const draft = computed(() => page.props.flash.draft ?? null)

const triaging = ref(false)

const suggestion = computed(() => {
  const flashed = page.props.flash.suggestion ?? null

  // Keyed by field, so a suggestion for one control never appears under
  // another when a second kind is added.
  return flashed !== null && flashed.field === 'department_id' ? flashed : null
})

const errors = computed(() => page.props.errors ?? {})

function askForDepartment(): void {
  triaging.value = true

  router.post(
    `/admin/support/${props.ticket.id}/triage`,
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        triaging.value = false
      },
    },
  )
}

function askForDraft(kind: 'reply' | 'summary'): void {
  drafting.value = true

  router.post(
    `/admin/support/${props.ticket.id}/draft`,
    { kind },
    {
      preserveScroll: true,
      onFinish: () => {
        drafting.value = false
      },
      onSuccess: () => {
        // Into the box the operator was going to type in. Appended rather
        // than replacing, for the reason `insert()` appends a canned reply:
        // somebody who has already started typing has not asked to lose it.
        if (draft.value !== null) insert(draft.value.text)
      },
    },
  )
}

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

  <AdminLayout :heading="ticket.subject">
    <template #header>
      <PageHeader :title="ticket.subject">
        <template #status>
          <AppStatus :tone="statusTone(ticket.status)" :label="ticket.statusLabel" />
          <!-- Two marks, because they are two facts: what state the ticket is
               in, and whether its clock has run out. -->
          <AppStatus v-if="ticket.hasBreached" tone="critical" :label="t('ui.ticket.overdue')" />
        </template>

        <template #meta>
          <span class="font-mono">{{ ticket.number }}</span>
          <template v-if="ticket.customer">
            <span aria-hidden="true">·</span>
            <span>{{ ticket.customer }}</span>
          </template>
          <template v-if="ticket.department">
            <span aria-hidden="true">·</span>
            <span>{{ ticket.department }}</span>
          </template>
          <span aria-hidden="true">·</span>
          <span>{{ ticket.assignee ?? t('ui.ticket.unassigned') }}</span>
        </template>

        <template v-if="can.manage && ticket.transitions.length > 0" #actions>
          <AppButton
            v-for="target in ticket.transitions"
            :key="target.value"
            @click="transition(target.value)"
          >
            {{ target.label }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
      <div class="flex min-w-0 flex-col gap-8">
        <!--
          A frame per reply, because each one is a thing somebody wrote at a
          time. An internal note is bordered differently on purpose: an agent
          must never be in doubt about whether the customer can read it.
        -->
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
              <p class="text-body flex flex-wrap items-center gap-x-2 font-medium">
                <span>{{ reply.author }}</span>
                <AppBadge v-if="reply.isInternal" tone="warning">
                  {{ t('ui.ticket.internal') }}
                </AppBadge>
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
                  class="border-line hover:bg-surface-hover text-chrome inline-flex items-center gap-2 rounded-sm border px-2.5 py-1 transition-colors duration-(--duration-fast)"
                >
                  {{ file.name }}
                  <span class="text-content-subtle">{{ file.size }}</span>
                </a>
              </li>
            </ul>
          </li>
        </ul>

        <DetailSection v-if="can.manage" :title="t('ui.ticket.reply')">
          <template #actions>
            <!--
              Beside the predefined replies, because that is what a draft is:
              a canned reply that happens to have been written for this
              ticket. Absent rather than disabled where the seller has not
              turned it on — a control that cannot do anything is a control
              that lies.
            -->
            <AppButton
              v-if="ai.summary"
              size="sm"
              variant="ghost"
              :loading="drafting"
              @click="askForDraft('summary')"
            >
              {{ t('ai.summarise') }}
            </AppButton>
            <AppButton
              v-if="ai.reply"
              size="sm"
              variant="ghost"
              :loading="drafting"
              @click="askForDraft('reply')"
            >
              {{ t('ai.draft') }}
            </AppButton>
            <AppButton
              v-if="options.canned.length > 0"
              size="sm"
              variant="ghost"
              @click="showCanned = !showCanned"
            >
              {{ t('ui.ticket.canned') }}
            </AppButton>
          </template>

          <AppTextarea
            v-model="replyForm.body"
            :label="t('ui.ticket.message')"
            :error="replyForm.errors.body"
            :rows="6"
          />

          <!--
            Said rather than assumed: what is in the box was written by a
            model, and the operator is the one sending it.
          -->
          <AppAlert v-if="draft" tone="info" class="mt-3">
            {{ t('ai.drafted', { model: draft.model }) }}
          </AppAlert>

          <div class="mt-3">
            <AppCheckbox
              v-model="replyForm.internal"
              :label="t('ui.ticket.internal_note')"
              :description="t('ui.ticket.internal_note_hint')"
            />
          </div>

          <div class="mt-4">
            <AppButton variant="primary" :loading="replyForm.processing" @click="send">
              {{ replyForm.internal ? t('ui.ticket.add_note') : t('ui.ticket.send') }}
            </AppButton>
          </div>

          <ul
            v-if="showCanned"
            class="divide-line-subtle border-line mt-4 divide-y rounded-lg border"
          >
            <li
              v-for="canned in options.canned"
              :key="canned.id"
              class="flex items-center justify-between gap-3 px-3 py-2"
            >
              <span class="text-body">{{ canned.name }}</span>
              <AppButton size="sm" variant="ghost" @click="insert(canned.body)">
                {{ t('ui.ticket.insert') }}
              </AppButton>
            </li>
          </ul>
        </DetailSection>
      </div>

      <aside class="flex min-w-0 flex-col gap-8">
        <DetailSection :title="t('ui.ticket.clock')" :description="t('ui.ticket.clock_intro')">
          <DescriptionList :items="clock">
            <template #due>
              <span class="flex flex-wrap items-center gap-x-2">
                <span :class="ticket.hasBreached ? 'text-danger' : ''">
                  {{ formatDateTime(ticket.dueAt) }}
                </span>
                <AppStatus
                  v-if="ticket.hasBreached"
                  tone="critical"
                  :label="t('ui.ticket.missed')"
                />
              </span>
            </template>
          </DescriptionList>
        </DetailSection>

        <DetailSection
          v-if="can.manage"
          :title="t('ui.ticket.assignment')"
          :description="t('ui.ticket.assignment_intro')"
        >
          <form class="flex flex-col gap-3" @submit.prevent="saveSettings">
            <div>
              <AppSelect
                v-model="settingsForm.department_id"
                :label="t('ui.ticket.department')"
                :options="[
                  { value: '', label: t('ui.ticket.no_department') },
                  ...options.departments,
                ]"
                :error="errors.department_id"
              />

              <!--
                A suggestion beside the field, never written into it: routing
                somebody's ticket is a decision with a queue and an SLA behind
                it, and a model inferring it is not somebody choosing it.
              -->
              <p v-if="suggestion" class="text-chrome text-content-muted mt-1">
                {{ t('ai.suggested', { model: suggestion.model, value: suggestion.label }) }}
              </p>

              <div v-if="ai.triage" class="mt-2">
                <AppButton size="sm" variant="ghost" :loading="triaging" @click="askForDepartment">
                  {{ t('ai.suggest_department') }}
                </AppButton>
              </div>
            </div>
            <AppSelect
              v-model="settingsForm.assigned_to"
              :label="t('ui.ticket.assigned_to')"
              :options="[{ value: '', label: t('ui.ticket.unassigned') }, ...options.agents]"
            />
            <AppSelect
              v-model="settingsForm.priority"
              :label="t('ui.ticket.priority')"
              :options="options.priorities"
            />
            <div>
              <AppButton type="submit" variant="primary" :loading="settingsForm.processing">
                {{ t('ui.ticket.save') }}
              </AppButton>
            </div>
          </form>
        </DetailSection>

        <DetailSection
          v-if="ticket.service || ticket.domain || ticket.invoice"
          :title="t('ui.ticket.related')"
          :description="t('ui.ticket.related_intro')"
        >
          <dl class="divide-line-subtle text-body divide-y">
            <div v-if="ticket.service" class="flex justify-between gap-4 py-2 first:pt-0">
              <dt class="text-content-muted">{{ t('ui.ticket.service') }}</dt>
              <dd>
                <Link
                  :href="`/admin/services/${ticket.serviceId}`"
                  class="underline-offset-4 hover:underline"
                >
                  {{ ticket.service }}
                </Link>
              </dd>
            </div>
            <div v-if="ticket.domain" class="flex justify-between gap-4 py-2">
              <dt class="text-content-muted">{{ t('ui.ticket.domain') }}</dt>
              <dd>
                <Link
                  :href="`/admin/domains/${ticket.domainId}`"
                  class="underline-offset-4 hover:underline"
                >
                  {{ ticket.domain }}
                </Link>
              </dd>
            </div>
            <div v-if="ticket.invoice" class="flex justify-between gap-4 py-2 last:pb-0">
              <dt class="text-content-muted">{{ t('ui.ticket.invoice') }}</dt>
              <dd>
                <Link
                  :href="`/admin/invoices/${ticket.invoiceId}`"
                  class="underline-offset-4 hover:underline"
                >
                  {{ ticket.invoice }}
                </Link>
              </dd>
            </div>
          </dl>
        </DetailSection>
      </aside>
    </div>
  </AdminLayout>
</template>
