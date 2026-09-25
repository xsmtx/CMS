<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
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
  {
    key: 'registered',
    label: t('domains.domains.registered'),
    value: formatDate(props.domain.registeredOn),
  },
  {
    key: 'expires',
    label: t('domains.domains.expires'),
    value: formatDate(props.domain.expiresOn),
  },
  { key: 'renewal', label: t('domains.domains.renewal'), value: props.domain.renewal },
])

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
    <template #actions>
      <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
    </template>

    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
      <div class="flex flex-col gap-8">
        <AppAlert v-if="domain.status === 'registering' || domain.status === 'pending'">
          {{ t('domains.portal.being_registered') }}
        </AppAlert>
        <AppAlert v-else-if="domain.status === 'failed'" tone="danger">
          {{ t('domains.portal.registration_failed') }}
        </AppAlert>

        <DetailSection :title="t('domains.portal.overview')">
          <DescriptionList :items="facts" />
        </DetailSection>

        <DetailSection
          v-if="can.nameservers"
          :title="t('domains.portal.nameservers_title')"
          :description="t('domains.portal.nameservers_hint')"
        >
          <div class="grid gap-4 sm:grid-cols-2">
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
            <!-- Labelled, not a "+": a button whose name is a glyph is a
                 button nobody can read out loud. -->
            <AppButton variant="ghost" icon="add" @click="form.nameservers.push('')">
              {{ t('portal.domain_detail.add_nameserver') }}
            </AppButton>
          </div>
        </DetailSection>
      </div>

      <div class="flex flex-col gap-8">
        <DetailSection
          v-if="can.autoRenew"
          :title="t('domains.portal.auto_renew_title')"
          :level="3"
        >
          <p class="text-body leading-relaxed">
            {{
              domain.autoRenew
                ? t('domains.portal.auto_renew_on')
                : t('domains.portal.auto_renew_off')
            }}
          </p>
          <div class="mt-4">
            <AppButton size="sm" @click="toggleAutoRenew">
              {{ t('domains.portal.auto_renew_toggle') }}
            </AppButton>
          </div>
        </DetailSection>

        <!--
          The code that moves this domain away. Fetched when asked for,
          shown once, and never written down on our side.
        -->
        <DetailSection
          v-if="can.transferCode"
          :title="t('domains.portal.transfer_title')"
          :level="3"
        >
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
            <div class="mt-1">
              <AppCopy :value="transferCode" :noun="t('domains.portal.transfer_code')" mono />
            </div>
          </div>

          <div v-else class="mt-4">
            <AppButton size="sm" @click="requestCode">
              {{ t('domains.portal.transfer_request') }}
            </AppButton>
          </div>
        </DetailSection>
      </div>
    </div>
  </ClientLayout>
</template>
