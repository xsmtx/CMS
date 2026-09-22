<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ContactRow {
  id: string
  name: string
  email: string
  phone: string | null
  isPrimary: boolean
  portalAccess: boolean
  status: string
  twoFactor: boolean
  lastLoginAt: string | null
}

const props = defineProps<{
  customer: {
    id: string
    name: string
    companyName: string | null
    legalName: string | null
    taxId: string | null
    status: string
    currencyCode: string
    anonymized: boolean
    createdAt: string
    tags: { id: string; name: string }[]
  }
  contacts: ContactRow[]
  addresses: { id: string; type: string; line: string; isDefault: boolean }[]
  notes: {
    id: string
    body: string
    author: string | null
    customerVisible: boolean
    pinned: boolean
    createdAt: string
  }[]
  customFields: { key: string; label: string; value: unknown }[]
  can: { update: boolean; export: boolean; anonymize: boolean; impersonate: boolean }
}>()

const page = usePage()

const impersonating = ref<string | null>(null)
const showErasure = ref(false)

const impersonateForm = useForm({ reason: '' })
const erasureForm = useForm({ reason: '', confirmation: false })

function startImpersonation(contactId: string): void {
  impersonateForm.post(`/admin/contacts/${contactId}/impersonate`)
}

function eraseData(): void {
  erasureForm.post(`/admin/customers/${props.customer.id}/anonymize`)
}

function removeContact(contact: ContactRow): void {
  router.delete(`/admin/customers/${props.customer.id}/contacts/${contact.id}`, {
    preserveScroll: true,
  })
}

function formatTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : 'Never'
}
</script>

<template>
  <Head :title="customer.name" />

  <AdminLayout :heading="customer.name">
    <template v-if="page.props.flash?.status">
      <AppAlert tone="success" class="mb-5">{{ page.props.flash.status }}</AppAlert>
    </template>

    <AppAlert v-if="customer.anonymized" tone="info" class="mb-5">
      This customer's personal data has been erased. The commercial record remains so that invoices
      and service history stay intact.
    </AppAlert>

    <div class="mb-5 flex flex-wrap gap-2">
      <AppButton v-if="can.update" :href="`/admin/customers/${customer.id}/edit`" variant="primary">
        Edit
      </AppButton>
      <AppButton v-if="can.export" :href="`/admin/customers/${customer.id}/export`">
        Export data
      </AppButton>
      <AppButton v-if="can.anonymize" variant="danger" @click="showErasure = !showErasure">
        Erase personal data
      </AppButton>
    </div>

    <AppCard
      v-if="showErasure"
      title="Erase personal data"
      description="Irreversible. Contacts lose their names, addresses and access; the audit trail and the commercial record are kept."
      class="mb-5"
    >
      <form class="flex max-w-xl flex-col gap-4" @submit.prevent="eraseData">
        <AppTextarea
          v-model="erasureForm.reason"
          label="Reason"
          hint="Recorded in the audit trail. Say where the request came from."
          :error="erasureForm.errors.reason"
          :rows="3"
        />
        <AppCheckbox
          v-model="erasureForm.confirmation"
          label="I understand this cannot be undone"
        />
        <div>
          <AppButton type="submit" variant="danger" :loading="erasureForm.processing">
            Erase personal data
          </AppButton>
        </div>
      </form>
    </AppCard>

    <div class="grid gap-5 lg:grid-cols-3">
      <AppCard title="Profile" class="lg:col-span-1">
        <dl class="space-y-3 text-sm">
          <div class="flex justify-between gap-4">
            <dt class="text-content-muted">Status</dt>
            <dd>
              <AppBadge>{{ customer.status }}</AppBadge>
            </dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-content-muted">Legal name</dt>
            <dd>{{ customer.legalName ?? '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-content-muted">Tax id</dt>
            <dd class="font-mono text-[13px]">{{ customer.taxId ?? '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-content-muted">Currency</dt>
            <dd>{{ customer.currencyCode }}</dd>
          </div>
          <div v-for="field in customFields" :key="field.key" class="flex justify-between gap-4">
            <dt class="text-content-muted">{{ field.label }}</dt>
            <dd>{{ field.value ?? '—' }}</dd>
          </div>
        </dl>

        <div v-if="customer.tags.length > 0" class="mt-4 flex flex-wrap gap-1">
          <AppBadge v-for="tag in customer.tags" :key="tag.id">{{ tag.name }}</AppBadge>
        </div>
      </AppCard>

      <AppCard title="Contacts" class="lg:col-span-2">
        <template #actions>
          <AppButton size="sm" :href="`/admin/customers/${customer.id}/contacts/create`">
            Add contact
          </AppButton>
        </template>

        <EmptyState
          v-if="contacts.length === 0"
          title="No contacts yet"
          description="A customer needs at least one contact so invoices and support replies reach a person."
        />

        <ul v-else class="divide-line divide-y">
          <li v-for="contact in contacts" :key="contact.id" class="py-3 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-medium">
                  {{ contact.name }}
                  <AppBadge v-if="contact.isPrimary" tone="accent" class="ml-2">Primary</AppBadge>
                  <AppBadge v-if="!contact.portalAccess" class="ml-2">No portal access</AppBadge>
                </p>
                <p class="text-content-muted mt-0.5 text-xs">
                  {{ contact.email }} · last sign-in {{ formatTime(contact.lastLoginAt) }}
                </p>
              </div>

              <div class="flex items-center gap-3 text-xs">
                <Link
                  :href="`/admin/customers/${customer.id}/contacts/${contact.id}/edit`"
                  class="text-content-muted hover:text-content underline underline-offset-4"
                >
                  Edit
                </Link>
                <button
                  v-if="can.impersonate && contact.portalAccess"
                  type="button"
                  class="text-content-muted hover:text-content underline underline-offset-4"
                  @click="impersonating = impersonating === contact.id ? null : contact.id"
                >
                  View as
                </button>
                <button
                  v-if="!contact.isPrimary"
                  type="button"
                  class="text-danger underline underline-offset-4"
                  @click="removeContact(contact)"
                >
                  Delete
                </button>
              </div>
            </div>

            <form
              v-if="impersonating === contact.id"
              class="border-line mt-3 flex flex-col gap-3 rounded-[var(--radius-sm)] border p-3"
              @submit.prevent="startImpersonation(contact.id)"
            >
              <AppTextarea
                v-model="impersonateForm.reason"
                label="Why are you viewing this account?"
                hint="Recorded in the audit trail alongside your name."
                :error="impersonateForm.errors.reason"
                :rows="2"
              />
              <div>
                <AppButton type="submit" size="sm" variant="primary">
                  View as {{ contact.name }}
                </AppButton>
              </div>
            </form>
          </li>
        </ul>
      </AppCard>

      <AppCard title="Addresses" class="lg:col-span-1">
        <EmptyState
          v-if="addresses.length === 0"
          title="No addresses"
          description="Invoices snapshot the billing address at issue time, so one is needed before billing starts."
        />
        <ul v-else class="divide-line divide-y text-sm">
          <li v-for="address in addresses" :key="address.id" class="py-2 first:pt-0 last:pb-0">
            <AppBadge>{{ address.type }}</AppBadge>
            <p class="text-content-muted mt-1">{{ address.line }}</p>
          </li>
        </ul>
      </AppCard>

      <AppCard title="Notes" class="lg:col-span-2">
        <EmptyState
          v-if="notes.length === 0"
          title="No notes"
          description="Notes are internal unless marked visible, so a staff aside never appears in the customer's portal."
        />
        <ul v-else class="divide-line divide-y">
          <li v-for="note in notes" :key="note.id" class="py-3 text-sm first:pt-0 last:pb-0">
            <p>{{ note.body }}</p>
            <p class="text-content-muted mt-1 text-xs">
              {{ note.author ?? 'Unknown' }} · {{ formatTime(note.createdAt) }}
              <AppBadge v-if="note.customerVisible" tone="accent" class="ml-2">
                Visible to customer
              </AppBadge>
            </p>
          </li>
        </ul>
      </AppCard>
    </div>
  </AdminLayout>
</template>
