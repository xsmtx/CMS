<script setup lang="ts">
/**
 * Setup: one page instead of a dropdown.
 *
 * A menu of eight links tells an operator the names of eight screens. A page can
 * tell them what each one is for and how much is in it, which is the difference
 * between "Roles" and "Roles — 6 defined, who may do what". WHMCS operators will
 * recognise the shape; it is what its System Settings page does, and it is the
 * one place in that product where the thing you were looking for is visible
 * rather than remembered.
 *
 * **The second section is the dangerous one and looks it.** Modules, Servers,
 * Connect, Licence and Import are owner-only, and they are owner-only for the same
 * reason: each of them is a way an administrator account becomes control of the
 * estate. Keeping them together, under their own heading, is how an operator
 * learns that they are the same kind of thing.
 *
 * Labels come from the server rather than from a map in here, because the page
 * lists permission-gated screens and the wording is operator vocabulary that
 * already lives in `lang/`.
 */
import { Head, Link } from '@inertiajs/vue3'

import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface Area {
  key: string
  href: string
  label: string
  description: string
  unit: string
  count: number | null
}

defineProps<{
  sections: { key: string; areas: Area[] }[]
}>()

const { t } = useTranslations()
</script>

<template>
  <Head title="Setup" />

  <AdminLayout :heading="t('apps.heading')" :description="t('apps.description')">
    <div class="flex flex-col gap-8">
      <section v-for="section in sections" :key="section.key" class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <h2 class="text-title font-semibold tracking-tight">
            {{ t(`apps.sections.${section.key}.title`) }}
          </h2>
          <p class="text-content-muted max-w-[70ch] text-sm leading-relaxed">
            {{ t(`apps.sections.${section.key}.description`) }}
          </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <Link
            v-for="area in section.areas"
            :key="area.key"
            :href="area.href"
            class="pressable border-line bg-surface-primary hover:border-line-strong block rounded-[var(--radius-lg)] border p-5 shadow-(--shadow-raised) transition-colors duration-(--duration-fast)"
          >
            <div class="flex items-start justify-between gap-3">
              <h3 class="text-body font-semibold">{{ area.label }}</h3>
              <span
                v-if="area.count !== null"
                class="text-content-muted shrink-0 text-xs tabular-nums"
              >
                {{ area.count }} {{ area.unit }}
              </span>
            </div>
            <p class="text-content-muted mt-1.5 text-sm leading-relaxed">
              {{ area.description }}
            </p>
          </Link>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
