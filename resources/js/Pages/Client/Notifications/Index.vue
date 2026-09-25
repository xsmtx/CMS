<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'

import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'

interface NotificationRow {
  id: string
  title: string
  body: string
  actionUrl: string | null
  readAt: string | null
  createdAt: string
}

interface Preference {
  value: string
  label: string
  enabled: boolean
  alwaysSent: boolean
}

const props = defineProps<{
  notifications: NotificationRow[]
  preferences: Preference[]
}>()

const { t } = useTranslations()

function enabled(category: string): boolean {
  return props.preferences.find((preference) => preference.value === category)?.enabled ?? true
}

// The four categories are known, so the form has a shape rather than an
// index signature the compiler cannot narrow.
const form = useForm({
  invoices: enabled('invoices'),
  support: enabled('support'),
  product: enabled('product'),
  marketing: enabled('marketing'),
})

type Category = 'invoices' | 'support' | 'product' | 'marketing'

const CATEGORIES: Category[] = ['invoices', 'support', 'product', 'marketing']

function find(category: string): Preference | undefined {
  return props.preferences.find((preference) => preference.value === category)
}

function labelFor(category: string): string {
  return find(category)?.label ?? category
}

function alwaysSent(category: string): boolean {
  return find(category)?.alwaysSent ?? false
}

function markRead(): void {
  router.post('/client/notifications/read', {}, { preserveScroll: true })
}

function savePreferences(): void {
  form.put('/client/notifications/preferences', { preserveScroll: true })
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('notifications.portal.title')" />

  <ClientLayout :heading="t('notifications.portal.title')">
    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
      <div>
        <div v-if="notifications.length > 0" class="mb-4">
          <AppButton size="sm" variant="ghost" @click="markRead">
            {{ t('notifications.portal.mark_read') }}
          </AppButton>
        </div>

        <ul v-if="notifications.length > 0" class="flex flex-col gap-3">
          <li
            v-for="notification in notifications"
            :key="notification.id"
            class="rounded-lg border p-4"
            :class="
              notification.readAt === null
                ? 'border-brand/40 bg-surface-primary'
                : 'border-line bg-surface-primary'
            "
          >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <p class="text-body font-medium">{{ notification.title }}</p>
              <p class="text-content-subtle text-chrome">
                {{ formatDateTime(notification.createdAt) }}
              </p>
            </div>

            <p class="text-content-muted text-body mt-1 leading-relaxed whitespace-pre-line">
              {{ notification.body }}
            </p>

            <a
              v-if="notification.actionUrl"
              :href="notification.actionUrl"
              class="text-body mt-2 inline-block underline-offset-4 hover:underline"
            >
              {{ t('billing.portal.view') }}
            </a>
          </li>
        </ul>

        <EmptyState
          v-else
          :title="t('notifications.portal.none')"
          :description="t('notifications.portal.none_description')"
        />
      </div>

      <div>
        <DetailSection
          :title="t('notifications.portal.preferences')"
          :description="t('notifications.portal.preferences_hint')"
        >
          <div class="flex flex-col gap-3">
            <div v-for="category in CATEGORIES" :key="category">
              <!--
                Disabled when it is always sent, rather than a switch that
                moves and changes nothing: a control that lies is worse than
                a control that is absent, and the sentence under it says why.
              -->
              <AppCheckbox
                v-model="form[category]"
                :label="labelFor(category)"
                :disabled="alwaysSent(category)"
              />
              <p v-if="alwaysSent(category)" class="text-content-subtle text-chrome mt-1 ml-7">
                {{ t('notifications.portal.always_sent') }}
              </p>
            </div>
          </div>

          <div class="mt-5">
            <AppButton size="sm" :loading="form.processing" @click="savePreferences">
              {{ t('crm.save') }}
            </AppButton>
          </div>
        </DetailSection>
      </div>
    </div>
  </ClientLayout>
</template>
