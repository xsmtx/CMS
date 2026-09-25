<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface Check {
  key: string
  label: string
  state: string
  stateLabel: string
  detail: string | null
  measurements: Record<string, string | number>
}

const props = defineProps<{
  overall: string
  checks: Check[]
  runtime: { version: string; php: string; environment: string; checkedAt: string }
  maintenance: { active: boolean; message: string | null; until: string | null }
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const editing = ref(false)

const form = useForm({
  enabled: !props.maintenance.active,
  message: props.maintenance.message ?? '',
  until: props.maintenance.until === null ? '' : props.maintenance.until.slice(0, 16),
})

function save(): void {
  form.put('/admin/health/maintenance', {
    preserveScroll: true,
    onSuccess: () => (editing.value = false),
  })
}

function turnOff(): void {
  form.enabled = false
  form.put('/admin/health/maintenance', { preserveScroll: true })
}

/**
 * Shape as well as colour (Handoff #3 §7).
 *
 * This is the screen somebody opens at three in the morning, often shared
 * over a call or photographed into a post-mortem. A green dot beside an
 * amber dot is one bit of information for most readers and none for the
 * rest, so every state here also carries a mark that survives greyscale.
 *
 * The words come from the shared vocabulary. One rule stays local: on this
 * screen a state nobody recognises is treated as failing, not as unknown —
 * only a check that says `unknown` gets to be unknown.
 */
function healthTone(state: string): StatusTone {
  const tone = statusTone(state)

  return tone === 'unknown' && state !== 'unknown' ? 'critical' : tone
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="t('ui.health.title')" />

  <AdminLayout :heading="t('ui.health.title')">
    <template #header>
      <PageHeader :title="t('ui.health.title')" :description="t('ui.health.intro')">
        <template #status>
          <AppStatus
            :tone="healthTone(overall)"
            :label="checks.find((check) => check.state === overall)?.stateLabel ?? overall"
          />
        </template>

        <template #meta>
          <span>{{ t('ui.health.checked', { at: formatDateTime(runtime.checkedAt) }) }}</span>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        A divided list, not a card per check. The question somebody opens this
        screen with is "which one is red", and a column of states answers it in
        one glance where a grid of boxes makes them hunt.
      -->
      <DetailSection :title="t('ui.health.checks')" :description="t('ui.health.checks_intro')">
        <ul class="divide-line-subtle divide-y">
          <li v-for="check in checks" :key="check.key" class="py-3 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
              <h3 class="text-body font-medium">{{ check.label }}</h3>
              <AppStatus :tone="healthTone(check.state)" :label="check.stateLabel" />
            </div>

            <p v-if="check.detail" class="text-content-muted text-chrome mt-1 leading-relaxed">
              {{ check.detail }}
            </p>

            <!-- Numbers, never configuration. A health page that proved the
                 database was configured by printing its DSN would have
                 published a password. -->
            <dl
              v-if="Object.keys(check.measurements).length > 0"
              class="text-content-muted text-chrome mt-1.5 flex flex-wrap gap-x-5 gap-y-1"
            >
              <div v-for="(value, key) in check.measurements" :key="key">
                <dt class="inline">{{ key }}</dt>
                <dd class="text-content ml-1 inline tabular-nums">{{ value }}</dd>
              </div>
            </dl>
          </li>
        </ul>
      </DetailSection>

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-2">
        <DetailSection :title="t('ui.health.installation')">
          <dl class="text-content-muted text-chrome flex flex-wrap gap-x-6 gap-y-1">
            <div>
              <dt class="inline">{{ t('ui.health.version') }}</dt>
              <dd class="text-content ml-1 inline">{{ runtime.version }}</dd>
            </div>
            <div>
              <dt class="inline">{{ t('ui.health.php') }}</dt>
              <dd class="text-content ml-1 inline">{{ runtime.php }}</dd>
            </div>
            <div>
              <dt class="inline">{{ t('ui.health.environment') }}</dt>
              <dd class="text-content ml-1 inline">{{ runtime.environment }}</dd>
            </div>
          </dl>
        </DetailSection>

        <DetailSection
          :title="t('ui.health.maintenance')"
          :description="t('ui.health.maintenance_intro')"
        >
          <template v-if="maintenance.active">
            <p class="text-body">{{ maintenance.message }}</p>
            <p v-if="maintenance.until" class="text-content-muted text-chrome mt-1">
              {{ t('ui.health.ends', { at: formatDateTime(maintenance.until) }) }}
            </p>

            <div v-if="can.manage" class="mt-4">
              <AppButton :loading="form.processing" @click="turnOff">
                {{ t('ui.health.turn_off') }}
              </AppButton>
            </div>
          </template>

          <template v-else-if="editing">
            <form class="flex flex-col gap-3" @submit.prevent="save">
              <AppTextarea
                v-model="form.message"
                :label="t('ui.health.message')"
                :rows="3"
                :error="form.errors.message"
              />
              <AppInput
                v-model="form.until"
                :label="t('ui.health.ends_at')"
                type="datetime-local"
                :hint="t('ui.health.ends_at_hint')"
                :error="form.errors.until"
              />

              <div class="flex gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                  {{ t('ui.health.turn_on') }}
                </AppButton>
                <AppButton variant="ghost" @click="editing = false">
                  {{ t('ui.confirm.cancel') }}
                </AppButton>
              </div>
            </form>
          </template>

          <template v-else>
            <p class="text-content-muted text-body">{{ t('ui.health.off') }}</p>

            <div v-if="can.manage" class="mt-4">
              <AppButton @click="editing = true">{{ t('ui.health.turn_on') }}</AppButton>
            </div>
          </template>
        </DetailSection>
      </div>
    </div>
  </AdminLayout>
</template>
