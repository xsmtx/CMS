<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTable from '../../../Components/AppTable.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface EndpointRow {
  id: string
  url: string
  description: string | null
  events: string[]
  isActive: boolean
  failures: number
  lastDeliveredAt: string | null
  disabledAt: string | null
}

interface DeliveryRow {
  id: string
  endpointId: string
  event: string
  status: string
  statusLabel: string
  attempt: number
  responseStatus: number | null
  error: string | null
  createdAt: string | null
}

defineProps<{
  endpoints: EndpointRow[]
  deliveries: DeliveryRow[]
  events: string[]
  issued: string | null
}>()

const { t } = useTranslations()

const form = useForm({ url: '', description: '' })

function create(): void {
  form.post('/client/developer/webhooks', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function remove(endpoint: EndpointRow): void {
  router.delete(`/client/developer/webhooks/${endpoint.id}`, { preserveScroll: true })
}

function redeliver(delivery: DeliveryRow): void {
  router.post(
    `/client/developer/webhooks/deliveries/${delivery.id}/redeliver`,
    {},
    { preserveScroll: true },
  )
}

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'delivered') return 'success'
  if (status === 'failed') return 'danger'
  if (status === 'retrying') return 'warning'

  return 'neutral'
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('api.webhooks.title')" />

  <ClientLayout :heading="t('api.webhooks.title')" :description="t('api.webhooks.description')">
    <div class="flex flex-col gap-6">
      <!-- Shown once, like a token, because it is one. -->
      <AppAlert v-if="issued" tone="success">
        {{ t('api.webhooks.created') }}
        <code
          class="border-line bg-surface-sunken mt-2 block overflow-x-auto rounded-[var(--radius-sm)] border px-3 py-2 font-mono text-xs break-all"
        >
          {{ issued }}
        </code>
      </AppAlert>

      <AppCard :title="t('api.webhooks.create')">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.url"
            :label="t('api.webhooks.url')"
            :hint="t('api.webhooks.url_hint')"
            :error="form.errors.url"
          />
          <AppInput
            v-model="form.description"
            :label="t('api.webhooks.what_for')"
            :error="form.errors.description"
          />
        </div>

        <p class="text-content-muted mt-4 text-xs leading-relaxed">
          {{ t('api.webhooks.events_hint') }}
        </p>

        <div class="mt-5">
          <AppButton variant="primary" :loading="form.processing" @click="create">
            {{ t('api.webhooks.create') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard :title="t('api.webhooks.title')">
        <ul v-if="endpoints.length > 0" class="divide-line divide-y">
          <li v-for="endpoint in endpoints" :key="endpoint.id" class="py-3 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate font-mono text-sm">{{ endpoint.url }}</p>
                <p class="text-content-muted mt-0.5 text-xs">
                  {{ endpoint.description }}
                  <span v-if="endpoint.lastDeliveredAt">
                    · {{ t('api.webhooks.last_delivered') }}
                    {{ formatDateTime(endpoint.lastDeliveredAt) }}
                  </span>
                </p>
                <!-- Said plainly: an endpoint that has been switched off
                     after repeated failures is the thing a customer needs
                     to know before they wonder why nothing arrives. -->
                <p v-if="!endpoint.isActive" class="text-danger mt-1 text-xs">
                  {{ t('api.webhooks.disabled') }}
                </p>
              </div>

              <AppButton size="sm" variant="ghost" @click="remove(endpoint)">
                {{ t('api.webhooks.delete') }}
              </AppButton>
            </div>

            <div v-if="endpoint.events.length > 0" class="mt-2 flex flex-wrap gap-1.5">
              <AppBadge v-for="event in endpoint.events" :key="event">{{ event }}</AppBadge>
            </div>
          </li>
        </ul>

        <EmptyState
          v-else
          :title="t('api.webhooks.none')"
          :description="t('api.webhooks.none_description')"
        />
      </AppCard>

      <AppCard :title="t('api.webhooks.deliveries')">
        <AppTable
          v-if="deliveries.length > 0"
          :headers="['Event', 'Status', 'Attempt', 'When', '']"
        >
          <tr v-for="delivery in deliveries" :key="delivery.id">
            <td class="px-4 py-2.5 font-mono text-xs">{{ delivery.event }}</td>
            <td class="px-4 py-2.5">
              <AppBadge :tone="tone(delivery.status)">{{ delivery.statusLabel }}</AppBadge>
              <span v-if="delivery.responseStatus" class="text-content-muted ml-2 text-xs">
                {{ delivery.responseStatus }}
              </span>
              <span v-if="delivery.error" class="text-content-muted block max-w-[40ch] text-xs">
                {{ delivery.error }}
              </span>
            </td>
            <td class="text-content-muted px-4 py-2.5 tabular-nums">{{ delivery.attempt }}</td>
            <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
              {{ formatDateTime(delivery.createdAt) }}
            </td>
            <td class="px-4 py-2.5 text-right">
              <AppButton size="sm" variant="ghost" @click="redeliver(delivery)">
                {{ t('api.webhooks.redeliver') }}
              </AppButton>
            </td>
          </tr>
        </AppTable>

        <p v-else class="text-content-muted text-sm">{{ t('api.webhooks.none_description') }}</p>

        <div class="border-line mt-6 border-t pt-4">
          <p class="text-sm font-medium">{{ t('api.webhooks.verify_title') }}</p>
          <p class="text-content-muted mt-1 text-xs leading-relaxed">
            {{ t('api.webhooks.verify_body') }}
          </p>
        </div>
      </AppCard>
    </div>
  </ClientLayout>
</template>
