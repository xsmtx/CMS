<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

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
    <div class="mb-6 flex flex-wrap items-center gap-3">
      <AppBadge :tone="ticket.isOpen ? 'warning' : 'neutral'">{{ ticket.statusLabel }}</AppBadge>
      <span v-if="ticket.department" class="text-content-muted text-sm">
        {{ ticket.department }}
      </span>
    </div>

    <AppAlert v-if="!ticket.isOpen" class="mb-6">
      {{ t('support.portal.closed_hint') }}
    </AppAlert>

    <ul class="flex max-w-3xl flex-col gap-4">
      <li
        v-for="reply in ticket.replies"
        :key="reply.id"
        class="border-line bg-surface-primary rounded-[var(--radius-lg)] border p-4"
        :class="reply.fromStaff ? '' : 'ml-auto'"
      >
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
          <p class="text-sm font-medium">{{ reply.author }}</p>
          <p class="text-content-subtle text-xs">{{ formatDateTime(reply.createdAt) }}</p>
        </div>

        <!-- Rendered by TicketMarkdown, which strips author HTML rather
             than escaping it. The raw body is never interpolated
             anywhere. -->
        <!-- eslint-disable-next-line vue/no-v-html -->
        <div class="prose-article text-sm leading-relaxed" v-html="reply.html" />

        <ul v-if="reply.attachments.length > 0" class="mt-3 flex flex-wrap gap-2">
          <li v-for="file in reply.attachments" :key="file.id">
            <a
              :href="`/attachments/${file.id}`"
              class="border-line hover:bg-surface-secondary inline-flex items-center gap-2 rounded-[var(--radius-sm)] border px-2.5 py-1 text-xs"
            >
              {{ file.name }}
              <span class="text-content-subtle">{{ file.size }}</span>
            </a>
          </li>
        </ul>
      </li>
    </ul>

    <AppCard class="mt-6 max-w-3xl" :title="t('support.portal.your_reply')">
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
    </AppCard>
  </ClientLayout>
</template>
