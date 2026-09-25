<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppCheckbox from '../../../../Components/AppCheckbox.vue'
import AppInput from '../../../../Components/AppInput.vue'
import AppSelect from '../../../../Components/AppSelect.vue'
import AppTextarea from '../../../../Components/AppTextarea.vue'
import PriceMatrix from '../../../../Components/PriceMatrix.vue'
import DetailSection from '../../../../Components/DetailSection.vue'
import PageHeader from '../../../../Components/PageHeader.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../../composables/useTranslations'
import {
  toPricePayload,
  type CurrencyOption,
  type CycleOption,
  type PriceCell,
} from '../../../../types/catalog'

interface ChoiceInput {
  id: string | null
  label: string
  value: string
  isDefault: boolean
  position: number
  prices: PriceCell[]
}

const props = defineProps<{
  product: { id: string; name: string }
  group: {
    id: string
    name: string
    key: string
    type: string
    description: string | null
    isRequired: boolean
    minQuantity: number
    maxQuantity: number | null
    position: number
    options: ChoiceInput[]
  } | null
  types: { value: string; label: string }[]
  cycles: CycleOption[]
  currencies: CurrencyOption[]
}>()

const { t } = useTranslations()

const isEditing = computed(() => props.group !== null)

const heading = computed(() =>
  isEditing.value ? t('ui.option_form.edit') : t('ui.option_form.add'),
)

const form = useForm<{
  name: string
  key: string
  type: string
  description: string
  is_required: boolean
  min_quantity: string
  max_quantity: string
  position: string
  options: ChoiceInput[]
}>({
  name: props.group?.name ?? '',
  key: props.group?.key ?? '',
  type: props.group?.type ?? 'select',
  description: props.group?.description ?? '',
  is_required: props.group?.isRequired ?? false,
  min_quantity: String(props.group?.minQuantity ?? 0),
  max_quantity:
    props.group?.maxQuantity === null || props.group === null
      ? ''
      : String(props.group.maxQuantity),
  position: String(props.group?.position ?? 0),
  options: props.group?.options.map((choice) => ({ ...choice })) ?? [],
})

// A quantity option has no choices to list: the customer types a number.
const isQuantity = computed(() => form.type === 'quantity')

const expanded = ref<number | null>(form.options.length > 0 ? 0 : null)

function addChoice(): void {
  form.options.push({
    id: null,
    label: '',
    value: '',
    isDefault: form.options.length === 0,
    position: form.options.length,
    prices: [],
  })

  expanded.value = form.options.length - 1
}

function removeChoice(index: number): void {
  form.options.splice(index, 1)
  form.options.forEach((choice, position) => {
    choice.position = position
  })

  if (expanded.value === index) expanded.value = null
}

/** Only one choice can be the default, so setting one clears the rest. */
function setDefault(index: number): void {
  form.options.forEach((choice, position) => {
    choice.isDefault = position === index
  })
}

/** The machine-readable value follows the label until it is edited by hand. */
function onLabelInput(index: number): void {
  const choice = form.options[index]
  if (!choice || choice.id !== null) return

  choice.value = choice.label
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
}

function submit(): void {
  form
    .transform((data) => ({
      ...data,
      min_quantity: Number(data.min_quantity),
      max_quantity: data.max_quantity === '' ? null : Number(data.max_quantity),
      position: Number(data.position),
      options: isQuantity.value
        ? []
        : data.options.map((choice) => ({
            id: choice.id,
            label: choice.label,
            value: choice.value,
            is_default: choice.isDefault,
            position: choice.position,
            prices: toPricePayload(choice.prices),
          })),
    }))
    [props.group ? 'put' : 'post'](
      props.group
        ? `/admin/catalog/products/${props.product.id}/options/${props.group.id}`
        : `/admin/catalog/products/${props.product.id}/options`,
    )
}
</script>

<template>
  <Head :title="heading" />

  <AdminLayout :heading="heading">
    <template #header>
      <PageHeader
        :title="heading"
        :description="t('ui.option_form.intro', { product: product.name })"
      />
    </template>

    <form class="flex max-w-4xl flex-col gap-8" @submit.prevent="submit">
      <DetailSection :title="t('ui.option_form.question')">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput
            v-model="form.name"
            :label="t('ui.option_form.name')"
            :error="form.errors.name"
            required
          />

          <AppInput
            v-model="form.key"
            :label="t('ui.option_form.key')"
            :error="form.errors.key"
            :hint="t('ui.option_form.key_hint')"
            required
          />

          <AppSelect
            v-model="form.type"
            :label="t('ui.option_form.type')"
            :options="types"
            :error="form.errors.type"
          />

          <AppInput
            v-model="form.position"
            :label="t('ui.option_form.position')"
            type="number"
            :error="form.errors.position"
          />

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.description"
              :label="t('ui.option_form.description')"
              :error="form.errors.description"
              :hint="t('ui.option_form.description_hint')"
            />
          </div>

          <div class="flex items-center">
            <AppCheckbox
              v-model="form.is_required"
              :label="t('ui.option_form.required')"
              :description="t('ui.option_form.required_hint')"
            />
          </div>

          <div v-if="isQuantity" class="grid grid-cols-2 gap-3">
            <AppInput
              v-model="form.min_quantity"
              :label="t('ui.option_form.minimum')"
              type="number"
              :error="form.errors.min_quantity"
            />
            <AppInput
              v-model="form.max_quantity"
              :label="t('ui.option_form.maximum')"
              type="number"
              :error="form.errors.max_quantity"
              :hint="t('ui.option_form.unbounded')"
            />
          </div>
        </div>
      </DetailSection>

      <DetailSection
        v-if="!isQuantity"
        :title="t('ui.option_form.choices')"
        :description="t('ui.option_form.choices_intro')"
      >
        <template #actions>
          <AppButton type="button" size="sm" variant="ghost" icon="add" @click="addChoice">
            {{ t('ui.option_form.add_choice') }}
          </AppButton>
        </template>

        <p v-if="form.options.length === 0" class="text-content-muted text-body">
          {{ t('ui.option_form.no_choices') }}
        </p>

        <!-- A frame per choice: each is a row somebody edits as a unit, and
             three fields in a grid run into the three below them. -->
        <div
          v-for="(choice, index) in form.options"
          :key="index"
          class="border-line mb-4 rounded-lg border p-4 last:mb-0"
        >
          <div class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto]">
              <AppInput
                v-model="choice.label"
                :label="t('ui.option_form.label')"
                :error="form.errors[`options.${index}.label` as keyof typeof form.errors]"
                @input="onLabelInput(index)"
              />
              <AppInput
                v-model="choice.value"
                :label="t('ui.option_form.value')"
                :error="form.errors[`options.${index}.value` as keyof typeof form.errors]"
              />
              <div class="flex items-end gap-3 pb-1">
                <button
                  type="button"
                  class="pressable text-content-muted hover:text-content text-chrome rounded-sm px-2 py-1 underline underline-offset-4"
                  @click="expanded = expanded === index ? null : index"
                >
                  {{
                    expanded === index
                      ? t('ui.option_form.hide_prices')
                      : t('ui.option_form.prices')
                  }}
                </button>
                <button
                  type="button"
                  class="pressable text-danger text-chrome rounded-sm px-2 py-1 underline underline-offset-4"
                  @click="removeChoice(index)"
                >
                  {{ t('ui.option_form.remove') }}
                </button>
              </div>
            </div>

            <label class="text-body flex items-center gap-3">
              <input
                type="radio"
                :checked="choice.isDefault"
                class="accent-brand size-4"
                :name="`default-choice`"
                @change="setDefault(index)"
              />
              {{ t('ui.option_form.default_choice') }}
            </label>

            <div v-if="expanded === index" class="border-line-subtle border-t pt-4">
              <PriceMatrix
                v-model="choice.prices"
                :cycles="cycles"
                :currencies="currencies"
                allow-negative
                :description="t('ui.option_form.prices_hint')"
              />
            </div>
          </div>
        </div>
      </DetailSection>

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? t('ui.option_form.save') : t('ui.option_form.create') }}
        </AppButton>
        <AppButton :href="`/admin/catalog/products/${product.id}/options`" variant="ghost">
          {{ t('ui.confirm.cancel') }}
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
