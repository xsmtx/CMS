<script setup lang="ts">
/**
 * One client: who they are, who speaks for them, and what the staff know.
 *
 * Laid out as a resource page (enterprise-cms-ux, "Detail pages"):
 *
 * 1. **Identity** — name, status, the facts that identify the account, and
 *    the everyday actions. Nothing destructive up here.
 * 2. **Tabs** — Overview, Contacts, Notes. Contacts is a table of its own
 *    because it has six columns of real information and row actions; the
 *    overview only needs to say who the primary contact is.
 * 3. **Danger zone** — erasing personal data, last on the page, behind a
 *    reason and the client's name typed out.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppMenu from '../../../Components/AppMenu.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTabs from '../../../Components/AppTabs.vue'
import DangerZone from '../../../Components/DangerZone.vue'
import DangerZoneRow from '../../../Components/DangerZoneRow.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

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

const { t } = useTranslations()

const tab = ref('overview')

const tabs = computed(() => [
  { key: 'overview', label: t('ui.client.tab_overview') },
  { key: 'contacts', label: t('ui.client.tab_contacts'), count: props.contacts.length },
  { key: 'notes', label: t('ui.client.tab_notes'), count: props.notes.length },
])

// --- facts -------------------------------------------------------------------

function capitalise(value: string): string {
  return value.replace(/_/g, ' ').replace(/^./, (first) => first.toUpperCase())
}

/** The server's own word for a status or address type, readable if missing. */
function word(group: 'statuses' | 'address_types', value: string): string {
  const key = `crm.${group}.${value}`
  const translated = t(key)

  return translated === key ? capitalise(value) : translated
}

const profile = computed<DescriptionItem[]>(() => [
  { key: 'status', label: t('ui.client.status') },
  { key: 'company', label: t('ui.client.company'), value: props.customer.companyName },
  { key: 'legal', label: t('ui.client.legal_name'), value: props.customer.legalName },
  { key: 'tax', label: t('ui.client.tax_number'), value: props.customer.taxId, mono: true },
  { key: 'currency', label: t('ui.client.currency'), value: props.customer.currencyCode },
  {
    key: 'created',
    label: t('ui.client.client_since'),
    value: formatDate(props.customer.createdAt),
  },
  ...props.customFields.map((field) => ({
    key: `custom-${field.key}`,
    label: field.label,
    value: field.value === null || field.value === undefined ? null : String(field.value),
  })),
])

const primaryContact = computed(() => props.contacts.find((contact) => contact.isPrimary) ?? null)

const latestNotes = computed(() =>
  [...props.notes]
    .sort((a, b) => Number(b.pinned) - Number(a.pinned) || b.createdAt.localeCompare(a.createdAt))
    .slice(0, 3),
)

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString() : '—'
}

function formatTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : t('ui.common.never')
}

// --- contacts ----------------------------------------------------------------

const contactColumns = computed<TableColumn[]>(() => [
  { key: 'name', label: t('ui.client.col_contact') },
  { key: 'email', label: t('ui.client.col_email') },
  { key: 'phone', label: t('ui.client.col_phone'), optional: true },
  { key: 'portal', label: t('ui.client.col_portal') },
  { key: 'twoFactor', label: t('ui.client.col_two_factor'), optional: true },
  { key: 'lastLogin', label: t('ui.client.col_last_sign_in'), optional: true },
  { key: 'actions', label: '' },
])

/**
 * Viewing a client's portal as them is high-risk: it is a staff member
 * seeing what the customer sees, and the reason is written to the audit
 * record beside their name. The same ladder as every other consequential
 * action, rather than an inline form of its own.
 */
const impersonating = ref<ContactRow | null>(null)
const impersonateForm = useForm({ reason: '' })

function startImpersonation(reason: string | null): void {
  if (impersonating.value === null) return

  impersonateForm.reason = reason ?? ''
  impersonateForm.post(`/admin/contacts/${impersonating.value.id}/impersonate`, {
    onFinish: () => (impersonating.value = null),
  })
}

/** Removing a contact takes away a person's access: consequential, so asked. */
const removing = ref<ContactRow | null>(null)
const removingBusy = ref(false)

function removeContact(): void {
  if (removing.value === null) return

  removingBusy.value = true
  router.delete(`/admin/customers/${props.customer.id}/contacts/${removing.value.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removingBusy.value = false
      removing.value = null
    },
  })
}

// --- erasure -----------------------------------------------------------------

const erasing = ref(false)
const erasureForm = useForm({ reason: '', confirmation: false })

/**
 * The server still wants its own `confirmation` flag — it is the contract
 * the erasure endpoint was written against — so the dialog's typed name and
 * reason are what set it, rather than a second checkbox.
 */
function eraseData(reason: string | null): void {
  erasureForm.reason = reason ?? ''
  erasureForm.confirmation = true
  erasureForm.post(`/admin/customers/${props.customer.id}/anonymize`, {
    onFinish: () => (erasing.value = false),
  })
}
</script>

<template>
  <Head :title="customer.name" />

  <AdminLayout :heading="customer.name">
    <template #header>
      <PageHeader :title="customer.name">
        <template #status>
          <AppStatus
            :tone="statusTone(customer.status)"
            :label="word('statuses', customer.status)"
          />
        </template>

        <template #meta>
          <AppCopy
            :value="customer.id"
            :label="customer.id.slice(-8)"
            :noun="t('ui.clients.client_id')"
            mono
          />
          <template v-if="customer.companyName">
            <span aria-hidden="true">·</span>
            <span>{{ customer.companyName }}</span>
          </template>
          <span aria-hidden="true">·</span>
          <span>{{ customer.currencyCode }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ t('ui.client.since', { date: formatDate(customer.createdAt) }) }}</span>
          <template v-if="customer.tags.length > 0">
            <span aria-hidden="true">·</span>
            <AppBadge v-for="tag in customer.tags" :key="tag.id">{{ tag.name }}</AppBadge>
          </template>
        </template>

        <template #actions>
          <AppButton
            v-if="can.export"
            :href="`/admin/customers/${customer.id}/export`"
            icon="download"
          >
            {{ t('ui.client.export') }}
          </AppButton>
          <AppButton
            v-if="can.update"
            :href="`/admin/customers/${customer.id}/edit`"
            variant="primary"
            icon="edit"
          >
            {{ t('ui.client.edit') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <AppAlert v-if="customer.anonymized" tone="info" class="mb-5">
      {{ t('ui.client.anonymized') }}
    </AppAlert>

    <AppTabs v-model="tab" :tabs="tabs" :label="t('ui.client.tabs_label')" query="tab">
      <template #default="{ active }">
        <!-- Overview: the facts on the left, the people on the right. -->
        <div
          v-if="active === 'overview'"
          class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]"
        >
          <div class="flex min-w-0 flex-col gap-8">
            <DetailSection :title="t('ui.client.profile')">
              <DescriptionList :items="profile">
                <template #status>
                  <AppStatus
                    :tone="statusTone(customer.status)"
                    :label="word('statuses', customer.status)"
                  />
                </template>
              </DescriptionList>
            </DetailSection>

            <DetailSection :title="t('ui.client.addresses')">
              <EmptyState
                v-if="addresses.length === 0"
                variant="plain"
                icon="document"
                :title="t('ui.client.no_address')"
                :description="t('ui.client.no_address_detail')"
              />
              <ul v-else class="divide-line-subtle divide-y">
                <li
                  v-for="address in addresses"
                  :key="address.id"
                  class="grid grid-cols-[minmax(7rem,12rem)_minmax(0,1fr)] gap-4 py-2 first:pt-0"
                >
                  <span class="text-content-muted">
                    {{ word('address_types', address.type) }}
                    <span v-if="address.isDefault" class="text-content-subtle">
                      · {{ t('ui.client.default_address') }}
                    </span>
                  </span>
                  <span>{{ address.line }}</span>
                </li>
              </ul>
            </DetailSection>
          </div>

          <aside class="flex min-w-0 flex-col gap-8">
            <DetailSection :title="t('ui.client.primary_contact')">
              <template v-if="contacts.length > 1" #actions>
                <button
                  type="button"
                  class="text-brand text-chrome underline-offset-4 hover:underline"
                  @click="tab = 'contacts'"
                >
                  {{ t('ui.client.all_contacts', { count: contacts.length }) }}
                </button>
              </template>

              <div v-if="primaryContact" class="flex flex-col gap-0.5">
                <p class="font-medium">{{ primaryContact.name }}</p>
                <p class="text-content-muted">{{ primaryContact.email }}</p>
                <p v-if="primaryContact.phone" class="text-content-muted">
                  {{ primaryContact.phone }}
                </p>
                <p class="text-content-subtle text-chrome mt-1">
                  {{
                    t('ui.client.last_sign_in', { time: formatTime(primaryContact.lastLoginAt) })
                  }}
                </p>
              </div>
              <EmptyState
                v-else
                variant="plain"
                icon="user"
                :title="t('ui.client.no_primary')"
                :description="t('ui.client.no_contacts_detail')"
              />
            </DetailSection>

            <DetailSection :title="t('ui.client.latest_notes')">
              <template v-if="notes.length > 3" #actions>
                <button
                  type="button"
                  class="text-brand text-chrome underline-offset-4 hover:underline"
                  @click="tab = 'notes'"
                >
                  {{ t('ui.client.all_notes', { count: notes.length }) }}
                </button>
              </template>

              <ul v-if="latestNotes.length > 0" class="divide-line-subtle divide-y">
                <li v-for="note in latestNotes" :key="note.id" class="py-2 first:pt-0">
                  <p class="line-clamp-3">{{ note.body }}</p>
                  <p class="text-content-subtle text-chrome mt-0.5">
                    <template v-if="note.pinned">{{ t('ui.client.pinned') }} · </template>
                    {{ note.author ?? t('ui.common.unknown') }} · {{ formatTime(note.createdAt) }}
                  </p>
                </li>
              </ul>
              <EmptyState
                v-else
                variant="plain"
                icon="note"
                :title="t('ui.client.no_notes')"
                :description="t('ui.client.no_notes_detail')"
              />
            </DetailSection>
          </aside>
        </div>

        <!-- Contacts: a table, because each contact has six facts and actions. -->
        <DetailSection
          v-else-if="active === 'contacts'"
          :title="t('ui.client.tab_contacts')"
          :description="t('ui.client.contacts_description')"
          :divided="false"
        >
          <template #actions>
            <AppButton
              size="sm"
              icon="add"
              :href="`/admin/customers/${customer.id}/contacts/create`"
            >
              {{ t('ui.client.add_contact') }}
            </AppButton>
          </template>

          <AppTable
            v-if="contacts.length > 0"
            name="admin.customer.contacts"
            :columns="contactColumns"
            noun="contact"
          >
            <tr v-for="contact in contacts" :key="contact.id">
              <td data-col="name">
                <span class="font-medium">{{ contact.name }}</span>
                <AppBadge v-if="contact.isPrimary" tone="brand" class="ml-2">
                  {{ t('ui.client.primary') }}
                </AppBadge>
              </td>
              <td data-col="email" class="text-content-muted">{{ contact.email }}</td>
              <td data-col="phone" class="text-content-muted">{{ contact.phone ?? '—' }}</td>
              <td data-col="portal">
                <AppStatus
                  :tone="contact.portalAccess ? 'healthy' : 'neutral'"
                  :label="
                    contact.portalAccess
                      ? t('ui.client.portal_allowed')
                      : t('ui.client.portal_none')
                  "
                />
              </td>
              <td data-col="twoFactor" class="text-content-muted">
                {{ contact.twoFactor ? t('ui.common.on') : t('ui.common.off') }}
              </td>
              <td data-col="lastLogin" class="text-content-muted whitespace-nowrap tabular-nums">
                {{ formatTime(contact.lastLoginAt) }}
              </td>
              <td data-col="actions" class="w-0 text-right whitespace-nowrap">
                <!-- Revealed on hover and focus (`.row-actions`), always on
                     touch: a column of buttons on every row buries the data. -->
                <span class="row-actions inline-flex items-center gap-1">
                  <AppButton
                    size="sm"
                    variant="ghost"
                    :href="`/admin/customers/${customer.id}/contacts/${contact.id}/edit`"
                  >
                    {{ t('ui.common.edit') }}
                  </AppButton>
                  <AppMenu
                    v-if="(can.impersonate && contact.portalAccess) || !contact.isPrimary"
                    :label="t('ui.client.more_actions', { name: contact.name })"
                    icon="more"
                    align="end"
                    width="13rem"
                  >
                    <button
                      v-if="can.impersonate && contact.portalAccess"
                      type="button"
                      role="menuitem"
                      class="hover:bg-surface-hover text-body block w-full rounded-sm px-2 py-1.5 text-left"
                      @click="impersonating = contact"
                    >
                      {{
                        t('ui.client.view_as', { name: contact.name.split(' ')[0] ?? contact.name })
                      }}
                    </button>
                    <button
                      v-if="!contact.isPrimary"
                      type="button"
                      role="menuitem"
                      class="hover:bg-surface-hover text-danger text-body block w-full rounded-sm px-2 py-1.5 text-left"
                      @click="removing = contact"
                    >
                      {{ t('ui.client.remove') }}
                    </button>
                  </AppMenu>
                </span>
              </td>
            </tr>
          </AppTable>

          <EmptyState
            v-else
            icon="user"
            :title="t('ui.client.no_contacts')"
            :description="t('ui.client.no_contacts_detail')"
          />
        </DetailSection>

        <!-- Notes -->
        <DetailSection
          v-else
          :title="t('ui.client.tab_notes')"
          :description="t('ui.client.notes_description')"
        >
          <ul v-if="notes.length > 0" class="divide-line-subtle max-w-[80ch] divide-y">
            <li v-for="note in notes" :key="note.id" class="py-3 first:pt-0">
              <p class="whitespace-pre-line">{{ note.body }}</p>
              <p class="text-content-subtle text-chrome mt-1 flex flex-wrap items-center gap-x-2">
                <span v-if="note.pinned">{{ t('ui.client.pinned') }}</span>
                <span v-if="note.pinned" aria-hidden="true">·</span>
                <span>{{ note.author ?? t('ui.common.unknown') }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ formatTime(note.createdAt) }}</span>
                <AppBadge v-if="note.customerVisible" tone="brand">
                  {{ t('ui.client.visible_to_customer') }}
                </AppBadge>
              </p>
            </li>
          </ul>
          <EmptyState
            v-else
            variant="plain"
            icon="note"
            :title="t('ui.client.no_notes')"
            :description="t('ui.client.no_notes_detail')"
          />
        </DetailSection>
      </template>
    </AppTabs>

    <DangerZone v-if="can.anonymize && !customer.anonymized">
      <DangerZoneRow :title="t('ui.client.erase_title')" :description="t('ui.client.erase_detail')">
        <AppButton variant="danger-subtle" @click="erasing = true">
          {{ t('ui.client.erase_button') }}
        </AppButton>
      </DangerZoneRow>
    </DangerZone>

    <AppConfirm
      v-model:open="erasing"
      level="destructive"
      :title="t('ui.client.erase_confirm_title')"
      :description="t('ui.client.erase_confirm_detail')"
      :confirm-label="t('ui.client.erase_confirm')"
      :phrase="customer.name"
      :busy="erasureForm.processing"
      @confirm="eraseData"
    />

    <AppConfirm
      :open="impersonating !== null"
      level="high-risk"
      :title="t('ui.client.view_as_title', { name: impersonating?.name ?? '' })"
      :description="t('ui.client.view_as_detail')"
      :confirm-label="t('ui.client.view_as_confirm')"
      :busy="impersonateForm.processing"
      @update:open="(value: boolean) => (impersonating = value ? impersonating : null)"
      @confirm="startImpersonation"
    />

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('ui.client.remove_title', { name: removing?.name ?? '' })"
      :description="t('ui.client.remove_detail')"
      :confirm-label="t('ui.client.remove_confirm')"
      :busy="removingBusy"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="removeContact"
    />

    <p v-if="impersonateForm.errors.reason" class="text-danger text-chrome mt-2" role="alert">
      {{ impersonateForm.errors.reason }}
    </p>
    <p v-if="erasureForm.errors.reason" class="text-danger text-chrome mt-2" role="alert">
      {{ erasureForm.errors.reason }}
    </p>
  </AdminLayout>
</template>
