<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface DomainRow {
  id: string
  name: string
  status: string
  statusLabel: string
  isUsable: boolean
  expiresOn: string | null
  daysUntilExpiry: number | null
  renewal: string
}

defineProps<{ domains: DomainRow[] }>()

const { t } = useTranslations()
</script>

<template>
  <Head :title="t('domains.portal.title')" />

  <ClientLayout :heading="t('domains.portal.title')" :description="t('domains.portal.description')">
    <div v-if="domains.length > 0" class="grid gap-5 sm:grid-cols-2">
      <AppCard v-for="domain in domains" :key="domain.id">
        <div class="flex items-start justify-between gap-4">
          <Link
            :href="`/client/domains/${domain.id}`"
            class="text-body min-w-0 font-semibold break-all underline-offset-4 hover:underline"
          >
            {{ domain.name }}
          </Link>
          <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
        </div>

        <dl class="border-line text-body mt-4 border-t pt-3">
          <div v-if="domain.expiresOn" class="flex justify-between gap-4 py-1">
            <dt class="text-content-muted">
              {{ t('domains.portal.expires_on', { date: domain.expiresOn }) }}
            </dt>
            <dd class="tabular-nums">{{ domain.renewal }}</dd>
          </div>
        </dl>
      </AppCard>
    </div>

    <EmptyState
      v-else
      :title="t('domains.portal.none')"
      :description="t('domains.portal.none_description')"
    />
  </ClientLayout>
</template>
