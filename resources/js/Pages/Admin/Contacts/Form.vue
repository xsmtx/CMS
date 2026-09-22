<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

const props = defineProps<{
  customer: { id: string; name: string }
  contact: {
    id: string
    firstName: string
    lastName: string
    email: string
    phone: string | null
    portalAccess: boolean
    isPrimary: boolean
    status: string
    notifyInvoices: boolean
    notifySupport: boolean
    notifyProduct: boolean
    notifyMarketing: boolean
  } | null
  statuses: { value: string; label: string }[]
}>()

const isEditing = computed(() => props.contact !== null)

const form = useForm({
  first_name: props.contact?.firstName ?? '',
  last_name: props.contact?.lastName ?? '',
  email: props.contact?.email ?? '',
  phone: props.contact?.phone ?? '',
  portal_access: props.contact?.portalAccess ?? false,
  is_primary: props.contact?.isPrimary ?? false,
  status: props.contact?.status ?? 'active',
  notify_invoices: props.contact?.notifyInvoices ?? true,
  notify_support: props.contact?.notifySupport ?? true,
  notify_product: props.contact?.notifyProduct ?? true,
  notify_marketing: props.contact?.notifyMarketing ?? false,
})

function submit(): void {
  if (props.contact) {
    form.put(`/admin/customers/${props.customer.id}/contacts/${props.contact.id}`)
    return
  }

  form.post(`/admin/customers/${props.customer.id}/contacts`)
}
</script>

<template>
  <Head :title="isEditing ? 'Edit contact' : 'Add contact'" />

  <AdminLayout
    :heading="isEditing ? 'Edit contact' : 'Add contact'"
    :description="`For ${customer.name}.`"
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppCard title="Person">
        <div class="grid max-w-xl gap-5">
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
            hint="Also the sign-in identifier if they have portal access."
            :error="form.errors.email"
            required
          />
          <AppInput v-model="form.phone" label="Phone" :error="form.errors.phone" />
          <AppSelect
            v-model="form.status"
            label="Status"
            :options="statuses"
            :error="form.errors.status"
          />
        </div>
      </AppCard>

      <AppCard title="Access">
        <div class="flex max-w-xl flex-col gap-4">
          <AppCheckbox
            v-model="form.portal_access"
            label="Can sign in to the client area"
            description="No password is set here. They receive a reset link instead."
          />
          <AppCheckbox
            v-model="form.is_primary"
            label="Primary contact"
            description="Invoices and account notices go here. Only one contact can be primary."
          />

          <AppAlert v-if="isEditing && !form.portal_access" tone="info">
            Turning access off clears their password, so the account cannot be reached even if the
            flag is turned back on by mistake.
          </AppAlert>
        </div>
      </AppCard>

      <AppCard
        title="Notifications"
        description="Transactional mail about services they pay for is always sent and has no switch here."
      >
        <div class="grid max-w-xl gap-3 sm:grid-cols-2">
          <AppCheckbox v-model="form.notify_invoices" label="Invoices and receipts" />
          <AppCheckbox v-model="form.notify_support" label="Support replies" />
          <AppCheckbox v-model="form.notify_product" label="Product announcements" />
          <AppCheckbox v-model="form.notify_marketing" label="Marketing" />
        </div>
      </AppCard>

      <div class="flex gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save changes' : 'Add contact' }}
        </AppButton>
        <AppButton :href="`/admin/customers/${customer.id}`" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
