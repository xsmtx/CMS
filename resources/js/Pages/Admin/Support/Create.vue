<script setup lang="ts">
/**
 * Open a ticket on a customer's behalf.
 *
 * The call that starts "I rang about this last week" ends with an operator
 * typing it into the queue. It goes through the same use case the portal
 * uses, so the clock, the notification and the department's rules are
 * identical — a second path that opened tickets differently would drift,
 * and the drift would be invisible until somebody measured response times.
 *
 * Everything on this screen is what the desk needs while the customer is
 * still on the phone: who they are, what they own, what has already been
 * written down for this question, and where the answer is documented.
 *
 * **Send email is checked by default.** The ordinary case is that the
 * customer should hear their ticket exists. Unchecked is for the call the
 * desk has already answered — the row is written either way, so what is
 * skipped is the message, not the history.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppRichText from '../../../Components/AppRichText.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface Candidate {
  id: string
  name: string
  email: string | null
  currency: string
}

interface ContactRow {
  id: string
  name: string
  email: string | null
  primary: boolean
}

interface OwnedRow {
  kind: 'service' | 'domain' | 'invoice'
  id: string
  label: string
  detail: string | null
  status: string
}

interface Article {
  title: string
  slug: string
  url: string
  public: boolean
}

const props = defineProps<{
  departments: { value: string; label: string }[]
  priorities: { value: string; label: string }[]
  canned: { id: string; name: string; body: string }[]
  articles: Article[]
  candidates: Candidate[]
  chosen: { id: string; name: string; email: string | null } | null
  contacts: ContactRow[]
  owned: OwnedRow[]
  attachmentRules: { extensions: string[]; maxKilobytes: number }
}>()

const form = useForm<{
  customer_id: string
  department_id: string
  subject: string
  body: string
  priority: string
  cc: string[]
  send_email: boolean
  service_id: string
  domain_id: string
  invoice_id: string
  attachments: File[]
}>({
  customer_id: props.chosen?.id ?? '',
  department_id: '',
  subject: '',
  body: '',
  priority: 'normal',
  cc: [],
  send_email: true,
  service_id: '',
  domain_id: '',
  invoice_id: '',
  attachments: [],
})

/**
 * The client picker: a partial reload of this same screen.
 *
 * Not an endpoint of its own — the authorization, the boundary and the
 * presenter are already here, and a second door into the same data is a
 * second place to get one of those three wrong.
 */
const term = ref('')

function search(): void {
  router.get(
    '/admin/support/create',
    { q: term.value, customer: form.customer_id },
    { preserveState: true, preserveScroll: true, replace: true, only: ['candidates'] },
  )
}

function choose(candidate: Candidate): void {
  form.customer_id = candidate.id

  router.get(
    '/admin/support/create',
    { customer: candidate.id },
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: ['chosen', 'contacts', 'owned'],
    },
  )
}

function forget(): void {
  form.customer_id = ''
  form.cc = []
  form.service_id = ''
  form.domain_id = ''
  form.invoice_id = ''

  router.get(
    '/admin/support/create',
    {},
    {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: ['chosen', 'contacts', 'owned'],
    },
  )
}

// Everything on the account is a CC the desk can reach for without
// remembering an address.
watch(
  () => props.chosen,
  (chosen) => {
    if (chosen) form.customer_id = chosen.id
  },
)

const ccDraft = ref('')

function addCc(address: string): void {
  const value = address.trim().toLowerCase()

  if (value === '' || form.cc.includes(value)) return

  form.cc = [...form.cc, value]
  ccDraft.value = ''
}

function removeCc(address: string): void {
  form.cc = form.cc.filter((one) => one !== address)
}

/** One thing at a time: a ticket is about a service, or a domain, or a bill. */
function link(row: OwnedRow): void {
  const already = about.value?.id === row.id

  form.service_id = !already && row.kind === 'service' ? row.id : ''
  form.domain_id = !already && row.kind === 'domain' ? row.id : ''
  form.invoice_id = !already && row.kind === 'invoice' ? row.id : ''
}

const about = computed(
  () =>
    props.owned.find(
      (row) =>
        row.id === form.service_id || row.id === form.domain_id || row.id === form.invoice_id,
    ) ?? null,
)

const editor = ref<InstanceType<typeof AppRichText> | null>(null)

function insertReply(body: string): void {
  editor.value?.insert(body)
}

function insertArticle(article: Article): void {
  editor.value?.insert(`[${article.title}](${article.url})`)
}

const articleFilter = ref('')

const matchingArticles = computed(() => {
  const needle = articleFilter.value.trim().toLowerCase()

  if (needle === '') return props.articles.slice(0, 8)

  return props.articles.filter((one) => one.title.toLowerCase().includes(needle)).slice(0, 8)
})

function onFiles(event: Event): void {
  form.attachments = Array.from((event.target as HTMLInputElement).files ?? [])
}

const accept = computed(() =>
  props.attachmentRules.extensions.map((extension) => `.${extension}`).join(','),
)

function submit(): void {
  form.post('/admin/support', { forceFormData: true })
}
</script>

<template>
  <Head title="Open new ticket" />

  <AdminLayout
    heading="Open New Ticket"
    description="Opened on the account rather than on one person, so the reply reaches whoever the customer asked it to."
  >
    <form class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem]" @submit.prevent="submit">
      <div class="flex flex-col gap-6">
        <AppCard title="Client">
          <div v-if="chosen" class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-body font-medium">{{ chosen.name }}</p>
              <p v-if="chosen.email" class="text-content-muted text-chrome">{{ chosen.email }}</p>
            </div>
            <AppButton type="button" variant="ghost" size="sm" @click="forget">Change</AppButton>
          </div>

          <div v-else class="flex flex-col gap-3">
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <AppInput
                  v-model="term"
                  label="Find a client"
                  hint="Name, company or email. % anchors a term."
                  :error="form.errors.customer_id"
                  @keyup.enter="search"
                />
              </div>
              <AppButton type="button" @click="search">Search</AppButton>
            </div>

            <ul
              v-if="candidates.length > 0"
              class="border-line divide-line divide-y rounded-md border"
            >
              <li v-for="candidate in candidates" :key="candidate.id">
                <button
                  type="button"
                  class="pressable hover:bg-surface-secondary block w-full px-3 py-2 text-left"
                  @click="choose(candidate)"
                >
                  <span class="text-body block font-medium">{{ candidate.name }}</span>
                  <span v-if="candidate.email" class="text-content-muted text-chrome block">
                    {{ candidate.email }}
                  </span>
                </button>
              </li>
            </ul>
          </div>
        </AppCard>

        <AppCard
          v-if="chosen"
          title="Copy in"
          description="Addresses that are not on the account: the developer they hired, the accounts mailbox."
        >
          <div class="flex items-end gap-2">
            <div class="flex-1">
              <AppInput
                v-model="ccDraft"
                label="Email address"
                type="email"
                :error="form.errors.cc"
                @keyup.enter.prevent="addCc(ccDraft)"
              />
            </div>
            <AppButton type="button" @click="addCc(ccDraft)">Add</AppButton>
          </div>

          <div v-if="contacts.length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <button
              v-for="contact in contacts"
              :key="contact.id"
              type="button"
              class="pressable border-line hover:border-line-strong text-chrome rounded-full border px-2.5 py-1"
              :disabled="!contact.email || form.cc.includes(contact.email.toLowerCase())"
              @click="contact.email && addCc(contact.email)"
            >
              {{ contact.name }}
              <span v-if="contact.primary" class="text-content-subtle">· primary</span>
            </button>
          </div>

          <ul v-if="form.cc.length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <li
              v-for="address in form.cc"
              :key="address"
              class="bg-surface-secondary text-chrome flex items-center gap-1.5 rounded-full px-2.5 py-1"
            >
              {{ address }}
              <button
                type="button"
                class="text-content-subtle hover:text-danger"
                :aria-label="`Remove ${address}`"
                @click="removeCc(address)"
              >
                ×
              </button>
            </li>
          </ul>
        </AppCard>

        <AppCard
          v-if="chosen && owned.length > 0"
          title="What it is about"
          description="A ticket linked to the thing it concerns is a ticket the next agent does not have to ask 'which one' about."
        >
          <div class="max-h-72 overflow-y-auto">
            <table class="text-body w-full text-left">
              <tbody class="divide-line divide-y">
                <tr
                  v-for="row in owned"
                  :key="row.id"
                  class="hover:bg-surface-secondary cursor-pointer transition-colors duration-(--duration-fast)"
                  :class="about?.id === row.id ? 'bg-surface-secondary' : ''"
                  @click="link(row)"
                >
                  <td class="py-2 pr-3">
                    <input
                      type="radio"
                      class="accent-brand size-3.5"
                      :checked="about?.id === row.id"
                      :aria-label="row.label"
                      @change="link(row)"
                    />
                  </td>
                  <td class="py-2 pr-3">
                    <span class="block">{{ row.label }}</span>
                    <span v-if="row.detail" class="text-content-muted text-chrome block">
                      {{ row.detail }}
                    </span>
                  </td>
                  <td class="text-content-muted text-chrome py-2 pr-3 capitalize">
                    {{ row.kind }}
                  </td>
                  <td class="py-2 text-right">
                    <AppStatus :tone="statusTone(row.status)" :label="row.status" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </AppCard>

        <AppCard title="The ticket">
          <div class="grid gap-5">
            <div class="grid gap-5 sm:grid-cols-2">
              <AppSelect
                v-model="form.department_id"
                label="Department"
                :options="[{ value: '', label: 'Unassigned' }, ...departments]"
                :error="form.errors.department_id"
              />
              <AppSelect
                v-model="form.priority"
                label="Priority"
                :options="priorities"
                hint="Priority scales the department's promise; it does not replace it."
              />
            </div>

            <AppInput
              v-model="form.subject"
              label="Subject"
              :error="form.errors.subject"
              required
            />

            <AppRichText
              ref="editor"
              v-model="form.body"
              label="Message"
              :rows="12"
              hint="Markdown. What you type is what is stored; the thread renders it."
              :error="form.errors.body"
            />

            <div>
              <label class="text-body font-medium" for="ticket-attachments">Attachments</label>
              <input
                id="ticket-attachments"
                type="file"
                multiple
                :accept="accept || undefined"
                class="text-content-muted text-chrome mt-1.5 block w-full"
                @change="onFiles"
              />
              <p class="text-content-muted text-chrome mt-1">
                Up to 10 files, {{ Math.round(attachmentRules.maxKilobytes / 1024) }} MB each.
                <template v-if="attachmentRules.extensions.length > 0">
                  Allowed: {{ attachmentRules.extensions.join(', ') }}.
                </template>
              </p>
              <ul v-if="form.attachments.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                <li
                  v-for="file in form.attachments"
                  :key="file.name"
                  class="bg-surface-secondary text-chrome rounded-full px-2.5 py-1"
                >
                  {{ file.name }}
                </li>
              </ul>
            </div>
          </div>
        </AppCard>
      </div>

      <aside class="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
        <AppCard title="Sending">
          <AppCheckbox
            v-model="form.send_email"
            label="Send email"
            description="Off for a call you have already answered. The ticket is written either way."
          />

          <div v-if="about" class="border-line text-chrome mt-4 border-t pt-3">
            <p class="text-content-muted">About</p>
            <p class="mt-0.5">{{ about.label }}</p>
          </div>

          <div class="mt-5 flex gap-2">
            <AppButton
              type="submit"
              variant="primary"
              :loading="form.processing"
              :disabled="form.customer_id === ''"
            >
              Open ticket
            </AppButton>
            <Link href="/admin/support">
              <AppButton type="button" variant="ghost">Cancel</AppButton>
            </Link>
          </div>
        </AppCard>

        <AppCard
          v-if="canned.length > 0"
          title="Predefined replies"
          description="What the desk has already written down for this question."
        >
          <ul class="divide-line max-h-56 divide-y overflow-y-auto">
            <li
              v-for="reply in canned"
              :key="reply.id"
              class="flex items-center justify-between gap-2 py-2"
            >
              <span class="text-body truncate">{{ reply.name }}</span>
              <AppButton size="sm" variant="ghost" @click="insertReply(reply.body)">
                Insert
              </AppButton>
            </li>
          </ul>
        </AppCard>

        <AppCard
          v-if="articles.length > 0"
          title="Knowledgebase"
          description="Inserted as a link. Staff-only articles say so — one in a customer thread is a mistake you can see yourself making."
        >
          <AppInput v-model="articleFilter" label="Find an article" />

          <ul class="divide-line mt-3 max-h-56 divide-y overflow-y-auto">
            <li
              v-for="article in matchingArticles"
              :key="article.slug"
              class="flex items-center justify-between gap-2 py-2"
            >
              <span class="min-w-0">
                <span class="text-body block truncate">{{ article.title }}</span>
                <AppBadge v-if="!article.public" tone="warning">Staff only</AppBadge>
              </span>
              <AppButton size="sm" variant="ghost" @click="insertArticle(article)">
                Insert
              </AppButton>
            </li>
          </ul>
        </AppCard>
      </aside>
    </form>
  </AdminLayout>
</template>
