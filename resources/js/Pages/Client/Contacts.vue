<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppBadge from '../../Components/AppBadge.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppConfirm from '../../Components/AppConfirm.vue'
import AppInput from '../../Components/AppInput.vue'
import AppTable from '../../Components/AppTable.vue'
import AppTableRow from '../../Components/AppTableRow.vue'
import AppCard from '../../Components/AppCard.vue'
import { type TableColumn } from '../../Components/tableContext'
import { useTranslations } from '../../composables/useTranslations'
import ClientLayout from '../../Layouts/ClientLayout.vue'

interface ContactRow {
  id: string
  firstName: string
  lastName: string
  name: string
  email: string
  phone: string | null
  isPrimary: boolean
  portalAccess: boolean
  isMe: boolean
}

defineProps<{
  contacts: ContactRow[]
  can: { manage: boolean }
}>()

const page = usePage()
const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'name', label: t('portal.contacts.name'), sticky: true },
  { key: 'access', label: t('portal.contacts.access') },
  { key: 'actions', label: '' },
]

const adding = ref(false)

/**
 * Removing somebody used to happen on the first click, from a red word.
 *
 * What goes is a person's access to the account, which is why the sentence
 * says that and what survives it — level 2, because nothing they wrote is
 * lost with them.
 */
const removing = ref<ContactRow | null>(null)

const form = useForm({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  portal_access: false,
  notify_invoices: true,
  notify_support: true,
  notify_product: true,
  notify_marketing: false,
})

function add(): void {
  form.post('/client/contacts', {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      adding.value = false
    },
  })
}

function remove(): void {
  const contact = removing.value

  if (contact === null) return

  router.delete(`/client/contacts/${contact.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}
</script>

<template>
  <Head :title="t('portal.contacts.title')" />

  <ClientLayout
    :heading="t('portal.contacts.title')"
    :description="t('portal.contacts.description')"
  >
    <template v-if="can.manage" #actions>
      <AppButton :icon="adding ? undefined : 'add'" @click="adding = !adding">
        {{ adding ? t('ui.confirm.cancel') : t('portal.contacts.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="page.props.flash?.status" tone="success">
        {{ page.props.flash.status }}
      </AppAlert>

      <AppAlert v-if="!can.manage" tone="info">
        {{ t('portal.contacts.owner_only') }}
      </AppAlert>

      <AppCard flush :title="t('portal.contacts.people')">
        <AppTable flush name="portal-contacts" :columns="COLUMNS">
          <AppTableRow v-for="contact in contacts" :key="contact.id">
            <td data-col="name">
              <span class="font-medium">{{ contact.name }}</span>
              <AppBadge v-if="contact.isPrimary" tone="brand" class="ml-2">
                {{ t('portal.contacts.account_owner') }}
              </AppBadge>
              <AppBadge v-if="contact.isMe" class="ml-2">{{ t('portal.contacts.you') }}</AppBadge>
              <span class="text-content-muted text-chrome block">{{ contact.email }}</span>
            </td>
            <td data-col="access" class="text-content-muted">
              {{
                contact.portalAccess
                  ? t('portal.contacts.signs_in')
                  : t('portal.contacts.cannot_sign_in')
              }}
            </td>
            <td data-col="actions" class="text-right">
              <span
                v-if="can.manage && !contact.isPrimary && !contact.isMe"
                class="row-actions inline-flex"
              >
                <AppButton size="sm" variant="danger-subtle" @click="removing = contact">
                  {{ t('portal.contacts.remove') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>
      </AppCard>

      <AppCard
        v-if="adding && can.manage"
        :title="t('portal.contacts.add_title')"
        :description="t('portal.contacts.add_hint')"
      >
        <form class="grid max-w-xl gap-5" @submit.prevent="add">
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="form.first_name"
              :label="t('portal.contacts.first_name')"
              :error="form.errors.first_name"
              required
            />
            <AppInput
              v-model="form.last_name"
              :label="t('portal.contacts.last_name')"
              :error="form.errors.last_name"
              required
            />
          </div>

          <AppInput
            v-model="form.email"
            :label="t('portal.contacts.email')"
            type="email"
            :error="form.errors.email"
            required
          />
          <AppInput
            v-model="form.phone"
            :label="t('portal.contacts.phone')"
            :error="form.errors.phone"
          />

          <AppCheckbox
            v-model="form.portal_access"
            :label="t('portal.contacts.can_sign_in')"
            :description="t('portal.contacts.can_sign_in_hint')"
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('portal.contacts.add') }}
            </AppButton>
          </div>
        </form>
      </AppCard>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('portal.contacts.remove_title', { name: removing?.name ?? '' })"
      :description="t('portal.contacts.remove_detail')"
      :confirm-label="t('portal.contacts.remove')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </ClientLayout>
</template>
