<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../../Components/AppButton.vue'
import AppCard from '../../../../Components/AppCard.vue'
import AppCheckbox from '../../../../Components/AppCheckbox.vue'
import AppInput from '../../../../Components/AppInput.vue'
import AppSelect from '../../../../Components/AppSelect.vue'
import AppTextarea from '../../../../Components/AppTextarea.vue'
import AdminLayout from '../../../../Layouts/AdminLayout.vue'

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
  <Head :title="isEditing ? 'Edit product' : 'New product'" />

  <AdminLayout
    :heading="isEditing ? (product?.name ?? 'Edit product') : 'New product'"
    description="What the product is and how it is described. Prices live on their own screen."
  >
    <form class="flex flex-col gap-6" @submit.prevent="submit">
      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppSelect
            v-model="form.product_group_id"
            label="Group"
            :options="groups"
            :error="form.errors.product_group_id"
          />

          <AppSelect
            v-model="form.type"
            label="Type"
            :options="types"
            :error="form.errors.type"
            hint="Decides what provisioning needs and whether checkout asks for a domain."
          />

          <AppInput v-model="form.name" label="Name" :error="form.errors.name" required />

          <AppInput
            v-model="form.slug"
            label="Slug"
            :error="form.errors.slug"
            hint="Left empty, it is derived from the name."
          />

          <div class="sm:col-span-2">
            <AppInput
              v-model="form.tagline"
              label="Tagline"
              :error="form.errors.tagline"
              hint="One line under the product name on the storefront."
            />
          </div>

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.description"
              label="Description"
              :error="form.errors.description"
            />
          </div>

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="featureText"
              label="Features"
              :error="form.errors.features"
              hint="One per line. These are the bullet points on the plan card."
            />
          </div>
        </div>
      </AppCard>

      <AppCard>
        <div class="grid gap-5 sm:grid-cols-2">
          <AppSelect
            v-model="form.status"
            label="Status"
            :options="statuses"
            :error="form.errors.status"
            hint="Hidden keeps it orderable by direct link. Retired stops orders entirely."
          />

          <AppInput
            v-model="form.position"
            label="Position"
            type="number"
            :error="form.errors.position"
          />

          <AppInput
            v-model="form.stock"
            label="Stock"
            type="number"
            :error="form.errors.stock"
            hint="Empty is unlimited. Zero reads as sold out."
          />

          <div class="flex items-end pb-1">
            <AppCheckbox
              v-model="effectiveRequiresDomain"
              label="Collect a domain at checkout"
              :description="
                domainOverridden ? 'Overridden for this product.' : 'Following the product type.'
              "
            />
          </div>
        </div>
      </AppCard>

      <div class="flex flex-wrap items-center gap-3">
        <AppButton type="submit" variant="primary" :loading="form.processing">
          {{ isEditing ? 'Save product' : 'Create product' }}
        </AppButton>
        <AppButton href="/admin/catalog/products" variant="ghost">Cancel</AppButton>

        <template v-if="product">
          <span class="text-line-strong" aria-hidden="true">|</span>
          <Link
            v-if="product.canPrice"
            :href="`/admin/catalog/products/${product.id}/pricing`"
            class="text-content-muted hover:text-content text-body underline underline-offset-4"
          >
            Pricing
          </Link>
          <Link
            :href="`/admin/catalog/products/${product.id}/options`"
            class="text-content-muted hover:text-content text-body underline underline-offset-4"
          >
            Options ({{ product.optionGroupCount }})
          </Link>
          <Link
            :href="`/admin/catalog/products/${product.id}/addons`"
            class="text-content-muted hover:text-content text-body underline underline-offset-4"
          >
            Addons ({{ product.addonCount }})
          </Link>
        </template>
      </div>
    </form>
  </AdminLayout>
</template>
