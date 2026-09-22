<script setup lang="ts">
import { Head } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppCard from '../../../Components/AppCard.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

defineProps<{
  service: {
    id: string
    name: string
    status: string
    statusLabel: string
    isUsable: boolean
    domain: string | null
    recurring: string
    cycleLabel: string | null
    nextDueOn: string | null
    package: string | null
    startsOn: string | null
    options: { group: string; label: string }[]
    credentials: { username: string | null; password: string | null } | null
  }
}>()

const { t } = useTranslations()

function tone(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  if (status === 'failed') return 'danger'
  if (status === 'suspended' || status === 'pending' || status === 'provisioning') return 'warning'
  return 'neutral'
}
</script>

<template>
  <Head :title="service.name" />

  <ClientLayout :heading="service.name" :description="service.domain ?? undefined">
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <!--
          What the customer is told about a failure: that it happened and
          that somebody knows. Which node, which rule and which provider
          error are an operator's vocabulary.
        -->
        <AppAlert v-if="service.status === 'provisioning' || service.status === 'pending'">
          {{ t('provisioning.portal.being_set_up') }}
        </AppAlert>
        <AppAlert v-else-if="service.status === 'failed'" tone="danger">
          {{ t('provisioning.portal.setup_failed') }}
        </AppAlert>
        <AppAlert v-else-if="service.status === 'suspended'" tone="danger">
          {{ t('provisioning.portal.suspended') }}
        </AppAlert>

        <AppCard :title="t('provisioning.portal.overview')">
          <div class="mb-4">
            <AppBadge :tone="tone(service.status)">{{ service.statusLabel }}</AppBadge>
          </div>

          <dl class="divide-line divide-y text-sm">
            <div class="flex justify-between gap-4 py-2.5 first:pt-0">
              <dt class="text-content-muted">{{ t('provisioning.services.domain') }}</dt>
              <dd>{{ service.domain ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">{{ t('provisioning.services.package') }}</dt>
              <dd>{{ service.package ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">{{ service.cycleLabel ?? '' }}</dt>
              <dd class="tabular-nums">{{ service.recurring }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5 last:pb-0">
              <dt class="text-content-muted">{{ t('provisioning.services.next_due') }}</dt>
              <dd>{{ service.nextDueOn ?? '—' }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard v-if="service.options.length > 0" :title="t('provisioning.portal.what_you_get')">
          <dl class="divide-line divide-y text-sm">
            <div
              v-for="option in service.options"
              :key="option.group + option.label"
              class="flex justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
            >
              <dt class="text-content-muted">{{ option.group }}</dt>
              <dd>{{ option.label }}</dd>
            </div>
          </dl>
        </AppCard>
      </div>

      <div>
        <!--
          The one credential in this platform meant to be read by the person
          it belongs to: they cannot sign in to their own account without
          it.
        -->
        <AppCard :title="t('provisioning.portal.credentials')">
          <dl v-if="service.credentials" class="text-sm">
            <dt class="text-content-muted text-xs">
              {{ t('provisioning.services.username') }}
            </dt>
            <dd class="mb-3 font-mono text-xs break-all">{{ service.credentials.username }}</dd>
            <dt class="text-content-muted text-xs">
              {{ t('provisioning.services.password') }}
            </dt>
            <dd class="font-mono text-xs break-all">
              {{ service.credentials.password ?? '—' }}
            </dd>
          </dl>

          <p v-else class="text-content-muted text-sm leading-relaxed">
            {{ t('provisioning.portal.no_credentials') }}
          </p>

          <p v-if="service.credentials" class="text-content-muted mt-4 text-xs leading-relaxed">
            {{ t('provisioning.portal.credentials_hint') }}
          </p>
        </AppCard>
      </div>
    </div>
  </ClientLayout>
</template>
