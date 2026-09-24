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
 *
 * The sections are `DetailSection`s with one exception: Sending is an
 * `AppCard`, because it is a sticky side panel carrying the primary action on
 * a long form, which is exactly what the design system keeps a framed surface
 * for. Everything else is a heading and a hairline.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppRichText from '../../../Components/AppRichText.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
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
  statusLabel: string
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

const { t } = useTranslations()

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

const maxMegabytes = computed(() => Math.round(props.attachmentRules.maxKilobytes / 1024))

function submit(): void {
  form.post('/admin/support', { forceFormData: true })
}
</script>

<template>
  <Head :title="t('ui.ticket_new.title')" />

  <AdminLayout :heading="t('ui.ticket_new.title')">
    <template #header>
      <PageHeader :title="t('ui.ticket_new.title')" :description="t('ui.ticket_new.intro')" />
    </template>

    <form
      class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,21rem)]"
      @submit.prevent="submit"
    >
      <div class="flex min-w-0 flex-col gap-8">
        <DetailSection :title="t('ui.ticket_new.client')">
          <template v-if="chosen" #actions>
            <AppButton type="button" variant="ghost" size="sm" @click="forget">
              {{ t('ui.ticket_new.change') }}
            </AppButton>
          </template>

          <div v-if="chosen">
            <p class="text-body font-medium">{{ chosen.name }}</p>
            <p v-if="chosen.email" class="text-content-muted text-chrome">{{ chosen.email }}</p>
          </div>

          <div v-else class="flex flex-col gap-3">
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <AppInput
                  v-model="term"
                  :label="t('ui.ticket_new.find_client')"
                  :hint="t('ui.ticket_new.find_client_hint')"
                  :error="form.errors.customer_id"
                  @keyup.enter="search"
                />
              </div>
              <AppButton type="button" @click="search">{{ t('ui.ticket_new.search') }}</AppButton>
            </div>

            <!--
              A framed list, because the results are an object sitting in the
              flow of a form rather than a section of it.
            -->
            <ul
              v-if="candidates.length > 0"
              class="border-line divide-line-subtle divide-y rounded-lg border"
            >
              <li v-for="candidate in candidates" :key="candidate.id">
                <button
                  type="button"
                  class="pressable hover:bg-surface-hover block w-full px-3 py-2 text-left transition-colors duration-(--duration-fast)"
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
        </DetailSection>

        <DetailSection
          v-if="chosen"
          :title="t('ui.ticket_new.copy_in')"
          :description="t('ui.ticket_new.copy_in_intro')"
        >
          <div class="flex items-end gap-2">
            <div class="flex-1">
              <AppInput
                v-model="ccDraft"
                :label="t('ui.ticket_new.email_address')"
                type="email"
                :error="form.errors.cc"
                @keyup.enter.prevent="addCc(ccDraft)"
              />
            </div>
            <AppButton type="button" @click="addCc(ccDraft)">{{
              t('ui.ticket_new.add')
            }}</AppButton>
          </div>

          <div v-if="contacts.length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <button
              v-for="contact in contacts"
              :key="contact.id"
              type="button"
              class="pressable border-line hover:border-line-strong text-chrome rounded-sm border px-2 py-1 disabled:opacity-50"
              :disabled="!contact.email || form.cc.includes(contact.email.toLowerCase())"
              @click="contact.email && addCc(contact.email)"
            >
              {{ contact.name }}
              <span v-if="contact.primary" class="text-content-subtle">
                · {{ t('ui.ticket_new.primary') }}
              </span>
            </button>
          </div>

          <ul v-if="form.cc.length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <li
              v-for="address in form.cc"
              :key="address"
              class="bg-surface-secondary text-chrome flex items-center gap-1.5 rounded-sm px-2 py-1"
            >
              {{ address }}
              <button
                type="button"
                class="text-content-subtle hover:text-danger"
                :aria-label="t('ui.ticket_new.remove_cc', { address })"
                @click="removeCc(address)"
              >
                ×
              </button>
            </li>
          </ul>
        </DetailSection>

        <DetailSection
          v-if="chosen && owned.length > 0"
          :title="t('ui.ticket_new.about')"
          :description="t('ui.ticket_new.about_intro')"
        >
          <!--
            Radios inside their own labels, so the whole row is a hit target
            without a click handler pretending to be one, and a screen reader
            hears one control per row rather than a table it has to interpret.
          -->
          <ul class="divide-line-subtle max-h-72 divide-y overflow-y-auto">
            <li v-for="row in owned" :key="row.id">
              <label
                class="hover:bg-surface-hover flex cursor-pointer items-start gap-3 px-2 py-2 transition-colors duration-(--duration-fast)"
                :class="about?.id === row.id ? 'bg-surface-selected' : ''"
              >
                <input
                  type="radio"
                  class="accent-brand mt-1 size-3.5 shrink-0"
                  :checked="about?.id === row.id"
                  @change="link(row)"
                />
                <span class="min-w-0 flex-1">
                  <span class="text-body block">{{ row.label }}</span>
                  <span v-if="row.detail" class="text-content-muted text-chrome block">
                    {{ row.detail }}
                  </span>
                </span>
                <span class="text-content-muted text-chrome shrink-0">
                  {{ t(`ui.ticket_new.kinds.${row.kind}`) }}
                </span>
                <span class="shrink-0">
                  <AppStatus :tone="statusTone(row.status)" :label="row.statusLabel" />
                </span>
              </label>
            </li>
          </ul>
        </DetailSection>

        <DetailSection :title="t('ui.ticket_new.ticket')">
          <div class="grid gap-5">
            <div class="grid gap-5 sm:grid-cols-2">
              <AppSelect
                v-model="form.department_id"
                :label="t('ui.ticket_new.department')"
                :options="[{ value: '', label: t('ui.ticket_new.unassigned') }, ...departments]"
                :error="form.errors.department_id"
              />
              <AppSelect
                v-model="form.priority"
                :label="t('ui.ticket_new.priority')"
                :options="priorities"
                :hint="t('ui.ticket_new.priority_hint')"
              />
            </div>

            <AppInput
              v-model="form.subject"
              :label="t('ui.ticket_new.subject')"
              :error="form.errors.subject"
              required
            />

            <AppRichText
              ref="editor"
              v-model="form.body"
              :label="t('ui.ticket_new.message')"
              :rows="12"
              :hint="t('ui.ticket_new.message_hint')"
              :error="form.errors.body"
            />

            <div>
              <label class="text-body font-medium" for="ticket-attachments">
                {{ t('ui.ticket_new.attachments') }}
              </label>
              <input
                id="ticket-attachments"
                type="file"
                multiple
                :accept="accept || undefined"
                class="text-content-muted text-chrome mt-1.5 block w-full"
                @change="onFiles"
              />
              <p class="text-content-muted text-chrome mt-1">
                {{ t('ui.ticket_new.attachment_rules', { megabytes: maxMegabytes }) }}
                <template v-if="attachmentRules.extensions.length > 0">
                  {{
                    t('ui.ticket_new.attachment_types', {
                      types: attachmentRules.extensions.join(', '),
                    })
                  }}
                </template>
              </p>
              <ul v-if="form.attachments.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                <li
                  v-for="file in form.attachments"
                  :key="file.name"
                  class="bg-surface-secondary text-chrome rounded-sm px-2 py-1"
                >
                  {{ file.name }}
                </li>
              </ul>
            </div>
          </div>
        </DetailSection>
      </div>

      <aside class="flex min-w-0 flex-col gap-8 lg:sticky lg:top-20 lg:self-start">
        <!--
          The one framed surface on the page. It carries the primary action and
          it is sticky, so it has to read as a thing that stays put while the
          form scrolls under it — which is what a frame says and a hairline
          does not.
        -->
        <AppCard :title="t('ui.ticket_new.sending')">
          <AppCheckbox
            v-model="form.send_email"
            :label="t('ui.ticket_new.send_email')"
            :description="t('ui.ticket_new.send_email_hint')"
          />

          <div v-if="about" class="border-line text-chrome mt-4 border-t pt-3">
            <p class="text-content-muted">{{ t('ui.ticket_new.about_label') }}</p>
            <p class="mt-0.5">{{ about.label }}</p>
          </div>

          <div class="mt-5 flex gap-2">
            <AppButton
              type="submit"
              variant="primary"
              :loading="form.processing"
              :disabled="form.customer_id === ''"
            >
              {{ t('ui.ticket_new.open') }}
            </AppButton>
            <AppButton type="button" variant="ghost" href="/admin/support">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </AppCard>

        <DetailSection
          v-if="canned.length > 0"
          :title="t('ui.ticket_new.canned')"
          :description="t('ui.ticket_new.canned_intro')"
        >
          <ul class="divide-line-subtle max-h-56 divide-y overflow-y-auto">
            <li
              v-for="reply in canned"
              :key="reply.id"
              class="flex items-center justify-between gap-2 py-2"
            >
              <span class="text-body truncate">{{ reply.name }}</span>
              <AppButton size="sm" variant="ghost" @click="insertReply(reply.body)">
                {{ t('ui.ticket_new.insert') }}
              </AppButton>
            </li>
          </ul>
        </DetailSection>

        <DetailSection
          v-if="articles.length > 0"
          :title="t('ui.ticket_new.knowledgebase')"
          :description="t('ui.ticket_new.knowledgebase_intro')"
        >
          <AppInput v-model="articleFilter" :label="t('ui.ticket_new.find_article')" />

          <ul class="divide-line-subtle mt-3 max-h-56 divide-y overflow-y-auto">
            <li
              v-for="article in matchingArticles"
              :key="article.slug"
              class="flex items-center justify-between gap-2 py-2"
            >
              <span class="min-w-0">
                <span class="text-body block truncate">{{ article.title }}</span>
                <AppBadge v-if="!article.public" tone="warning">
                  {{ t('ui.ticket_new.staff_only') }}
                </AppBadge>
              </span>
              <AppButton size="sm" variant="ghost" @click="insertArticle(article)">
                {{ t('ui.ticket_new.insert') }}
              </AppButton>
            </li>
          </ul>
        </DetailSection>
      </aside>
    </form>
  </AdminLayout>
</template>
