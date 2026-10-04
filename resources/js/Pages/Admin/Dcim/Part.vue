<script setup lang="ts">
/**
 * One part, and everywhere it has been (§11).
 *
 * **The history is the reason the table exists.** A disk that has been in
 * three machines has three rows, and "where has this serial been" is what a
 * warranty claim turns on — so it is the section, not a footnote.
 *
 * Fitting and taking out are ordinary forms rather than confirmations:
 * nothing here moves a screwdriver. The record going wrong is the danger, and
 * the answer to that is that nothing is ever edited — taking a part out
 * closes its fitting and leaves the row.
 */
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DescriptionList from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface HistoryRow {
  id: string
  server: string | null
  fittedAt: string
  removedAt: string | null
  by: string | null
  note: string | null
}

const props = defineProps<{
  part: {
    id: string
    kindLabel: string
    model: string | null
    serial: string | null
    assetTag: string | null
    vendor: string | null
    purchasedOn: string | null
    warrantyUntil: string | null
    outOfWarranty: boolean | null
    server: string | null
    serverId: string | null
  }
  history: HistoryRow[]
  servers?: { id: string; name: string }[]
  can?: { manage: boolean }
}>()

const { t } = useTranslations()

// The reader's own locale, not the operating system's.
const locale = computed(() => usePage().props.locale ?? 'en')

const removing = ref(false)
const fitForm = useForm({ server_id: '', note: '' })
const removeForm = useForm({ note: '' })

/*
 * A row nobody filled in is noise on a register: an empty "Asset tag" line
 * says only that somebody did not type one, which the absence says better.
 */
const rows = [
  { key: 'kind', label: t('dcim.parts.fields.kind'), value: props.part.kindLabel },
  { key: 'model', label: t('dcim.parts.fields.model'), value: props.part.model ?? '' },
  { key: 'vendor', label: t('dcim.parts.fields.vendor'), value: props.part.vendor ?? '' },
  { key: 'asset', label: t('dcim.parts.fields.asset_tag'), value: props.part.assetTag ?? '' },
  { key: 'bought', label: t('dcim.parts.fields.purchased_on'), value: day(props.part.purchasedOn) },
  {
    key: 'warranty',
    label: t('dcim.parts.fields.warranty_until'),
    value: day(props.part.warrantyUntil),
  },
].filter((row) => row.value !== '')

function day(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleDateString(locale.value)
}

function fit(): void {
  fitForm.post(`/admin/infrastructure/parts/${props.part.id}/fit`, {
    preserveScroll: true,
    onSuccess: () => fitForm.reset(),
  })
}

function remove(reason: string | null): void {
  removeForm.note = reason ?? ''
  removeForm.post(`/admin/infrastructure/parts/${props.part.id}/remove`, {
    preserveScroll: true,
    onSuccess: () => {
      removing.value = false
      removeForm.reset()
    },
  })
}
</script>

<template>
  <Head :title="part.serial ?? part.kindLabel" />

  <AdminLayout :heading="part.serial ?? part.assetTag ?? part.kindLabel">
    <template #status>
      <AppStatus
        :tone="part.outOfWarranty === null ? 'unknown' : part.outOfWarranty ? 'warning' : 'healthy'"
        :label="
          part.outOfWarranty === null
            ? t('dcim.parts.no_warranty')
            : part.outOfWarranty
              ? t('dcim.parts.out_of_warranty')
              : t('dcim.parts.in_warranty')
        "
      />
    </template>

    <div class="flex flex-col gap-8">
      <!--
        No heading. The page heading and the status beside it already say
        what this is, and a section called "Hardware" under a page called
        Hardware is a heading that says nothing twice.
      -->
      <DescriptionList :items="rows" />

      <DetailSection :title="t('dcim.parts.history')">
        <p v-if="history.length === 0" class="text-content-muted text-body">
          {{ t('dcim.parts.no_history') }}
        </p>

        <ul v-else class="flex flex-col gap-2">
          <li v-for="row in history" :key="row.id" class="text-body flex flex-wrap gap-x-3">
            <span class="font-medium">{{ row.server }}</span>
            <span class="text-content-muted text-chrome tabular-nums">
              {{ t('dcim.parts.fitted_on', { date: day(row.fittedAt) }) }}
            </span>
            <span class="text-content-muted text-chrome tabular-nums">
              {{
                row.removedAt
                  ? t('dcim.parts.removed_on', { date: day(row.removedAt) })
                  : t('dcim.parts.still_fitted')
              }}
            </span>
            <span v-if="row.by" class="text-content-subtle text-chrome">{{ row.by }}</span>
            <span v-if="row.note" class="text-content-subtle text-chrome w-full">
              {{ row.note }}
            </span>
          </li>
        </ul>
      </DetailSection>

      <DetailSection v-if="can?.manage" :title="t('dcim.parts.fit')">
        <div class="flex flex-col gap-4">
          <form class="flex flex-wrap items-end gap-3" @submit.prevent="fit">
            <AppSelect
              v-model="fitForm.server_id"
              :label="t('dcim.parts.fields.server')"
              :options="[
                { value: '', label: t('dcim.parts.fields.choose_server') },
                ...(servers ?? []).map((s) => ({ value: s.id, label: s.name })),
              ]"
              :error="fitForm.errors.server_id"
            />
            <AppInput
              v-model="fitForm.note"
              :label="t('dcim.parts.fields.note')"
              :error="fitForm.errors.note"
            />
            <div>
              <AppButton type="submit" :loading="fitForm.processing">
                {{ t('dcim.parts.fit') }}
              </AppButton>
            </div>
          </form>

          <!--
            One note field on the screen, not two. Taking a part out asks for
            its own reason in the confirmation, which is where the rest of
            this product asks.
          -->
          <div v-if="part.serverId">
            <AppButton variant="danger-subtle" @click="removing = true">
              {{ t('dcim.parts.remove') }}
            </AppButton>
          </div>
        </div>
      </DetailSection>
    </div>

    <AppConfirm
      :open="removing"
      level="high-risk"
      :title="t('dcim.parts.remove_title', { name: part.serial ?? part.kindLabel })"
      :description="t('dcim.parts.remove_body')"
      :confirm-label="t('dcim.parts.remove')"
      :busy="removeForm.processing"
      @confirm="remove"
      @close="removing = false"
    />
  </AdminLayout>
</template>
