<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { statusTone } from '../../../status'

const props = defineProps<{
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

/**
 * A date-only column arrives as `Y-m-d` and has to be localised here: it is
 * the one value a server cannot format, because only the browser knows where
 * the person reading it lives. The admin copies of these screens learned the
 * same lesson; the portal renders the same records through different pages.
 */
function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}

const facts = computed<DescriptionItem[]>(() => [
  { key: 'domain', label: t('provisioning.services.domain'), value: props.service.domain },
  { key: 'package', label: t('provisioning.services.package'), value: props.service.package },
  {
    key: 'price',
    label: props.service.cycleLabel ?? t('portal.columns.price'),
    value: props.service.recurring,
  },
  {
    key: 'due',
    label: t('provisioning.services.next_due'),
    value: formatDate(props.service.nextDueOn),
  },
])

const chosen = computed<DescriptionItem[]>(() =>
  props.service.options.map((option) => ({
    key: option.group + option.label,
    label: option.group,
    value: option.label,
  })),
)
</script>

<template>
  <Head :title="service.name" />

  <ClientLayout :heading="service.name" :description="service.domain ?? undefined">
    <template #actions>
      <AppStatus :tone="statusTone(service.status)" :label="service.statusLabel" />
    </template>

    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
      <div class="flex flex-col gap-8">
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
          <DescriptionList :items="facts" />
        </AppCard>

        <AppCard v-if="chosen.length > 0" :title="t('provisioning.portal.what_you_get')">
          <DescriptionList :items="chosen" />
        </AppCard>
      </div>

      <!--
        Framed, and the only framed thing on the page: this is the one
        credential in the product meant to be read by the person it belongs
        to, and they cannot sign in to their own service without it.
      -->
      <AppCard :title="t('provisioning.portal.credentials')">
        <dl v-if="service.credentials" class="text-body">
          <dt class="text-content-muted text-chrome">
            {{ t('provisioning.services.username') }}
          </dt>
          <dd class="mb-3 font-mono break-all">{{ service.credentials.username ?? '—' }}</dd>
          <dt class="text-content-muted text-chrome">
            {{ t('provisioning.services.password') }}
          </dt>
          <dd class="font-mono break-all">{{ service.credentials.password ?? '—' }}</dd>
        </dl>

        <p v-else class="text-content-muted text-body leading-relaxed">
          {{ t('provisioning.portal.no_credentials') }}
        </p>

        <p v-if="service.credentials" class="text-content-muted text-chrome mt-4 leading-relaxed">
          {{ t('provisioning.portal.credentials_hint') }}
        </p>
      </AppCard>
    </div>
  </ClientLayout>
</template>
