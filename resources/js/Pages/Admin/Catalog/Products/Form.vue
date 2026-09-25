<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import DetailSection from '../../../../Components/DetailSection.vue'
import AppCheckbox from '../../../../Components/AppCheckbox.vue'
import AppInput from '../../../../Components/AppInput.vue'
import AppSelect from '../../../../Components/AppSelect.vue'
import AppTextarea from '../../../../Components/AppTextarea.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../../composables/useTranslations'

interface TypeOption {
  value: string
  label: string
  requiresDomain: boolean
}

const props = defineProps<{
  product: {
    id: string
    productGroupId: string
    name: string
    slug: string
    type: string
    tagline: string | null
    description: string | null
    features: string[]
    status: string
    position: number
    stock: number | null
    requiresDomain: boolean
    optionGroupCount: number
    addonCount: number
    canPrice: boolean
  } | null
  groups: { value: string; label: string }[]
  types: TypeOption[]
  statuses: { value: string; label: string }[]
}>()

const { t } = useTranslations()

const isEditing = computed(() => props.product !== null)

const form = useForm({
  product_group_id: props.product?.productGroupId ?? props.groups[0]?.value ?? '',
  name: props.product?.name ?? '',
  slug: props.product?.slug ?? '',
  type: props.product?.type ?? props.types[0]?.value ?? 'shared_hosting',
  tagline: props.product?.tagline ?? '',
  description: props.product?.description ?? '',
  features: props.product?.features ?? ([] as string[]),
  status: props.product?.status ?? 'active',
  position: String(props.product?.position ?? 0),
  stock: props.product?.stock === null || props.product === null ? '' : String(props.product.stock),
  requires_domain: props.product?.requiresDomain ?? null,
})

// Left untouched, the domain rule follows the product type. Once an operator
// overrides it, their choice sticks.
const domainOverridden = ref(props.product !== null)

const typeRequiresDomain = computed(
  () => props.types.find((type) => type.value === form.type)?.requiresDomain ?? false,
)

const effectiveRequiresDomain = computed({
  get: () => (domainOverridden.value ? (form.requires_domain ?? false) : typeRequiresDomain.value),
  set: (value: boolean) => {
    domainOverridden.value = true
    form.requires_domain = value
  },
})

const featureText = computed({
  get: () => form.features.join('\n'),
  set: (value: string) => {
    form.features = value
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line !== '')
  },
})

function submit(): void {
  form
    .transform((data) => ({
      ...data,
      position: Number(data.position),
      stock: data.stock === '' ? null : Number(data.stock),
      requires_domain: domainOverridden.value ? effectiveRequiresDomain.value : null,
    }))
    [props.product ? 'put' : 'post'](
      props.product ? `/admin/catalog/products/${props.product.id}` : '/admin/catalog/products',
    )
}
</script>

<template>
  <Head :title="isEditing ? t('catalog.products.edit') : t('catalog.products.create')" />

  <AdminLayout
    :heading="
      isEditing ? (product?.name ?? t('catalog.products.edit')) : t('catalog.products.create')
    "
    :description="t('catalog.products.form_intro')"
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <DetailSection :title="t('catalog.products.the_product')">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppSelect
            v-model="form.product_group_id"
            :label="t('catalog.products.group')"
            :options="groups"
            :error="form.errors.product_group_id"
          />

          <AppSelect
            v-model="form.type"
            :label="t('catalog.products.type')"
            :options="types"
            :error="form.errors.type"
            :hint="t('catalog.products.type_hint')"
          />

          <AppInput
            v-model="form.name"
            :label="t('catalog.products.name')"
            :error="form.errors.name"
            required
          />

          <AppInput
            v-model="form.slug"
            :label="t('catalog.products.slug')"
            :error="form.errors.slug"
            :hint="t('catalog.products.slug_hint')"
          />

          <div class="sm:col-span-2">
            <AppInput
              v-model="form.tagline"
              :label="t('catalog.products.tagline')"
              :error="form.errors.tagline"
              :hint="t('catalog.products.tagline_hint')"
            />
          </div>

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.description"
              :label="t('catalog.products.description')"
              :error="form.errors.description"
            />
          </div>

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="featureText"
              :label="t('catalog.products.features')"
              :error="form.errors.features"
              :hint="t('catalog.products.features_hint')"
            />
          </div>
        </div>
      </DetailSection>

      <DetailSection :title="t('catalog.products.how_it_sells')">
        <div class="grid gap-5 sm:grid-cols-2">
          <AppSelect
            v-model="form.status"
            :label="t('catalog.products.status')"
            :options="statuses"
            :error="form.errors.status"
            :hint="t('catalog.products.status_hint')"
          />

          <AppInput
            v-model="form.position"
            :label="t('catalog.products.position')"
            type="number"
            :error="form.errors.position"
          />

          <AppInput
            v-model="form.stock"
            :label="t('catalog.products.stock')"
            type="number"
            :error="form.errors.stock"
            :hint="t('catalog.products.stock_hint')"
          />

          <div class="flex items-end pb-1">
            <AppCheckbox
              v-model="effectiveRequiresDomain"
              :label="t('catalog.products.collect_domain')"
              :description="
                domainOverridden
                  ? t('catalog.products.domain_overridden')
                  : t('catalog.products.domain_from_type')
              "
            />
          </div>
        </div>
      </DetailSection>

      <div class="flex flex-wrap items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? t('catalog.products.save') : t('catalog.products.create_submit') }}
        </AppButton>
        <AppButton href="/admin/catalog/products" variant="ghost">{{
          t('ui.confirm.cancel')
        }}</AppButton>

        <template v-if="product">
          <span class="text-line-strong" aria-hidden="true">|</span>
          <Link
            v-if="product.canPrice"
            :href="`/admin/catalog/products/${product.id}/pricing`"
            class="text-content-muted hover:text-content text-body underline underline-offset-4"
          >
            {{ t('catalog.products.pricing') }}
          </Link>
          <Link
            :href="`/admin/catalog/products/${product.id}/options`"
            class="text-content-muted hover:text-content text-body underline underline-offset-4"
          >
            {{ t('catalog.products.options', { count: product.optionGroupCount }) }}
          </Link>
          <Link
            :href="`/admin/catalog/products/${product.id}/addons`"
            class="text-content-muted hover:text-content text-body underline underline-offset-4"
          >
            {{ t('catalog.products.addons', { count: product.addonCount }) }}
          </Link>
        </template>
      </div>
    </form>
  </AdminLayout>
</template>
