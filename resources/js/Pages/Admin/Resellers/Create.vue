<script setup lang="ts">
/**
 * Create a reseller, and the person who will run it.
 *
 * **The owner is part of this form, not a second step.** A reseller
 * organization with nobody in it is a node an operator has to remember to
 * come back to, and the thing they came here to do was give somebody an
 * account.
 *
 * **No password field.** The account is reached through the reset flow, here
 * as everywhere: a secret typed into this form is a secret to email, read
 * aloud or leave in a ticket.
 *
 * The form says out loud that the reseller starts able to sell nothing.
 * Absence is a refusal here, and somebody who expected the opposite would
 * otherwise find out from a confused reseller rather than from this page.
 */
import { Head, useForm } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

const form = useForm({
  name: '',
  slug: '',
  owner_name: '',
  owner_email: '',
})

function submit(): void {
  form.post('/admin/resellers', { preserveScroll: true })
}
</script>

<template>
  <Head title="Add reseller" />

  <AdminLayout
    heading="Add reseller"
    description="The organization and the person who will run it, in one step."
  >
    <form class="flex max-w-3xl flex-col gap-5" @submit.prevent="submit">
      <AppCard title="The reseller">
        <div class="flex flex-col gap-4">
          <AppInput
            v-model="form.name"
            label="Trading name"
            :error="form.errors.name"
            hint="What their customers will see, unless they set their own brand."
            required
          />
          <AppInput
            v-model="form.slug"
            label="Slug"
            :error="form.errors.slug"
            hint="Optional. Ours rather than theirs, and suffixed if it is taken — two resellers with the same trading name is an ordinary thing."
          />
        </div>
      </AppCard>

      <AppCard
        title="Who runs it"
        description="An administrator of the reseller, not of this installation: they see their own subtree and nothing above it."
      >
        <div class="flex flex-col gap-4">
          <AppInput
            v-model="form.owner_name"
            label="Owner name"
            :error="form.errors.owner_name"
            required
          />
          <AppInput
            v-model="form.owner_email"
            label="Owner email"
            type="email"
            autocomplete="off"
            :error="form.errors.owner_email"
            hint="Unique across this installation. They reach the panel through the password reset flow — no password is set here."
            required
          />
        </div>
      </AppCard>

      <!-- Said on the way in rather than discovered afterwards: a reseller
           created on Friday that could sell the whole catalogue would be
           exposing a product nobody meant to expose. -->
      <AppAlert tone="info">
        A new reseller may sell <strong>nothing</strong> until you tick products for them. That is
        deliberate — absence is a refusal here, not a shortcut for everything.
      </AppAlert>

      <div class="flex items-center gap-2">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          Create reseller
        </AppButton>
        <AppButton href="/admin/resellers" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
