<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface TicketRow {
  id: string
  number: string
  subject: string
  status: string
  statusLabel: string
  isOpen: boolean
  department: string | null
  lastReplyAt: string | null
  awaitingUs: boolean
}

defineProps<{ tickets: TicketRow[]; can: { create: boolean } }>()

const { t } = useTranslations()

function tone(status: string): 'neutral' | 'success' | 'warning' {
  if (status === 'closed') return 'neutral'
  if (status === 'answered') return 'success'
  return 'warning'
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('support.portal.title')" />

  <ClientLayout :heading="t('support.portal.title')" :description="t('support.portal.description')">
    <div v-if="can.create" class="mb-6">
      <AppButton variant="primary" href="/client/support/new">
        {{ t('support.portal.open_ticket') }}
      </AppButton>
    </div>

    <AppTable
      v-if="tickets.length > 0"
      :headers="[
        t('support.tickets.subject'),
        t('support.tickets.department'),
        t('support.tickets.status'),
        t('support.tickets.last_reply'),
      ]"
    >
      <tr v-for="ticket in tickets" :key="ticket.id">
        <td class="px-4 py-3">
          <Link
            :href="`/client/support/${ticket.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ ticket.subject }}
          </Link>
          <span class="text-content-muted block text-xs">{{ ticket.number }}</span>
        </td>
        <td class="text-content-muted px-4 py-3">{{ ticket.department ?? '—' }}</td>
        <td class="px-4 py-3">
          <AppBadge :tone="tone(ticket.status)">{{ ticket.statusLabel }}</AppBadge>
        </td>
        <td class="text-content-muted px-4 py-3 whitespace-nowrap">
          {{ formatDate(ticket.lastReplyAt) }}
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      :title="t('support.portal.none')"
      :description="t('support.portal.none_description')"
    />
  </ClientLayout>
</template>
