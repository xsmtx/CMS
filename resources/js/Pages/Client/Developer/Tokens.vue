<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ClientLayout from '../../../Layouts/ClientLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface TokenRow {
  id: string
  name: string
  lastUsedAt: string | null
  expiresAt: string | null
  createdAt: string | null
}

defineProps<{
  tokens: TokenRow[]
  issued: string | null
}>()

const { t } = useTranslations()

const form = useForm({ name: '', expires_in_days: '' })

function create(): void {
  form.post('/client/developer/tokens', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function revoke(id: string): void {
  router.delete(`/client/developer/tokens/${id}`, { preserveScroll: true })
}

function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('identity.tokens.title')" />

  <ClientLayout
    :heading="t('identity.tokens.title')"
    :description="t('identity.tokens.description')"
  >
    <div class="flex flex-col gap-6">
      <!--
        Shown once, on the redirect that created it. The table stores a
        hash; there is nothing to show a second time even if a screen asked.
      -->
      <AppAlert v-if="issued" tone="success">
        {{ t('identity.tokens.copy_once') }}
        <code
          class="border-line bg-surface-sunken mt-2 block overflow-x-auto rounded-[var(--radius-sm)] border px-3 py-2 font-mono text-xs break-all"
        >
          {{ issued }}
        </code>
      </AppAlert>

      <AppCard :title="t('identity.tokens.create')">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput
            v-model="form.name"
            :label="t('identity.tokens.name')"
            :hint="t('identity.tokens.name_hint')"
            :error="form.errors.name"
          />
          <AppInput
            v-model="form.expires_in_days"
            type="number"
            :label="t('identity.tokens.expires')"
            :hint="t('identity.tokens.expires_hint')"
            :error="form.errors.expires_in_days"
          />
        </div>

        <div class="mt-5">
          <AppButton variant="primary" :loading="form.processing" @click="create">
            {{ t('identity.tokens.create') }}
          </AppButton>
        </div>

        <p class="text-content-muted mt-4 text-xs leading-relaxed">
          {{ t('identity.tokens.api_coming') }}
        </p>
      </AppCard>

      <AppCard :title="t('identity.tokens.title')">
        <ul v-if="tokens.length > 0" class="divide-line divide-y">
          <li
            v-for="token in tokens"
            :key="token.id"
            class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ token.name }}</p>
              <p class="text-content-muted mt-0.5 text-xs">
                {{
                  token.lastUsedAt
                    ? t('identity.tokens.last_used', { date: formatDate(token.lastUsedAt) })
                    : t('identity.tokens.never_used')
                }}
                ·
                {{ token.expiresAt ? formatDate(token.expiresAt) : t('identity.tokens.no_expiry') }}
              </p>
            </div>

            <AppButton size="sm" variant="ghost" @click="revoke(token.id)">
              {{ t('identity.tokens.revoke') }}
            </AppButton>
          </li>
        </ul>

        <EmptyState
          v-else
          :title="t('identity.tokens.none')"
          :description="t('identity.tokens.description')"
        />
      </AppCard>
    </div>
  </ClientLayout>
</template>
