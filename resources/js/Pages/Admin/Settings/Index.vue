<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface LegalLink {
  label: string
  url: string
}

interface ThemeOption {
  value: string
  label: string
  parent: string | null
  author: string | null
  problems: string[]
}

interface SurfaceRow {
  value: string
  label: string
  current: string
  themes: ThemeOption[]
}

interface BrandForm {
  trading_name: string
  legal_name: string
  tax_id: string
  address: string
  country: string
  support_email: string
  support_phone: string
  website_url: string
  logo_url: string
  logo_dark_url: string
  favicon_url: string
  accent_color: string
  accent_contrast: string
  font_family: string
  portal_name: string
  email_from_name: string
  email_from_address: string
  email_footer: string
  invoice_footer: string
  legal_links: LegalLink[]
  hide_vendor_mark: boolean
}

const props = defineProps<{
  brand: Record<string, string | null | boolean | LegalLink[]>
  effective: { name: string; logoUrl: string | null; legalLinks: LegalLink[] }
  surfaces: SurfaceRow[]
  vendorMark: string
  can: { manage: boolean; removeVendorMark: boolean }
}>()

function text(key: string): string {
  const value = props.brand[key]

  return typeof value === 'string' ? value : ''
}

const form = useForm<BrandForm>({
  trading_name: text('tradingName'),
  legal_name: text('legalName'),
  tax_id: text('taxId'),
  address: text('address'),
  country: text('country'),
  support_email: text('supportEmail'),
  support_phone: text('supportPhone'),
  website_url: text('websiteUrl'),
  logo_url: text('logoUrl'),
  logo_dark_url: text('logoDarkUrl'),
  favicon_url: text('faviconUrl'),
  accent_color: text('accentColor'),
  accent_contrast: text('accentContrast'),
  font_family: text('fontFamily'),
  portal_name: text('portalName'),
  email_from_name: text('emailFromName'),
  email_from_address: text('emailFromAddress'),
  email_footer: text('emailFooter'),
  invoice_footer: text('invoiceFooter'),
  legal_links: Array.isArray(props.brand.legalLinks) ? [...props.brand.legalLinks] : [],
  hide_vendor_mark: props.brand.hideVendorMark === true,
})

const themeForm = useForm({ surface: '', theme: '' })

// What is actually showing once inheritance has filled the holes. An
// operator who has set nothing should be able to see what their customers
// see rather than a form full of blanks.
const inherited = computed(() => props.effective)

function save(): void {
  form.put('/admin/settings/brand', { preserveScroll: true })
}

function applyTheme(surface: string, theme: string): void {
  themeForm.surface = surface
  themeForm.theme = theme
  themeForm.put('/admin/settings/theme', { preserveScroll: true })
}

function addLink(): void {
  form.legal_links = [...form.legal_links, { label: '', url: '' }]
}

function removeLink(index: number): void {
  form.legal_links = form.legal_links.filter((_, position) => position !== index)
}
</script>

<template>
  <Head title="Settings" />

  <AdminLayout
    heading="Settings"
    description="What this installation calls itself, and what it looks like."
  >
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="flex flex-col gap-6 lg:col-span-2">
        <AppCard
          title="Identity"
          description="The name customers see, and the one a court sees. They differ often enough that an invoice needs both."
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.trading_name"
              label="Trading name"
              :error="form.errors.trading_name"
            />
            <AppInput
              v-model="form.legal_name"
              label="Legal name"
              hint="Printed on invoices. Falls back to the trading name."
              :error="form.errors.legal_name"
            />
            <AppInput v-model="form.tax_id" label="Tax number" :error="form.errors.tax_id" />
            <AppInput v-model="form.country" label="Country" :error="form.errors.country" />
            <AppInput
              v-model="form.portal_name"
              label="Portal name"
              hint="What the customer area calls itself, if not the trading name."
              :error="form.errors.portal_name"
            />
          </div>

          <div class="mt-4">
            <AppTextarea
              v-model="form.address"
              label="Address"
              :rows="3"
              :error="form.errors.address"
            />
          </div>
        </AppCard>

        <AppCard title="Contact" description="Shown in the storefront footer and on documents.">
          <div class="grid gap-4 sm:grid-cols-3">
            <AppInput
              v-model="form.support_email"
              label="Support email"
              :error="form.errors.support_email"
            />
            <AppInput
              v-model="form.support_phone"
              label="Support phone"
              :error="form.errors.support_phone"
            />
            <AppInput v-model="form.website_url" label="Website" :error="form.errors.website_url" />
          </div>
        </AppCard>

        <AppCard
          title="Appearance"
          description="Colours override the design tokens everything else is built on, so one change reaches every button, badge and link."
        >
          <div class="grid gap-4 sm:grid-cols-3">
            <AppInput
              v-model="form.logo_url"
              label="Logo"
              hint="An HTTPS address."
              :error="form.errors.logo_url"
            />
            <AppInput
              v-model="form.logo_dark_url"
              label="Logo for dark backgrounds"
              :error="form.errors.logo_dark_url"
            />
            <AppInput v-model="form.favicon_url" label="Favicon" :error="form.errors.favicon_url" />
          </div>

          <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
              <AppInput
                v-model="form.accent_color"
                label="Accent colour"
                hint="Hex, such as #2563eb."
                :error="form.errors.accent_color"
              />
              <!-- Shown beside the field rather than only on save: a colour
                   is the one setting nobody can check by reading. -->
              <div
                v-if="form.accent_color"
                class="border-line mt-2 h-6 rounded-[var(--radius-sm)] border"
                :style="{ background: form.accent_color }"
                aria-hidden="true"
              />
            </div>
            <AppInput
              v-model="form.accent_contrast"
              label="Text on the accent"
              :error="form.errors.accent_contrast"
            />
            <AppInput
              v-model="form.font_family"
              label="Font stack"
              hint="A CSS font stack. Loading a webfont is a theme's job."
              :error="form.errors.font_family"
            />
          </div>
        </AppCard>

        <AppCard
          title="Email and invoices"
          description="The identity messages go out under. The credentials that send them stay in configuration."
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.email_from_name"
              label="From name"
              :error="form.errors.email_from_name"
            />
            <AppInput
              v-model="form.email_from_address"
              label="From address"
              :error="form.errors.email_from_address"
            />
          </div>

          <div class="mt-4 grid gap-4">
            <AppTextarea
              v-model="form.email_footer"
              label="Email footer"
              :rows="2"
              :error="form.errors.email_footer"
            />
            <AppTextarea
              v-model="form.invoice_footer"
              label="Invoice footer"
              hint="Payment terms, a registration number, whatever your jurisdiction expects."
              :rows="2"
              :error="form.errors.invoice_footer"
            />
          </div>
        </AppCard>

        <AppCard
          title="Legal links"
          description="Shown in the storefront footer. Every jurisdiction wants a different set."
        >
          <div v-if="form.legal_links.length > 0" class="flex flex-col gap-3">
            <div
              v-for="(link, index) in form.legal_links"
              :key="index"
              class="grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end"
            >
              <AppInput v-model="link.label" label="Label" />
              <AppInput v-model="link.url" label="Address" />
              <AppButton size="sm" variant="ghost" @click="removeLink(index)">Remove</AppButton>
            </div>
          </div>

          <p v-else class="text-content-muted text-sm">No links yet.</p>

          <div class="mt-4">
            <AppButton size="sm" variant="ghost" @click="addLink">Add a link</AppButton>
          </div>
        </AppCard>

        <AppCard
          title="Platform mark"
          description="The line in the storefront footer that credits this platform."
        >
          <AppCheckbox
            v-model="form.hide_vendor_mark"
            :label="`Hide “${vendorMark}”`"
            :disabled="!can.removeVendorMark"
          />
          <!-- Said in words rather than shown as a switch that silently does
               nothing. -->
          <p v-if="!can.removeVendorMark" class="text-content-subtle mt-1 ml-7 text-xs">
            Removing the platform mark is not included in this licence.
          </p>
        </AppCard>

        <div v-if="can.manage">
          <AppButton variant="primary" :loading="form.processing" @click="save">Save</AppButton>
        </div>
      </div>

      <div class="flex flex-col gap-6">
        <AppCard
          title="What customers see"
          description="With anything you have not set filled in from the organization above you."
        >
          <div class="flex items-center gap-3">
            <img
              v-if="inherited.logoUrl"
              :src="inherited.logoUrl"
              :alt="inherited.name"
              class="h-8 w-auto max-w-[10rem] object-contain"
            />
            <span class="text-sm font-semibold">{{ inherited.name }}</span>
          </div>

          <ul v-if="inherited.legalLinks.length > 0" class="mt-3 flex flex-wrap gap-x-4 gap-y-1">
            <li
              v-for="link in inherited.legalLinks"
              :key="link.url"
              class="text-content-muted text-xs"
            >
              {{ link.label }}
            </li>
          </ul>
        </AppCard>

        <AppCard
          title="Themes"
          description="A theme is templates, assets and a manifest. Behaviour belongs in a module."
        >
          <AppAlert v-if="themeForm.errors.theme" tone="danger" class="mb-4">
            {{ themeForm.errors.theme }}
          </AppAlert>

          <div v-for="surface in surfaces" :key="surface.value" class="mb-6 last:mb-0">
            <p class="text-sm font-medium">{{ surface.label }}</p>

            <ul class="mt-2 flex flex-col gap-2">
              <li
                v-for="theme in surface.themes"
                :key="theme.value"
                class="border-line rounded-[var(--radius-sm)] border p-3"
              >
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="text-sm">
                      {{ theme.label }}
                      <AppBadge v-if="theme.value === surface.current" class="ml-2" tone="brand">
                        In use
                      </AppBadge>
                    </p>
                    <p v-if="theme.parent" class="text-content-muted mt-0.5 text-xs">
                      Extends {{ theme.parent }}
                    </p>
                  </div>

                  <AppButton
                    v-if="
                      can.manage && theme.value !== surface.current && theme.problems.length === 0
                    "
                    size="sm"
                    variant="ghost"
                    @click="applyTheme(surface.value, theme.value)"
                  >
                    Use this theme
                  </AppButton>
                </div>

                <!-- Every problem at once, named. An operator fixing a theme
                     wants the four bad files, not one per attempt. -->
                <ul v-if="theme.problems.length > 0" class="mt-2 flex flex-col gap-1">
                  <li v-for="problem in theme.problems" :key="problem" class="text-danger text-xs">
                    {{ problem }}
                  </li>
                </ul>
              </li>
            </ul>
          </div>
        </AppCard>
      </div>
    </div>
  </AdminLayout>
</template>
