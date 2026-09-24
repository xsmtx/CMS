<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
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
  scopes: string[]
}

interface ScopeRow {
  value: string
  group: string
  label: string
  description: string
  available: boolean
}

const props = defineProps<{
  tokens: TokenRow[]
  scopes: ScopeRow[]
  issued: string | null
}>()

const { t } = useTranslations()

const form = useForm<{ name: string; expires_in_days: string; scopes: string[] }>({
  name: '',
  expires_in_days: '365',
  scopes: [],
})

// Grouped, because twelve checkboxes in a column is a list nobody reads and
// a decision nobody makes carefully.
const grouped = computed(() => {
  const groups = new Map<string, ScopeRow[]>()

  for (const scope of props.scopes) {
    groups.set(scope.group, [...(groups.get(scope.group) ?? []), scope])
  }

  return [...groups.entries()].map(([group, scopes]) => ({ group, scopes }))
})

function toggle(scope: ScopeRow, checked: boolean): void {
  form.scopes = checked
    ? [...form.scopes, scope.value]
    : form.scopes.filter((value) => value !== scope.value)
}

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

  <ClientLayout :heading="t('identity.tokens.title')" :description="t('api.tokens.description')">
    <div class="flex flex-col gap-6">
      <!--
        Shown once, on the redirect that created it. The table stores a
        hash; there is nothing to show a second time even if a screen asked.
      -->
      <AppAlert v-if="issued" tone="success">
        {{ t('api.tokens.created') }}
        <code
          class="border-line bg-surface-secondary text-chrome mt-2 block overflow-x-auto rounded-sm border px-3 py-2 font-mono break-all"
        >
          {{ issued }}
        </code>
      </AppAlert>

      <AppCard :title="t('api.tokens.create')">
        <div class="grid gap-4 sm:grid-cols-2">
          <AppInput v-model="form.name" :label="t('api.tokens.name')" :error="form.errors.name" />
          <AppInput
            v-model="form.expires_in_days"
            type="number"
            :label="t('api.tokens.expires')"
            :hint="t('api.tokens.expires_hint')"
            :error="form.errors.expires_in_days"
          />
        </div>

        <div class="mt-6">
          <p class="text-body font-medium">{{ t('api.tokens.scopes') }}</p>
          <p class="text-content-muted text-chrome mt-1 leading-relaxed">
            {{ t('api.tokens.scopes_hint') }}
          </p>

          <div class="mt-4 grid gap-5 sm:grid-cols-2">
            <div v-for="entry in grouped" :key="entry.group">
              <p class="text-content-subtle text-label pb-2 font-medium">{{ entry.group }}</p>

              <div class="flex flex-col gap-2.5">
                <div v-for="scope in entry.scopes" :key="scope.value">
                  <AppCheckbox
                    :model-value="form.scopes.includes(scope.value)"
                    :label="scope.label"
                    :description="scope.description"
                    :disabled="!scope.available"
                    @update:model-value="(checked: boolean) => toggle(scope, checked)"
                  />
                  <!-- Shown as unavailable rather than hidden: being told
                       "you cannot grant this" teaches something a missing
                       row does not. -->
                  <p v-if="!scope.available" class="text-content-subtle text-chrome mt-0.5 ml-7">
                    {{ t('api.tokens.no_scopes') }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-6">
          <AppButton
            variant="primary"
            :loading="form.processing"
            :disabled="form.scopes.length === 0"
            @click="create"
          >
            {{ t('api.tokens.create') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard :title="t('identity.tokens.title')">
        <ul v-if="tokens.length > 0" class="divide-line divide-y">
          <li v-for="token in tokens" :key="token.id" class="py-3 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="text-body truncate font-medium">{{ token.name }}</p>
                <p class="text-content-muted text-chrome mt-0.5">
                  {{
                    token.lastUsedAt
                      ? `${t('api.tokens.last_used')} ${formatDate(token.lastUsedAt)}`
                      : t('api.tokens.never_used')
                  }}
                  ·
                  {{
                    token.expiresAt
                      ? `${t('api.tokens.expires')} ${formatDate(token.expiresAt)}`
                      : t('identity.tokens.no_expiry')
                  }}
                </p>
              </div>

              <AppButton size="sm" variant="ghost" @click="revoke(token.id)">
                {{ t('api.tokens.revoke') }}
              </AppButton>
            </div>

            <div v-if="token.scopes.length > 0" class="mt-2 flex flex-wrap gap-1.5">
              <AppBadge v-for="scope in token.scopes" :key="scope">{{ scope }}</AppBadge>
            </div>
            <!-- A token issued before scopes existed carries nothing now,
                 and saying so is kinder than letting somebody believe it
                 still works. -->
            <p v-else class="text-content-subtle text-chrome mt-2">
              {{ t('api.tokens.no_scopes') }}
            </p>
          </li>
        </ul>

        <EmptyState
          v-else
          :title="t('api.tokens.none')"
          :description="t('api.tokens.none_description')"
        />
      </AppCard>
    </div>
  </ClientLayout>
</template>
