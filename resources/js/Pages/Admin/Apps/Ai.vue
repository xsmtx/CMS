<script setup lang="ts">
/**
 * Whether this installation uses an assistant, and for what (ADR 0050).
 *
 * The one screen in this product where somebody agrees to something **on
 * their customers' behalf** — a ticket is a customer's words, and a provider
 * is a third party the customer has no contract with. So every sentence here
 * says what leaves rather than what the feature is called, and a feature a
 * customer may end up reading is marked as one.
 *
 * It opens on the honest empty state: with no provider module enabled there
 * is nothing to choose, and the screen says so rather than drawing a dropdown
 * with nothing in it.
 */
import { Head, useForm } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface Feature {
  value: string
  label: string
  description: string
  reachesCustomer: boolean
}

interface UsageRow {
  id: string
  feature: string
  featureLabel: string
  provider: string
  model: string
  tokens: number | null
  outcome: string
  outcomeLabel: string
  who: string | null
  at: string | null
}

const props = defineProps<{
  settings: { provider: string | null; features: string[]; instructions: string | null }
  providers: { value: string; label: string }[]
  features: Feature[]
  usage: UsageRow[]
}>()

const { t } = useTranslations()

const form = useForm({
  provider: props.settings.provider ?? '',
  features: [...props.settings.features],
  instructions: props.settings.instructions ?? '',
})

const providerOptions = [{ value: '', label: t('ai.provider_none') }, ...props.providers]

const USAGE_COLUMNS: TableColumn[] = [
  { key: 'feature', label: t('ai.usage.feature') },
  { key: 'who', label: t('ai.usage.who') },
  { key: 'model', label: t('ai.usage.model') },
  { key: 'tokens', label: t('ai.usage.tokens'), numeric: true },
  { key: 'outcome', label: t('ai.usage.outcome') },
  { key: 'when', label: t('ai.usage.when') },
]

function toggle(value: string): void {
  form.features = form.features.includes(value)
    ? form.features.filter((feature) => feature !== value)
    : [...form.features, value]
}

function save(): void {
  form.put('/admin/apps/ai', { preserveScroll: true })
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('ai.title')" />

  <AdminLayout :heading="t('ai.title')" :description="t('ai.intro')">
    <div class="flex flex-col gap-8">
      <!--
        No module, nothing to choose. A dropdown with nothing in it would be
        a control that cannot do anything, and the sentence underneath is
        what an operator actually needs next.
      -->
      <EmptyState
        v-if="providers.length === 0"
        icon="info"
        :title="t('ai.off')"
        :description="t('ai.off_detail')"
      />

      <template v-else>
        <!--
          One section for one form. Three headings each repeating the field
          under them — and one of them repeating the page title — is the
          "says nothing twice" mistake this product keeps finding; every
          field here already carries its own label and its own hint.
        -->
        <DetailSection :title="t('ai.what')" :description="t('ai.what_hint')">
          <div class="flex max-w-xl flex-col gap-5">
            <AppSelect
              v-model="form.provider"
              :label="t('ai.provider')"
              :hint="t('ai.provider_hint')"
              :options="providerOptions"
              :error="form.errors.provider"
            />

            <!--
              One checkbox per feature, each saying what leaves. Drafting
              what a customer reads and summarising for a colleague are
              different agreements, so they are different switches.
            -->
            <div class="flex flex-col gap-4">
              <div v-for="feature in features" :key="feature.value">
                <AppCheckbox
                  :model-value="form.features.includes(feature.value)"
                  :label="feature.label"
                  :description="feature.description"
                  :disabled="form.provider === ''"
                  @update:model-value="toggle(feature.value)"
                />
                <div class="mt-1 ml-6">
                  <AppBadge :tone="feature.reachesCustomer ? 'warning' : 'neutral'">
                    {{ feature.reachesCustomer ? t('ai.reaches_customer') : t('ai.internal_only') }}
                  </AppBadge>
                </div>
              </div>
            </div>

            <AppTextarea
              v-model="form.instructions"
              :label="t('ai.instructions')"
              :hint="t('ai.instructions_hint')"
              :error="form.errors.instructions"
              :rows="4"
            />

            <div>
              <!-- Labelled with what pressing it does, not with a heading. -->
              <AppButton variant="primary" :loading="form.processing" @click="save">
                {{ t('ui.common.save') }}
              </AppButton>
            </div>
          </div>
        </DetailSection>
      </template>

      <DetailSection
        :title="t('ai.usage.title')"
        :description="t('ai.usage.intro')"
        :divided="false"
      >
        <EmptyState
          v-if="usage.length === 0"
          variant="plain"
          icon="info"
          :title="t('ai.usage.empty')"
          :description="t('ai.usage.empty_detail')"
        />

        <AppTable v-else name="ai-usage" :columns="USAGE_COLUMNS">
          <AppTableRow v-for="row in usage" :key="row.id">
            <td data-col="feature">{{ row.featureLabel }}</td>
            <td data-col="who" class="text-content-muted">{{ row.who ?? '—' }}</td>
            <td data-col="model" class="text-content-muted font-mono">{{ row.model }}</td>
            <td data-col="tokens" class="numeric">
              <!--
                "Not reported" rather than a nought: a provider that said
                nothing and one that charged nothing are different answers.
              -->
              <span v-if="row.tokens === null" class="text-content-muted">
                {{ t('ai.usage.unreported') }}
              </span>
              <span v-else>{{ row.tokens }}</span>
            </td>
            <td data-col="outcome">
              <AppStatus
                :tone="row.outcome === 'answered' ? 'healthy' : 'warning'"
                :label="row.outcomeLabel"
              />
            </td>
            <td data-col="when" class="text-content-muted">{{ when(row.at) }}</td>
          </AppTableRow>
        </AppTable>
      </DetailSection>
    </div>
  </AdminLayout>
</template>
