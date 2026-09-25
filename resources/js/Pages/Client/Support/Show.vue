<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
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
    isOpen: boolean
    department: string | null
    service: string | null
    replies: Reply[]
  }
}>()

const { t } = useTranslations()

const form = useForm({ body: '' })

function send(): void {
  form.post(`/client/support/${props.ticket.id}/replies`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="ticket.subject" />

  <ClientLayout :heading="ticket.subject" :description="ticket.number">
    <template #actions>
      <AppStatus :tone="statusTone(ticket.status)" :label="ticket.statusLabel" />
    </template>

    <div class="max-w-3xl">
      <!--
        The department and the service it is about. The service has been in
        the payload since the screen was written and nothing drew it, which
        is the one fact that tells a customer which of their three hosting
        accounts this conversation is about.
      -->
      <p
        v-if="ticket.department || ticket.service"
        class="text-content-muted text-chrome mb-6 flex flex-wrap gap-x-2"
      >
        <span v-if="ticket.department">{{ ticket.department }}</span>
        <span v-if="ticket.department && ticket.service" aria-hidden="true">·</span>
        <span v-if="ticket.service"> {{ t('support.portal.service') }}: {{ ticket.service }} </span>
      </p>

      <AppAlert v-if="!ticket.isOpen" class="mb-6">
        {{ t('support.portal.closed_hint') }}
      </AppAlert>

      <ul class="flex flex-col gap-4">
        <li
          v-for="reply in ticket.replies"
          :key="reply.id"
          class="border-line bg-surface-primary rounded-lg border p-4"
          :class="reply.fromStaff ? '' : 'ml-auto'"
        >
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-body font-medium">{{ reply.author }}</p>
            <p class="text-content-subtle text-chrome">{{ formatDateTime(reply.createdAt) }}</p>
          </div>

          <!-- Rendered by TicketMarkdown, which strips author HTML rather
               than escaping it. The raw body is never interpolated
               anywhere. -->
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div class="prose-article text-body leading-relaxed" v-html="reply.html" />

          <ul v-if="reply.attachments.length > 0" class="mt-3 flex flex-wrap gap-2">
            <li v-for="file in reply.attachments" :key="file.id">
              <a
                :href="`/attachments/${file.id}`"
                class="border-line hover:bg-surface-secondary text-chrome inline-flex items-center gap-2 rounded-md border px-2.5 py-1"
              >
                {{ file.name }}
                <span class="text-content-subtle">{{ file.size }}</span>
              </a>
            </li>
          </ul>
        </li>
      </ul>

      <DetailSection class="mt-8" :title="t('support.portal.your_reply')">
        <AppTextarea
          v-model="form.body"
          :label="t('support.tickets.reply')"
          :error="form.errors.body"
          :rows="5"
        />

        <div class="mt-4">
          <AppButton variant="primary" :loading="form.processing" @click="send">
            {{ t('support.tickets.send') }}
          </AppButton>
        </div>
      </DetailSection>
    </div>
  </ClientLayout>
</template>
