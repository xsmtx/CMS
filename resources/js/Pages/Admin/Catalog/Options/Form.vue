<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppCard from '../../../../Components/AppCard.vue'
import AppCheckbox from '../../../../Components/AppCheckbox.vue'
import AppInput from '../../../../Components/AppInput.vue'
import AppSelect from '../../../../Components/AppSelect.vue'
import AppTextarea from '../../../../Components/AppTextarea.vue'
import PriceMatrix from '../../../../Components/PriceMatrix.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
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

const isEditing = computed(() => props.group !== null)

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
  <Head :title="isEditing ? 'Edit option group' : 'New option group'" />

  <AdminLayout
    :heading="isEditing ? 'Edit option group' : 'New option group'"
    :description="`A question asked at checkout for ${product.name}. Each answer adjusts the price up or down.`"
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />

          <AppInput
            v-model="form.key"
            label="Key"
            :error="form.errors.key"
            hint="Lowercase, no spaces. Order lines and provisioning refer to this, so it does not change once orders exist."
            required
          />

          <AppSelect v-model="form.type" label="Type" :options="types" :error="form.errors.type" />

          <AppInput
            v-model="form.position"
            label="Position"
            type="number"
            :error="form.errors.position"
          />

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.description"
              label="Description"
              :error="form.errors.description"
              hint="Shown beside the question at checkout."
            />
          </div>

          <div class="flex items-center">
            <AppCheckbox
              v-model="form.is_required"
              label="Required"
              description="The customer has to answer before ordering."
            />
          </div>

          <div v-if="isQuantity" class="grid grid-cols-2 gap-3">
            <AppInput
              v-model="form.min_quantity"
              label="Minimum"
              type="number"
              :error="form.errors.min_quantity"
            />
            <AppInput
              v-model="form.max_quantity"
              label="Maximum"
              type="number"
              :error="form.errors.max_quantity"
              hint="Empty is unbounded."
            />
          </div>
        </div>
      </AppCard>

      <section v-if="!isQuantity" class="flex flex-col gap-4">
        <div class="flex items-end justify-between gap-4">
          <div>
            <h2 class="text-base font-semibold tracking-tight">Choices</h2>
            <p class="text-content-muted mt-1 max-w-[60ch] text-sm leading-relaxed">
              Each choice carries its own price difference. A choice that makes the plan cheaper
              takes a negative amount.
            </p>
          </div>
          <AppButton type="button" size="sm" @click="addChoice">Add choice</AppButton>
        </div>

        <p v-if="form.options.length === 0" class="text-content-muted text-sm">
          No choices yet. A dropdown with nothing in it cannot be answered.
        </p>

        <AppCard v-for="(choice, index) in form.options" :key="index">
          <div class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto]">
              <AppInput
                v-model="choice.label"
                label="Label"
                :error="form.errors[`options.${index}.label` as keyof typeof form.errors]"
                @input="onLabelInput(index)"
              />
              <AppInput
                v-model="choice.value"
                label="Value"
                :error="form.errors[`options.${index}.value` as keyof typeof form.errors]"
              />
              <div class="flex items-end gap-3 pb-1">
                <button
                  type="button"
                  class="pressable text-content-muted hover:text-content rounded-[var(--radius-sm)] px-2 py-1 text-xs underline underline-offset-4"
                  @click="expanded = expanded === index ? null : index"
                >
                  {{ expanded === index ? 'Hide prices' : 'Prices' }}
                </button>
                <button
                  type="button"
                  class="pressable text-danger rounded-[var(--radius-sm)] px-2 py-1 text-xs underline underline-offset-4"
                  @click="removeChoice(index)"
                >
                  Remove
                </button>
              </div>
            </div>

            <label class="flex items-center gap-3 text-sm">
              <input
                type="radio"
                :checked="choice.isDefault"
                class="accent-brand size-4"
                :name="`default-choice`"
                @change="setDefault(index)"
              />
              Default choice
            </label>

            <div v-if="expanded === index" class="border-line border-t pt-4">
              <PriceMatrix
                v-model="choice.prices"
                :cycles="cycles"
                :currencies="currencies"
                allow-negative
                description="These amounts are added to the product price. Use a negative number for a choice that costs less."
              />
            </div>
          </div>
        </AppCard>
      </section>

      <div class="flex items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save option group' : 'Create option group' }}
        </AppButton>
        <AppButton :href="`/admin/catalog/products/${product.id}/options`" variant="ghost">
          Cancel
        </AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
