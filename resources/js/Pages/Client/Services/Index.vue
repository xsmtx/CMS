<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

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
              class="text-body font-semibold underline-offset-4 hover:underline"
            >
              {{ service.name }}
            </Link>
            <p v-if="service.domain" class="text-content-muted text-chrome mt-0.5">
              {{ service.domain }}
            </p>
          </div>
          <AppStatus :tone="statusTone(service.status)" :label="service.statusLabel" />
        </div>

        <dl class="border-line text-body mt-4 border-t pt-3">
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
