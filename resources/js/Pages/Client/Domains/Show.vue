<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

const props = defineProps<{
  domain: {
    id: string
    name: string
    status: string
    statusLabel: string
    isUsable: boolean
    expiresOn: string | null
    daysUntilExpiry: number | null
    renewal: string
    registeredOn: string | null
    years: number
    autoRenew: boolean
    registrarLock: boolean
    nameservers: string[]
  }
  can: { manage: boolean; nameservers: boolean; autoRenew: boolean; transferCode: boolean }
  transferCode: string | null
}>()

const { t } = useTranslations()

const form = useForm({
  nameservers: props.domain.nameservers.length >= 2 ? [...props.domain.nameservers] : ['', ''],
})

function saveNameservers(): void {
  form.put(`/client/domains/${props.domain.id}/nameservers`, { preserveScroll: true })
}

function toggleAutoRenew(): void {
  router.put(`/client/domains/${props.domain.id}/auto-renew`, {}, { preserveScroll: true })
}

function requestCode(): void {
  router.post(`/client/domains/${props.domain.id}/transfer-code`, {}, { preserveScroll: true })
}
</script>

<template>
  <Head :title="domain.name" />

  <ClientLayout :heading="domain.name">
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppAlert v-if="domain.status === 'registering' || domain.status === 'pending'">
          {{ t('domains.portal.being_registered') }}
        </AppAlert>
        <AppAlert v-else-if="domain.status === 'failed'" tone="danger">
          {{ t('domains.portal.registration_failed') }}
        </AppAlert>

        <AppCard :title="t('domains.portal.overview')">
          <div class="mb-4">
            <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
          </div>

          <dl class="divide-line text-body divide-y">
            <div class="flex justify-between gap-4 py-2.5 first:pt-0">
              <dt class="text-content-muted">{{ t('domains.domains.registered') }}</dt>
              <dd>{{ domain.registeredOn ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5">
              <dt class="text-content-muted">{{ t('domains.domains.expires') }}</dt>
              <dd>{{ domain.expiresOn ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2.5 last:pb-0">
              <dt class="text-content-muted">{{ t('domains.domains.renewal') }}</dt>
              <dd class="tabular-nums">{{ domain.renewal }}</dd>
            </div>
          </dl>
        </AppCard>

        <AppCard
          v-if="can.nameservers"
          :title="t('domains.portal.nameservers_title')"
          :description="t('domains.portal.nameservers_hint')"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-for="(_, index) in form.nameservers"
              :key="index"
              :model-value="form.nameservers[index] ?? ''"
              :label="t('domains.portal.nameserver', { number: index + 1 })"
              :error="form.errors[`nameservers.${index}` as keyof typeof form.errors]"
              @update:model-value="(value) => (form.nameservers[index] = String(value))"
            />
          </div>

          <div class="mt-4 flex gap-2">
            <AppButton variant="primary" :loading="form.processing" @click="saveNameservers">
              {{ t('domains.portal.save_nameservers') }}
            </AppButton>
            <AppButton variant="ghost" @click="form.nameservers.push('')">+</AppButton>
          </div>
        </AppCard>
      </div>

      <div class="flex flex-col gap-6">
        <AppCard v-if="can.autoRenew" :title="t('domains.portal.auto_renew_title')">
          <p class="text-body leading-relaxed">
            {{
              domain.autoRenew
                ? t('domains.portal.auto_renew_on')
                : t('domains.portal.auto_renew_off')
            }}
          </p>
          <AppButton class="mt-4" size="sm" @click="toggleAutoRenew">
            {{ t('domains.portal.auto_renew_toggle') }}
          </AppButton>
        </AppCard>

        <!--
          The code that moves this domain away. Fetched when asked for,
          shown once, and never written down on our side.
        -->
        <AppCard v-if="can.transferCode" :title="t('domains.portal.transfer_title')">
          <p class="text-content-muted text-body leading-relaxed">
            {{ t('domains.portal.transfer_hint') }}
          </p>

          <p
            v-if="domain.registrarLock"
            class="text-content-muted text-chrome mt-3 leading-relaxed"
          >
            {{ t('domains.portal.transfer_locked') }}
          </p>

          <div v-if="transferCode" class="mt-4">
            <p class="text-content-muted text-chrome">{{ t('domains.portal.transfer_code') }}</p>
            <code
              class="border-line bg-surface-secondary text-chrome mt-1 block overflow-x-auto rounded-sm border px-3 py-2 font-mono break-all"
            >
              {{ transferCode }}
            </code>
          </div>

          <AppButton v-else class="mt-4" size="sm" @click="requestCode">
            {{ t('domains.portal.transfer_request') }}
          </AppButton>
        </AppCard>
      </div>
    </div>
  </ClientLayout>
</template>
