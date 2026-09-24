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
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
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

  <AdminLayout :heading="t('billing.settings.title')" :description="t('billing.settings.intro')">
    <div class="flex flex-col gap-6">
      <AppAlert v-if="!settings.stated" tone="info">
        {{ t('billing.settings.default_note') }}
      </AppAlert>

      <form class="flex flex-col gap-6" @submit.prevent="saveTerms">
        <AppCard
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
        </AppCard>

        <AppCard
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
            <p class="text-content-muted text-sm leading-relaxed">
              {{ t('billing.settings.late_fee_step_note') }}
            </p>
          </div>
        </AppCard>

        <AppCard
          :title="t('billing.settings.document_title')"
          :description="t('billing.settings.document_intro')"
        >
          <AppTextarea
            v-model="terms.document_note"
            :label="t('billing.settings.document_note')"
            :rows="3"
            :error="terms.errors.document_note"
          />
        </AppCard>

        <div>
          <AppButton type="submit" variant="primary" :loading="terms.processing">
            {{ t('billing.settings.save') }}
          </AppButton>
        </div>
      </form>

      <section class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <h2 class="text-title font-semibold tracking-tight">
            {{ t('billing.settings.numbering.title') }}
          </h2>
          <p class="text-content-muted max-w-[75ch] text-sm leading-relaxed">
            {{ t('billing.settings.numbering.intro') }}
          </p>
        </div>

        <AppCard v-for="sequence in sequences" :key="sequence.key">
          <form class="flex flex-col gap-4" @submit.prevent="saveSequence(sequence.key)">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h3 class="text-body font-semibold">{{ sequence.label }}</h3>

              <div class="flex items-center gap-2">
                <span class="text-content-muted text-xs">
                  {{ t('billing.settings.numbering.preview') }}
                </span>
                <AppBadge tone="brand">{{ sequence.preview }}</AppBadge>
                <AppBadge v-if="sequence.periodKey" tone="info">{{ sequence.periodKey }}</AppBadge>
              </div>
            </div>

            <p v-if="!sequence.exists" class="text-content-muted text-xs leading-relaxed">
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
        </AppCard>
      </section>
    </div>
  </AdminLayout>
</template>
