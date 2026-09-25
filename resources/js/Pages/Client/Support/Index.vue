<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

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

const COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('support.tickets.subject'), sticky: true },
  { key: 'department', label: t('support.tickets.department') },
  { key: 'status', label: t('support.tickets.status') },
  { key: 'reply', label: t('support.tickets.last_reply') },
]

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('support.portal.title')" />

  <ClientLayout :heading="t('support.portal.title')" :description="t('support.portal.description')">
    <template v-if="can.create" #actions>
      <AppButton variant="primary" icon="add" href="/client/support/new">
        {{ t('support.portal.open_ticket') }}
      </AppButton>
    </template>

    <AppTable v-if="tickets.length > 0" name="portal-tickets" :columns="COLUMNS">
      <AppTableRow v-for="ticket in tickets" :key="ticket.id">
        <td data-col="subject">
          <Link
            :href="`/client/support/${ticket.id}`"
            class="font-medium underline-offset-4 hover:underline"
          >
            {{ ticket.subject }}
          </Link>
          <!--
            Whose turn it is. The payload has carried this since the screen was
            written and nothing drew it, which left the one question a customer
            opens this list to answer — "are they looking at it yet" —
            unanswered.
          -->
          <AppBadge v-if="ticket.awaitingUs" class="ml-2" tone="info">
            {{ t('support.portal.awaiting_us') }}
          </AppBadge>
          <span class="text-content-muted text-chrome block">{{ ticket.number }}</span>
        </td>
        <td data-col="department" class="text-content-muted">{{ ticket.department ?? '—' }}</td>
        <td data-col="status">
          <AppStatus :tone="statusTone(ticket.status)" :label="ticket.statusLabel" />
        </td>
        <td data-col="reply" class="text-content-muted whitespace-nowrap">
          {{ formatDate(ticket.lastReplyAt) }}
        </td>
      </AppTableRow>
    </AppTable>

    <EmptyState
      v-else
      icon="support"
      :title="t('support.portal.none')"
      :description="t('support.portal.none_description')"
    >
      <AppButton v-if="can.create" variant="primary" icon="add" href="/client/support/new">
        {{ t('support.portal.open_ticket') }}
      </AppButton>
    </EmptyState>
  </ClientLayout>
</template>
