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

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import ModuleSettings from './ModuleSettings.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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

function uninstall(slug: string): void {
  router.delete(`/admin/apps/modules/${slug}`, { preserveScroll: true })
}

function tone(state: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (state === 'enabled') return 'success'
  if (state === 'failed') return 'danger'
  if (state === 'installed') return 'warning'
  return 'neutral'
}
</script>

<template>
  <Head title="Modules" />

  <AdminLayout
    heading="Modules"
    description="Packages that extend this platform. Nothing on disk runs until you enable it."
  >
    <p class="text-content-muted mb-6 text-xs">
      SDK {{ sdk }} — a module declares the range it was built for and is refused outside it.
    </p>

    <AppCard
      v-if="!enabledForInstallation"
      title="Modules are turned off for this installation"
      description="PLATFORM_MODULES_ENABLED is false. Nothing will be loaded, whatever a row says."
      class="mb-6"
    />

    <div v-if="modules.length > 0" class="mb-8 flex flex-col gap-4">
      <AppCard v-for="module in modules" :key="module.slug">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-[0.9375rem] font-semibold tracking-tight">{{ module.name }}</h2>
              <AppBadge :tone="tone(module.state)">{{ module.stateLabel }}</AppBadge>
              <AppBadge>{{ module.typeLabel }}</AppBadge>
            </div>
            <p class="text-content-muted mt-1.5 text-xs">
              {{ module.slug }} · {{ module.version }}
              <span v-if="module.provider"> · {{ module.provider }}</span>
              <span v-if="module.onDisk === null" class="text-danger">
                · the files are no longer on disk
              </span>
              <span v-else-if="module.upgradable"> · {{ module.onDisk }} is on disk</span>
            </p>
            <p v-if="module.failureReason" class="text-danger mt-2 max-w-[70ch] text-xs">
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
              Enable
            </AppButton>
            <AppButton v-else size="sm" @click="act(module.slug, 'disable')">Disable</AppButton>
            <AppButton v-if="module.upgradable" size="sm" @click="act(module.slug, 'upgrade')">
              Upgrade
            </AppButton>
            <AppButton size="sm" variant="ghost" @click="toggle(module.slug)">
              {{ expanded === module.slug ? 'Hide' : 'Details' }}
            </AppButton>
          </div>
        </div>

        <div v-if="expanded === module.slug" class="border-line mt-5 border-t pt-5">
          <div v-if="module.registers.length > 0">
            <p class="text-content-muted mb-2 text-xs font-medium">What it adds</p>
            <ul class="flex flex-col gap-1.5 text-sm">
              <li v-for="entry in module.registers" :key="entry.point" class="flex gap-2">
                <span class="text-content-muted min-w-[10rem]">{{ entry.label }}</span>
                <span class="font-mono text-xs">{{ entry.keys.join(', ') }}</span>
              </li>
            </ul>
          </div>
          <p v-else class="text-content-muted text-sm">
            Nothing recorded yet — a module says what it adds when it is enabled.
          </p>

          <ModuleSettings
            v-if="module.config.length > 0 && can.manage"
            :slug="module.slug"
            :fields="module.config"
          />

          <div v-if="can.manage" class="border-line mt-5 border-t pt-5">
            <AppButton size="sm" variant="danger" @click="uninstall(module.slug)">
              Uninstall
            </AppButton>
            <p class="text-content-muted mt-2 max-w-[60ch] text-xs leading-relaxed">
              Refused while anything still points at this module. Its own tables are left in place:
              removing them is a separate, deliberate act.
            </p>
          </div>
        </div>
      </AppCard>
    </div>

    <EmptyState
      v-else
      title="No modules installed"
      description="A module is a folder under modules/. Put one there and it appears below, doing nothing, until you install and enable it."
    />

    <AppCard
      v-if="available.length > 0"
      title="On disk, not installed"
      description="Read what a package says about itself before you run any of it."
    >
      <ul class="flex flex-col gap-4">
        <li
          v-for="module in available"
          :key="module.slug"
          class="flex flex-wrap items-start justify-between gap-4"
        >
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-medium">{{ module.name }}</span>
              <AppBadge>{{ module.typeLabel }}</AppBadge>
            </div>
            <p class="text-content-muted mt-1 text-xs">
              {{ module.slug }} · {{ module.version }}
              <span v-if="module.provider"> · {{ module.provider }}</span>
              · SDK {{ module.sdk }}
            </p>
            <p v-if="module.description" class="text-content-muted mt-1 max-w-[60ch] text-sm">
              {{ module.description }}
            </p>
          </div>
          <AppButton v-if="can.manage" size="sm" @click="install(module.slug)">Install</AppButton>
        </li>
      </ul>
    </AppCard>
  </AdminLayout>
</template>
