<script setup lang="ts">
/**
 * Apps and Integrations: one door, and what is behind it.
 *
 * A hub rather than a menu because the two things here are the two ways an
 * administrator account becomes control of the estate — enabling a package
 * runs code this platform did not ship, and adding a server hands out
 * credentials to a machine. Putting them together, behind one gate, is
 * easier to reason about than finding them scattered through Setup.
 */
import { Head, Link } from '@inertiajs/vue3'

import AdminLayout from '../../../Layouts/AdminLayout.vue'

defineProps<{
  areas: { key: string; href: string; count: number; total: number }[]
}>()

const COPY: Record<string, { label: string; description: string; unit: string }> = {
  modules: {
    label: 'Modules',
    description:
      'Packages that add payment gateways, provisioning, registrars and more. Nothing runs until you enable it.',
    unit: 'enabled',
  },
  servers: {
    label: 'Servers',
    description: 'The machines accounts are created on, and the credentials that reach them.',
    unit: 'configured',
  },
}

function copy(key: string) {
  return COPY[key] ?? { label: key, description: '', unit: '' }
}
</script>

<template>
  <Head title="Apps & Integrations" />

  <AdminLayout
    heading="Apps & Integrations"
    description="Everything that connects this platform to something else. Open to the owner of this installation only."
  >
    <div class="grid gap-5 sm:grid-cols-2">
      <Link
        v-for="area in areas"
        :key="area.key"
        :href="area.href"
        class="pressable border-line bg-surface-raised hover:border-line-strong block rounded-[var(--radius-lg)] border p-6 shadow-(--shadow-raised) transition-colors duration-(--duration-fast)"
      >
        <div class="flex items-start justify-between gap-4">
          <h2 class="text-[0.9375rem] font-semibold tracking-tight">{{ copy(area.key).label }}</h2>
          <span class="text-content-muted text-xs tabular-nums">
            {{ area.count }} {{ copy(area.key).unit }}
          </span>
        </div>
        <p class="text-content-muted mt-2 max-w-[52ch] text-sm leading-relaxed">
          {{ copy(area.key).description }}
        </p>
      </Link>
    </div>

    <p class="text-content-muted mt-8 max-w-[70ch] text-xs leading-relaxed">
      This area is open to super administrators only. It is not a permission: an administrator holds
      every staff permission by design, so no permission could mean “the owner of this
      installation”.
    </p>
  </AdminLayout>
</template>
