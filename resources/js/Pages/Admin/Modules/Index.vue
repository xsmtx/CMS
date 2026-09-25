<script setup lang="ts">
/**
 * Modules: what is installed, what is on disk, and what each one does.
 *
 * The screen's job is to make the decision legible before it is taken. A
 * module is code somebody else wrote, so the row shows the type, the
 * version, the author, and — once enabled — exactly which seams the package
 * reached into. "This module provides the Stripe gateway" is a sentence an
 * operator can check against what they expected when they downloaded it.
 *
 * Enable is the one destructive-looking button here, and it is deliberately
 * not styled as the safe one: it runs code this platform did not ship.
 */
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import ModuleSettings from './ModuleSettings.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

import type { ConfigFieldProps } from './ModuleSettings.vue'

interface ModuleRow {
  slug: string
  name: string
  type: string
  typeLabel: string
  version: string
  provider: string | null
  state: string
  stateLabel: string
  failureReason: string | null
  installedAt: string | null
  enabledAt: string | null
  onDisk: string | null
  upgradable: boolean
  registers: { point: string; label: string; keys: string[] }[]
  config: ConfigFieldProps[]
}

interface AvailableModule {
  slug: string
  name: string
  type: string
  typeLabel: string
  version: string
  provider: string | null
  description: string | null
  sdk: string
  platform: string
}

defineProps<{
  modules: ModuleRow[]
  available: AvailableModule[]
  sdk: string
  enabledForInstallation: boolean
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const expanded = ref<string | null>(null)

function toggle(slug: string): void {
  expanded.value = expanded.value === slug ? null : slug
}

function install(slug: string): void {
  router.post('/admin/apps/modules', { slug }, { preserveScroll: true })
}

function act(slug: string, action: 'enable' | 'disable' | 'upgrade'): void {
  router.post(`/admin/apps/modules/${slug}/${action}`, {}, { preserveScroll: true })
}

/**
 * Uninstalling is asked about first. It was one click from a row of
 * settings, and it removes a module the platform may be running on.
 */
const uninstalling = ref<{ slug: string; name: string } | null>(null)

function uninstall(): void {
  if (uninstalling.value === null) return

  router.delete(`/admin/apps/modules/${uninstalling.value.slug}`, {
    preserveScroll: true,
    onFinish: () => (uninstalling.value = null),
  })
}
</script>

<template>
  <Head :title="t('ui.modules.title')" />

  <AdminLayout :heading="t('ui.modules.title')">
    <template #header>
      <PageHeader :title="t('ui.modules.title')" :description="t('ui.modules.intro')">
        <template #meta>
          <span>{{ t('ui.modules.sdk', { version: sdk }) }}</span>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        A message, not a surface. This was a card with a title and a sentence
        and nothing in it, which is a rectangle pretending to be content.
      -->
      <AppAlert v-if="!enabledForInstallation" tone="warning">
        {{ t('ui.modules.disabled', { name: 'PLATFORM_MODULES_ENABLED' }) }}
      </AppAlert>

      <DetailSection
        :title="t('ui.modules.installed')"
        :description="t('ui.modules.installed_intro')"
        :divided="modules.length === 0"
      >
        <!-- A frame per module: each is a package with its own state, its own
             actions and its own settings, and the boundary has to be visible
             before somebody presses Enable on the wrong one. -->
        <div
          v-for="module in modules"
          :key="module.slug"
          class="border-line bg-surface-primary mb-4 rounded-lg border p-4 last:mb-0"
        >
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-title font-semibold tracking-tight">{{ module.name }}</h2>
                <AppStatus :tone="statusTone(module.state)" :label="module.stateLabel" />
                <AppBadge>{{ module.typeLabel }}</AppBadge>
              </div>
              <p class="text-content-muted text-chrome mt-1.5">
                {{ module.slug }} · {{ module.version }}
                <span v-if="module.provider"> · {{ module.provider }}</span>
                <span v-if="module.onDisk === null" class="text-danger">
                  · {{ t('ui.modules.missing_files') }}
                </span>
                <span v-else-if="module.upgradable">
                  · {{ t('ui.modules.on_disk', { version: module.onDisk }) }}
                </span>
              </p>
              <p v-if="module.failureReason" class="text-danger text-chrome mt-2 max-w-[70ch]">
                {{ module.failureReason }}
              </p>
            </div>

            <div v-if="can.manage" class="flex flex-wrap gap-2">
              <AppButton
                v-if="module.state !== 'enabled'"
                size="sm"
                variant="primary"
                @click="act(module.slug, 'enable')"
              >
                {{ t('ui.modules.enable') }}
              </AppButton>
              <AppButton v-else size="sm" @click="act(module.slug, 'disable')">
                {{ t('ui.modules.disable') }}
              </AppButton>
              <AppButton v-if="module.upgradable" size="sm" @click="act(module.slug, 'upgrade')">
                {{ t('ui.modules.upgrade') }}
              </AppButton>
              <AppButton size="sm" variant="ghost" @click="toggle(module.slug)">
                {{ expanded === module.slug ? t('ui.modules.hide') : t('ui.modules.details') }}
              </AppButton>
            </div>
          </div>

          <div v-if="expanded === module.slug" class="border-line-subtle mt-5 border-t pt-5">
            <div v-if="module.registers.length > 0">
              <p class="text-content-subtle text-label mb-2 uppercase">
                {{ t('ui.modules.what_it_adds') }}
              </p>
              <ul class="text-body flex flex-col gap-1.5">
                <li v-for="entry in module.registers" :key="entry.point" class="flex gap-2">
                  <span class="text-content-muted min-w-[10rem]">{{ entry.label }}</span>
                  <span class="text-chrome font-mono">{{ entry.keys.join(', ') }}</span>
                </li>
              </ul>
            </div>
            <p v-else class="text-content-muted text-body">
              {{ t('ui.modules.nothing_recorded') }}
            </p>

            <ModuleSettings
              v-if="module.config.length > 0 && can.manage"
              :slug="module.slug"
              :fields="module.config"
            />

            <div v-if="can.manage" class="border-line-subtle mt-5 border-t pt-5">
              <AppButton
                size="sm"
                variant="danger-subtle"
                @click="uninstalling = { slug: module.slug, name: module.name }"
              >
                {{ t('ui.modules.uninstall') }}
              </AppButton>
              <p class="text-content-muted text-chrome mt-2 max-w-[60ch] leading-relaxed">
                {{ t('ui.modules.uninstall_hint') }}
              </p>
            </div>
          </div>
        </div>

        <EmptyState
          v-if="modules.length === 0"
          variant="plain"
          icon="modules"
          :title="t('ui.modules.none')"
          :description="t('ui.modules.none_detail')"
        />
      </DetailSection>

      <DetailSection
        v-if="available.length > 0"
        :title="t('ui.modules.on_disk_title')"
        :description="t('ui.modules.on_disk_intro')"
      >
        <ul class="divide-line-subtle flex flex-col divide-y">
          <li
            v-for="module in available"
            :key="module.slug"
            class="flex flex-wrap items-start justify-between gap-4 py-3 first:pt-0 last:pb-0"
          >
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="text-body font-medium">{{ module.name }}</span>
                <AppBadge>{{ module.typeLabel }}</AppBadge>
              </div>
              <p class="text-content-muted text-chrome mt-1">
                {{ module.slug }} · {{ module.version }}
                <span v-if="module.provider"> · {{ module.provider }}</span>
                · SDK {{ module.sdk }}
              </p>
              <p v-if="module.description" class="text-content-muted text-body mt-1 max-w-[60ch]">
                {{ module.description }}
              </p>
            </div>
            <AppButton v-if="can.manage" size="sm" @click="install(module.slug)">
              {{ t('ui.modules.install') }}
            </AppButton>
          </li>
        </ul>
      </DetailSection>
    </div>

    <AppConfirm
      :open="uninstalling !== null"
      level="consequential"
      :title="t('ui.modules.uninstall_title', { name: uninstalling?.name ?? '' })"
      :description="t('ui.modules.uninstall_detail')"
      :confirm-label="t('ui.modules.uninstall_confirm')"
      @update:open="(value: boolean) => (uninstalling = value ? uninstalling : null)"
      @confirm="uninstall"
    />
  </AdminLayout>
</template>
