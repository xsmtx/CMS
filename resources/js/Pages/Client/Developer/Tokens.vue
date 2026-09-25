<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import ClientLayout from '../../../Layouts/ClientLayout.vue'

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

const COLUMNS: TableColumn[] = [
  { key: 'name', label: t('api.tokens.name'), sticky: true },
  { key: 'used', label: t('api.tokens.last_used') },
  { key: 'expires', label: t('api.tokens.expires') },
  { key: 'actions', label: '' },
]

/**
 * Revoking used to happen on the first click.
 *
 * A token is what somebody's integration authenticates with, so revoking one
 * stops a running program rather than removing a record — and nothing that
 * reads this screen can tell which program.
 */
const revoking = ref<TokenRow | null>(null)

function revoke(): void {
  const token = revoking.value

  if (token === null) return

  router.delete(`/client/developer/tokens/${token.id}`, {
    preserveScroll: true,
    onFinish: () => {
      revoking.value = null
    },
  })
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
        <!-- The one moment this value exists in a readable form: the table
             keeps a hash. Copyable, rather than something to select by hand. -->
        <span class="mt-2 block">
          <AppCopy :value="issued" :noun="t('api.tokens.name')" mono />
        </span>
      </AppAlert>

      <DetailSection :title="t('api.tokens.create')">
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
      </DetailSection>

      <DetailSection :title="t('identity.tokens.title')" :divided="tokens.length === 0">
        <AppTable v-if="tokens.length > 0" name="portal-tokens" :columns="COLUMNS">
          <AppTableRow v-for="token in tokens" :key="token.id">
            <td data-col="name">
              <span class="font-medium">{{ token.name }}</span>
              <span v-if="token.scopes.length > 0" class="mt-1 flex flex-wrap gap-1.5">
                <AppBadge v-for="scope in token.scopes" :key="scope">{{ scope }}</AppBadge>
              </span>
              <!-- A token issued before scopes existed carries nothing now,
                   and saying so is kinder than letting somebody believe it
                   still works. -->
              <span v-else class="text-content-subtle text-chrome block">
                {{ t('api.tokens.no_scopes') }}
              </span>
            </td>
            <td data-col="used" class="text-content-muted">
              {{ token.lastUsedAt ? formatDate(token.lastUsedAt) : t('api.tokens.never_used') }}
            </td>
            <td data-col="expires" class="text-content-muted">
              {{ token.expiresAt ? formatDate(token.expiresAt) : t('identity.tokens.no_expiry') }}
            </td>
            <td data-col="actions" class="text-right">
              <span class="row-actions inline-flex">
                <AppButton size="sm" variant="danger-subtle" @click="revoking = token">
                  {{ t('api.tokens.revoke') }}
                </AppButton>
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          variant="plain"
          icon="connection"
          :title="t('api.tokens.none')"
          :description="t('api.tokens.none_description')"
        />
      </DetailSection>
    </div>

    <!--
      Level 2, not 3: the endpoint takes no reason and writes no audit row on
      the customer's side, and a dialog that asks for one would be collecting
      a sentence nobody reads.
    -->
    <AppConfirm
      :open="revoking !== null"
      level="consequential"
      :title="t('api.tokens.revoke_title', { name: revoking?.name ?? '' })"
      :description="t('api.tokens.revoke_detail')"
      :confirm-label="t('api.tokens.revoke')"
      @update:open="(value: boolean) => (revoking = value ? revoking : null)"
      @confirm="revoke"
    />
  </ClientLayout>
</template>
