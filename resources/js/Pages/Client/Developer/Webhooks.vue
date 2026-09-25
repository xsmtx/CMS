<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppCard from '../../../Components/AppCard.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

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

const DELIVERY_COLUMNS: TableColumn[] = [
  { key: 'event', label: t('api.deliveries.event'), sticky: true },
  { key: 'status', label: t('api.deliveries.status') },
  { key: 'attempt', label: t('api.deliveries.attempt'), numeric: true },
  { key: 'when', label: t('api.deliveries.when') },
  { key: 'actions', label: '' },
]

/** Deleting an endpoint stops deliveries reaching somebody's software. */
const removing = ref<EndpointRow | null>(null)

function remove(): void {
  const endpoint = removing.value

  if (endpoint === null) return

  router.delete(`/client/developer/webhooks/${endpoint.id}`, {
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
}

function redeliver(delivery: DeliveryRow): void {
  router.post(
    `/client/developer/webhooks/deliveries/${delivery.id}/redeliver`,
    {},
    { preserveScroll: true },
  )
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
        <!-- The signing secret, the one time it is readable. -->
        <span class="mt-2 block">
          <AppCopy :value="issued" :noun="t('api.webhooks.verify_title')" mono />
        </span>
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

        <p class="text-content-muted text-chrome mt-4 leading-relaxed">
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
                <p class="text-body truncate font-mono">{{ endpoint.url }}</p>
                <p class="text-content-muted text-chrome mt-0.5">
                  {{ endpoint.description }}
                  <span v-if="endpoint.lastDeliveredAt">
                    · {{ t('api.webhooks.last_delivered') }}
                    {{ formatDateTime(endpoint.lastDeliveredAt) }}
                  </span>
                </p>
                <!-- Said plainly: an endpoint that has been switched off
                     after repeated failures is the thing a customer needs
                     to know before they wonder why nothing arrives. -->
                <p v-if="!endpoint.isActive" class="text-danger text-chrome mt-1">
                  {{ t('api.webhooks.disabled') }}
                </p>
              </div>

              <AppButton size="sm" variant="danger-subtle" @click="removing = endpoint">
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

      <AppCard :flush="deliveries.length > 0" :title="t('api.webhooks.deliveries')">
        <AppTable
          v-if="deliveries.length > 0"
          flush
          name="portal-deliveries"
          :columns="DELIVERY_COLUMNS"
        >
          <AppTableRow v-for="delivery in deliveries" :key="delivery.id">
            <td data-col="event" class="text-chrome font-mono">{{ delivery.event }}</td>
            <td data-col="status">
              <AppStatus :tone="statusTone(delivery.status)" :label="delivery.statusLabel" />
              <span v-if="delivery.responseStatus" class="text-content-muted text-chrome ml-2">
                {{ delivery.responseStatus }}
              </span>
              <span v-if="delivery.error" class="text-content-muted text-chrome block max-w-[40ch]">
                {{ delivery.error }}
              </span>
            </td>
            <td data-col="attempt" class="numeric text-content-muted tabular-nums">
              {{ delivery.attempt }}
            </td>
            <td data-col="when" class="text-content-muted whitespace-nowrap">
              {{ formatDateTime(delivery.createdAt) }}
            </td>
            <td data-col="actions" class="text-right">
              <span class="row-actions inline-flex">
                <AppButton size="sm" variant="ghost" @click="redeliver(delivery)">
                  {{ t('api.webhooks.redeliver') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <p v-else class="text-content-muted text-body">{{ t('api.webhooks.none_description') }}</p>

        <div class="border-line mt-6 border-t pt-4">
          <p class="text-body font-medium">{{ t('api.webhooks.verify_title') }}</p>
          <p class="text-content-muted text-chrome mt-1 leading-relaxed">
            {{ t('api.webhooks.verify_body') }}
          </p>
        </div>
      </AppCard>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('api.webhooks.delete_title', { url: removing?.url ?? '' })"
      :description="t('api.webhooks.delete_detail')"
      :confirm-label="t('api.webhooks.delete')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </ClientLayout>
</template>
