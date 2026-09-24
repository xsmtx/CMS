<script setup lang="ts">
/**
 * What this installation calls itself, and what it looks like.
 *
 * A settings page, so it is one form and one save (enterprise-cms-ux). The
 * sections are `DetailSection`s rather than cards: eight rectangles down a
 * page make eight things look equally important, and the grouping here is a
 * heading and a hairline, not eight separate surfaces.
 *
 * Themes are in the aside and save on their own. Choosing a theme is not part
 * of the brand form — it takes effect immediately and has nothing to do with
 * the fields beside it, so a shared Save would be a button that did two
 * unrelated things.
 *
 * A brand inherits field by field up the organization path, so the aside also
 * shows what customers actually see once the holes are filled. An operator who
 * has set nothing should not be looking at a form full of blanks and guessing.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
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
  brandFields: Record<string, string | null | boolean | LegalLink[]>
  effective: { name: string; logoUrl: string | null; legalLinks: LegalLink[] }
  surfaces: SurfaceRow[]
  vendorMark: string
  can: { manage: boolean; removeVendorMark: boolean }
}>()

const { t } = useTranslations()

function text(key: string): string {
  const value = props.brandFields[key]

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
  legal_links: Array.isArray(props.brandFields.legalLinks) ? [...props.brandFields.legalLinks] : [],
  hide_vendor_mark: props.brandFields.hideVendorMark === true,
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
  <Head :title="t('ui.settings.title')" />

  <AdminLayout :heading="t('ui.settings.title')">
    <template #header>
      <PageHeader :title="t('ui.settings.title')" :description="t('ui.settings.intro')" />
    </template>

    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
      <form class="flex min-w-0 flex-col gap-8" @submit.prevent="save">
        <DetailSection
          :title="t('ui.settings.identity')"
          :description="t('ui.settings.identity_intro')"
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.trading_name"
              :label="t('ui.settings.trading_name')"
              :error="form.errors.trading_name"
            />
            <AppInput
              v-model="form.legal_name"
              :label="t('ui.settings.legal_name')"
              :hint="t('ui.settings.legal_name_hint')"
              :error="form.errors.legal_name"
            />
            <AppInput
              v-model="form.tax_id"
              :label="t('ui.settings.tax_id')"
              :error="form.errors.tax_id"
            />
            <AppInput
              v-model="form.country"
              :label="t('ui.settings.country')"
              :error="form.errors.country"
            />
            <AppInput
              v-model="form.portal_name"
              :label="t('ui.settings.portal_name')"
              :hint="t('ui.settings.portal_name_hint')"
              :error="form.errors.portal_name"
            />
          </div>

          <div class="mt-4">
            <AppTextarea
              v-model="form.address"
              :label="t('ui.settings.address')"
              :rows="3"
              :error="form.errors.address"
            />
          </div>
        </DetailSection>

        <DetailSection
          :title="t('ui.settings.contact')"
          :description="t('ui.settings.contact_intro')"
        >
          <div class="grid gap-4 sm:grid-cols-3">
            <AppInput
              v-model="form.support_email"
              :label="t('ui.settings.support_email')"
              :error="form.errors.support_email"
            />
            <AppInput
              v-model="form.support_phone"
              :label="t('ui.settings.support_phone')"
              :error="form.errors.support_phone"
            />
            <AppInput
              v-model="form.website_url"
              :label="t('ui.settings.website')"
              :error="form.errors.website_url"
            />
          </div>
        </DetailSection>

        <DetailSection
          :title="t('ui.settings.appearance')"
          :description="t('ui.settings.appearance_intro')"
        >
          <div class="grid gap-4 sm:grid-cols-3">
            <AppInput
              v-model="form.logo_url"
              :label="t('ui.settings.logo')"
              :hint="t('ui.settings.logo_hint')"
              :error="form.errors.logo_url"
            />
            <AppInput
              v-model="form.logo_dark_url"
              :label="t('ui.settings.logo_dark')"
              :error="form.errors.logo_dark_url"
            />
            <AppInput
              v-model="form.favicon_url"
              :label="t('ui.settings.favicon')"
              :error="form.errors.favicon_url"
            />
          </div>

          <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
              <AppInput
                v-model="form.accent_color"
                :label="t('ui.settings.accent')"
                :hint="t('ui.settings.accent_hint')"
                :error="form.errors.accent_color"
              />
              <!-- Shown beside the field rather than only on save: a colour
                   is the one setting nobody can check by reading. -->
              <div
                v-if="form.accent_color"
                class="border-line mt-2 h-6 rounded-md border"
                :style="{ background: form.accent_color }"
                aria-hidden="true"
              />
            </div>
            <AppInput
              v-model="form.accent_contrast"
              :label="t('ui.settings.accent_contrast')"
              :error="form.errors.accent_contrast"
            />
            <AppInput
              v-model="form.font_family"
              :label="t('ui.settings.font')"
              :hint="t('ui.settings.font_hint')"
              :error="form.errors.font_family"
            />
          </div>
        </DetailSection>

        <DetailSection :title="t('ui.settings.email')" :description="t('ui.settings.email_intro')">
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.email_from_name"
              :label="t('ui.settings.from_name')"
              :error="form.errors.email_from_name"
            />
            <AppInput
              v-model="form.email_from_address"
              :label="t('ui.settings.from_address')"
              :error="form.errors.email_from_address"
            />
          </div>

          <div class="mt-4 grid gap-4">
            <AppTextarea
              v-model="form.email_footer"
              :label="t('ui.settings.email_footer')"
              :rows="2"
              :error="form.errors.email_footer"
            />
            <AppTextarea
              v-model="form.invoice_footer"
              :label="t('ui.settings.invoice_footer')"
              :hint="t('ui.settings.invoice_footer_hint')"
              :rows="2"
              :error="form.errors.invoice_footer"
            />
          </div>
        </DetailSection>

        <DetailSection :title="t('ui.settings.legal')" :description="t('ui.settings.legal_intro')">
          <template #actions>
            <AppButton size="sm" variant="ghost" icon="add" @click="addLink">
              {{ t('ui.settings.add_link') }}
            </AppButton>
          </template>

          <div v-if="form.legal_links.length > 0" class="flex flex-col gap-3">
            <div
              v-for="(link, index) in form.legal_links"
              :key="index"
              class="grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end"
            >
              <AppInput v-model="link.label" :label="t('ui.settings.link_label')" />
              <AppInput v-model="link.url" :label="t('ui.settings.link_url')" />
              <AppButton size="sm" variant="ghost" @click="removeLink(index)">
                {{ t('ui.settings.remove_link') }}
              </AppButton>
            </div>
          </div>

          <EmptyState
            v-else
            variant="plain"
            icon="document"
            :title="t('ui.settings.no_links')"
            :description="t('ui.settings.no_links_detail')"
          />
        </DetailSection>

        <DetailSection :title="t('ui.settings.mark')" :description="t('ui.settings.mark_intro')">
          <AppCheckbox
            v-model="form.hide_vendor_mark"
            :label="t('ui.settings.hide_mark', { mark: vendorMark })"
            :disabled="!can.removeVendorMark"
          />
          <!-- Said in words rather than shown as a switch that silently does
               nothing. -->
          <p v-if="!can.removeVendorMark" class="text-content-subtle text-chrome mt-1 ml-7">
            {{ t('ui.settings.mark_not_licensed') }}
          </p>
        </DetailSection>

        <!--
          One save for one form, and it is a real submit so Enter in a field
          works: a form with no submit button has no implicit submission, and
          a header button cannot be one because the header is outside the form.
        -->
        <div v-if="can.manage">
          <AppButton type="submit" variant="primary" :loading="form.processing">
            {{ t('ui.settings.save') }}
          </AppButton>
        </div>
      </form>

      <aside class="flex min-w-0 flex-col gap-8">
        <DetailSection
          :title="t('ui.settings.preview')"
          :description="t('ui.settings.preview_intro')"
        >
          <div class="flex items-center gap-3">
            <img
              v-if="inherited.logoUrl"
              :src="inherited.logoUrl"
              :alt="inherited.name"
              class="h-8 w-auto max-w-[10rem] object-contain"
            />
            <span class="text-body font-semibold">{{ inherited.name }}</span>
          </div>

          <ul v-if="inherited.legalLinks.length > 0" class="mt-3 flex flex-wrap gap-x-4 gap-y-1">
            <li
              v-for="link in inherited.legalLinks"
              :key="link.url"
              class="text-content-muted text-chrome"
            >
              {{ link.label }}
            </li>
          </ul>
        </DetailSection>

        <DetailSection
          :title="t('ui.settings.themes')"
          :description="t('ui.settings.themes_intro')"
        >
          <AppAlert v-if="themeForm.errors.theme" tone="danger" class="mb-4">
            {{ themeForm.errors.theme }}
          </AppAlert>

          <div v-for="surface in surfaces" :key="surface.value" class="mb-5 last:mb-0">
            <p class="text-content-subtle text-label uppercase">{{ surface.label }}</p>

            <!-- A divided list rather than a box per theme: a framed row
                 inside a framed panel is a card inside a card. -->
            <!-- A surface with no packages says so. A heading with nothing
                 under it reads as something that failed to load. -->
            <p v-if="surface.themes.length === 0" class="text-content-muted text-chrome mt-1">
              {{ t('ui.settings.no_themes') }}
            </p>

            <ul v-else class="divide-line-subtle mt-1 divide-y">
              <li v-for="theme in surface.themes" :key="theme.value" class="py-2.5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="text-body flex flex-wrap items-center gap-x-2">
                      <span>{{ theme.label }}</span>
                      <AppBadge v-if="theme.value === surface.current" tone="brand">
                        {{ t('ui.settings.in_use') }}
                      </AppBadge>
                    </p>
                    <p v-if="theme.parent" class="text-content-muted text-chrome mt-0.5">
                      {{ t('ui.settings.extends', { parent: theme.parent }) }}
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
                    {{ t('ui.settings.use_theme') }}
                  </AppButton>
                </div>

                <!-- Every problem at once, named. An operator fixing a theme
                     wants the four bad files, not one per attempt. -->
                <ul v-if="theme.problems.length > 0" class="mt-2 flex flex-col gap-1">
                  <li
                    v-for="problem in theme.problems"
                    :key="problem"
                    class="text-danger text-chrome"
                  >
                    {{ problem }}
                  </li>
                </ul>
              </li>
            </ul>
          </div>
        </DetailSection>
      </aside>
    </div>
  </AdminLayout>
</template>
