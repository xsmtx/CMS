<script setup lang="ts">
/**
 * Billing terms, and what a document number looks like.
 *
 * Two panels with a deliberate asymmetry. The terms are one form and one save,
 * because they are one set of answers. The numbering is **a form per sequence**,
 * because an operator migrating an invoice book sets the invoice sequence and
 * must not have the order sequence saved alongside it by a button that happened
 * to be nearby — a wrong `next_value` is a duplicate document number, which is
 * the one settings mistake in this product that cannot be quietly corrected.
 *
 * Each sequence shows the number it will actually produce next, worked out
 * server side by the same formatter that will produce it. Nothing here builds
 * that string in TypeScript: a preview computed twice is a preview that
 * eventually disagrees with the document.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { reactive } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface SequenceRow {
  key: string
  label: string
  prefix: string
  padding: number
  nextValue: number
  resetPeriod: string
  periodKey: string | null
  preview: string
  exists: boolean
}

const props = defineProps<{
  settings: {
    dueDays: number
    lateFeeRate: string
    lateFeeLabel: string | null
    documentNote: string | null
    stated: boolean
  }
  sequences: SequenceRow[]
  options: { resetPeriods: { value: string; label: string }[] }
}>()

const { t } = useTranslations()

const terms = useForm({
  due_days: props.settings.dueDays,
  late_fee_rate: props.settings.lateFeeRate,
  late_fee_label: props.settings.lateFeeLabel ?? '',
  document_note: props.settings.documentNote ?? '',
})

function saveTerms(): void {
  terms.put('/admin/billing/settings', { preserveScroll: true })
}

/**
 * One form per sequence, keyed by the sequence's own key.
 *
 * Built once from the props rather than per render: a `useForm` created inside
 * a `v-for` expression is a new form object on every keystroke, which loses the
 * field being typed into.
 */
const numbering = reactive(
  Object.fromEntries(
    props.sequences.map((sequence) => [
      sequence.key,
      useForm({
        prefix: sequence.prefix,
        padding: sequence.padding,
        next_value: sequence.nextValue,
        reset_period: sequence.resetPeriod,
      }),
    ]),
  ),
)

function saveSequence(key: string): void {
  numbering[key]?.put(`/admin/billing/numbering/${key}`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t('billing.settings.title')" />

  <AdminLayout :heading="t('billing.settings.title')">
    <template #header>
      <PageHeader :title="t('billing.settings.title')" :description="t('billing.settings.intro')" />
    </template>

    <!--
      Capped: two columns across the full window give a due-days field 580px
      wide, which reads as a mistake. A form is prose with boxes in it.
    -->
    <div class="flex max-w-4xl flex-col gap-8">
      <AppAlert v-if="!settings.stated" tone="info">
        {{ t('billing.settings.default_note') }}
      </AppAlert>

      <form class="flex flex-col gap-8" @submit.prevent="saveTerms">
        <DetailSection
          :title="t('billing.settings.terms_title')"
          :description="t('billing.settings.terms_intro')"
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="terms.due_days"
              type="number"
              :label="t('billing.settings.due_days')"
              :hint="t('billing.settings.due_days_hint')"
              :error="terms.errors.due_days"
              required
            />
          </div>
        </DetailSection>

        <DetailSection
          :title="t('billing.settings.late_fee_title')"
          :description="t('billing.settings.late_fee_intro')"
        >
          <div class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
              <AppInput
                v-model="terms.late_fee_rate"
                :label="t('billing.settings.late_fee_rate')"
                :hint="t('billing.settings.late_fee_rate_hint')"
                :error="terms.errors.late_fee_rate"
                required
              />
              <AppInput
                v-model="terms.late_fee_label"
                :label="t('billing.settings.late_fee_label')"
                :hint="t('billing.settings.late_fee_label_hint')"
                :error="terms.errors.late_fee_label"
              />
            </div>

            <!-- Where the timing lives. Without this line an operator sets a
                 rate, waits, and concludes the fee is broken. -->
            <p class="text-content-muted text-body leading-relaxed">
              {{ t('billing.settings.late_fee_step_note') }}
            </p>
          </div>
        </DetailSection>

        <DetailSection
          :title="t('billing.settings.document_title')"
          :description="t('billing.settings.document_intro')"
        >
          <AppTextarea
            v-model="terms.document_note"
            :label="t('billing.settings.document_note')"
            :rows="3"
            :error="terms.errors.document_note"
          />
        </DetailSection>

        <div>
          <AppButton type="submit" variant="primary" :loading="terms.processing">
            {{ t('billing.settings.save') }}
          </AppButton>
        </div>
      </form>

      <DetailSection
        :title="t('billing.settings.numbering.title')"
        :description="t('billing.settings.numbering.intro')"
      >
        <!--
          A frame per sequence, not a divider. Each one is its own form and its
          own save: an operator migrating an invoice book must not have the
          order sequence written alongside it by a button that happened to be
          nearby, and the frame is what says where one ends.
        -->
        <div
          v-for="sequence in sequences"
          :key="sequence.key"
          class="border-line mb-4 rounded-lg border p-4 last:mb-0"
        >
          <form class="flex flex-col gap-4" @submit.prevent="saveSequence(sequence.key)">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h3 class="text-body font-semibold">{{ sequence.label }}</h3>

              <div class="flex items-center gap-2">
                <span class="text-content-muted text-chrome">
                  {{ t('billing.settings.numbering.preview') }}
                </span>
                <AppBadge tone="brand">{{ sequence.preview }}</AppBadge>
                <AppBadge v-if="sequence.periodKey" tone="info">{{ sequence.periodKey }}</AppBadge>
              </div>
            </div>

            <p v-if="!sequence.exists" class="text-content-muted text-chrome leading-relaxed">
              {{ t('billing.settings.numbering.not_created') }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <AppInput
                v-model="numbering[sequence.key]!.prefix"
                :label="t('billing.settings.numbering.prefix')"
                :error="numbering[sequence.key]!.errors.prefix"
              />
              <AppInput
                v-model="numbering[sequence.key]!.padding"
                type="number"
                :label="t('billing.settings.numbering.padding')"
                :error="numbering[sequence.key]!.errors.padding"
                required
              />
              <AppInput
                v-model="numbering[sequence.key]!.next_value"
                type="number"
                :label="t('billing.settings.numbering.next_value')"
                :hint="t('billing.settings.numbering.next_value_hint')"
                :error="numbering[sequence.key]!.errors.next_value"
                required
              />
              <AppSelect
                v-model="numbering[sequence.key]!.reset_period"
                :label="t('billing.settings.numbering.reset')"
                :options="options.resetPeriods"
                :error="numbering[sequence.key]!.errors.reset_period"
              />
            </div>

            <div>
              <AppButton
                type="submit"
                variant="secondary"
                :loading="numbering[sequence.key]!.processing"
              >
                {{ t('billing.settings.numbering.save') }}
              </AppButton>
            </div>
          </form>
        </div>
      </DetailSection>
    </div>
  </AdminLayout>
</template>
