<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppCard from '../../../Components/AppCard.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface ServiceRow {
  id: string
  name: string
  status: string
  statusLabel: string
  isUsable: boolean
  domain: string | null
  recurring: string
  cycleLabel: string | null
  nextDueOn: string | null
}

defineProps<{ services: ServiceRow[] }>()

const { t } = useTranslations()

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  if (status === 'failed') return 'danger'
  if (status === 'suspended' || status === 'pending' || status === 'provisioning') return 'warning'
  return 'neutral'
}
</script>

<template>
  <Head :title="t('provisioning.portal.title')" />

  <ClientLayout
    :heading="t('provisioning.portal.title')"
    :description="t('provisioning.portal.description')"
  >
    <div v-if="services.length > 0" class="grid gap-5 sm:grid-cols-2">
      <AppCard v-for="service in services" :key="service.id">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <Link
              :href="`/client/services/${service.id}`"
              class="text-sm font-semibold underline-offset-4 hover:underline"
            >
              {{ service.name }}
            </Link>
            <p v-if="service.domain" class="text-content-muted mt-0.5 text-xs">
              {{ service.domain }}
            </p>
          </div>
          <AppBadge :tone="tone(service.status)">{{ service.statusLabel }}</AppBadge>
        </div>

        <dl class="border-line mt-4 border-t pt-3 text-sm">
          <div class="flex justify-between gap-4 py-1">
            <dt class="text-content-muted">{{ service.cycleLabel ?? '' }}</dt>
            <dd class="tabular-nums">{{ service.recurring }}</dd>
          </div>
          <div v-if="service.nextDueOn" class="flex justify-between gap-4 py-1">
            <dt class="text-content-muted">
              {{ t('provisioning.portal.next_due', { date: service.nextDueOn }) }}
            </dt>
          </div>
        </dl>
      </AppCard>
    </div>

    <EmptyState
      v-else
      :title="t('provisioning.portal.none')"
      :description="t('provisioning.portal.none_description')"
    />
  </ClientLayout>
</template>
