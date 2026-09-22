<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../Components/AppAlert.vue'
import AppBadge from '../../Components/AppBadge.vue'
import AppButton from '../../Components/AppButton.vue'
import AppCard from '../../Components/AppCard.vue'
import AppCheckbox from '../../Components/AppCheckbox.vue'
import AppInput from '../../Components/AppInput.vue'
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

const adding = ref(false)

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

function remove(contact: ContactRow): void {
  router.delete(`/client/contacts/${contact.id}`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Contacts" />

  <ClientLayout heading="Contacts" description="The people on this account and who can sign in.">
    <div class="flex flex-col gap-5">
      <AppAlert v-if="page.props.flash?.status" tone="success">
        {{ page.props.flash.status }}
      </AppAlert>

      <AppAlert v-if="!can.manage" tone="info">
        Only the account owner can add or remove contacts. You can still update your own details on
        the profile page.
      </AppAlert>

      <AppCard title="People on this account">
        <template v-if="can.manage" #actions>
          <AppButton size="sm" @click="adding = !adding">
            {{ adding ? 'Cancel' : 'Add contact' }}
          </AppButton>
        </template>

        <ul class="divide-line divide-y">
          <li
            v-for="contact in contacts"
            :key="contact.id"
            class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
          >
            <div>
              <p class="text-sm font-medium">
                {{ contact.name }}
                <AppBadge v-if="contact.isPrimary" tone="accent" class="ml-2">
                  Account owner
                </AppBadge>
                <AppBadge v-if="contact.isMe" class="ml-2">You</AppBadge>
              </p>
              <p class="text-content-muted mt-0.5 text-xs">
                {{ contact.email }}
                <span v-if="!contact.portalAccess"> · cannot sign in</span>
              </p>
            </div>

            <button
              v-if="can.manage && !contact.isPrimary && !contact.isMe"
              type="button"
              class="text-danger text-xs underline underline-offset-4"
              @click="remove(contact)"
            >
              Remove
            </button>
          </li>
        </ul>
      </AppCard>

      <AppCard
        v-if="adding && can.manage"
        title="Add a contact"
        description="Giving someone access sends them a link to choose their own password. You never set one for them."
      >
        <form class="grid max-w-xl gap-5" @submit.prevent="add">
          <div class="grid gap-5 sm:grid-cols-2">
            <AppInput
              v-model="form.first_name"
              label="First name"
              :error="form.errors.first_name"
              required
            />
            <AppInput
              v-model="form.last_name"
              label="Last name"
              :error="form.errors.last_name"
              required
            />
          </div>

          <AppInput
            v-model="form.email"
            label="Email address"
            type="email"
            :error="form.errors.email"
            required
          />
          <AppInput v-model="form.phone" label="Phone" :error="form.errors.phone" />

          <AppCheckbox
            v-model="form.portal_access"
            label="Can sign in to this account"
            description="They will be able to see services, invoices and support requests."
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              Add contact
            </AppButton>
          </div>
        </form>
      </AppCard>
    </div>
  </ClientLayout>
</template>
