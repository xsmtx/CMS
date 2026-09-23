<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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

function tone(state: string): 'success' | 'warning' | 'danger' {
  if (state === 'ok') return 'success'
  if (state === 'degraded') return 'warning'

  return 'danger'
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="System health" />

  <AdminLayout
    heading="System health"
    description="What is working, what is about to stop working, and what has stopped."
  >
    <div class="mb-6 flex flex-wrap items-center gap-3">
      <AppBadge :tone="tone(overall)">
        {{ checks.find((check) => check.state === overall)?.stateLabel ?? overall }}
      </AppBadge>
      <span class="text-content-muted text-xs">
        Checked {{ formatDateTime(runtime.checkedAt) }}
      </span>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <AppCard v-for="check in checks" :key="check.key">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <h2 class="text-sm font-semibold">{{ check.label }}</h2>
          <AppBadge :tone="tone(check.state)">{{ check.stateLabel }}</AppBadge>
        </div>

        <p v-if="check.detail" class="text-content-muted mt-2 text-xs leading-relaxed">
          {{ check.detail }}
        </p>

        <!-- Numbers, never configuration. A health page that proved the
             database was configured by printing its DSN would have
             published a password. -->
        <dl
          v-if="Object.keys(check.measurements).length > 0"
          class="text-content-muted mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs"
        >
          <div v-for="(value, key) in check.measurements" :key="key">
            <dt class="inline">{{ key }}</dt>
            <dd class="text-content ml-1 inline tabular-nums">{{ value }}</dd>
          </div>
        </dl>
      </AppCard>
    </div>

    <div class="mt-8 grid gap-4 md:grid-cols-2">
      <AppCard title="This installation">
        <dl class="text-content-muted flex flex-wrap gap-x-6 gap-y-1 text-xs">
          <div>
            <dt class="inline">Version</dt>
            <dd class="text-content ml-1 inline">{{ runtime.version }}</dd>
          </div>
          <div>
            <dt class="inline">PHP</dt>
            <dd class="text-content ml-1 inline">{{ runtime.php }}</dd>
          </div>
          <div>
            <dt class="inline">Environment</dt>
            <dd class="text-content ml-1 inline">{{ runtime.environment }}</dd>
          </div>
        </dl>
      </AppCard>

      <AppCard
        title="Maintenance mode"
        description="Closes the storefront and the client area. Staff can still sign in."
      >
        <template v-if="maintenance.active">
          <p class="text-sm">{{ maintenance.message }}</p>
          <p v-if="maintenance.until" class="text-content-muted mt-1 text-xs">
            Ends {{ formatDateTime(maintenance.until) }}
          </p>

          <div v-if="can.manage" class="mt-4">
            <AppButton size="sm" :loading="form.processing" @click="turnOff">Turn off</AppButton>
          </div>
        </template>

        <template v-else-if="editing">
          <div class="flex flex-col gap-3">
            <AppTextarea
              v-model="form.message"
              label="Message shown to visitors"
              :rows="3"
              :error="form.errors.message"
            />
            <AppInput
              v-model="form.until"
              label="Ends at"
              type="datetime-local"
              hint="Leave empty to leave it on until you turn it off."
              :error="form.errors.until"
            />
          </div>

          <div class="mt-4 flex gap-2">
            <AppButton variant="primary" size="sm" :loading="form.processing" @click="save">
              Turn on
            </AppButton>
            <AppButton variant="ghost" size="sm" @click="editing = false">Cancel</AppButton>
          </div>
        </template>

        <template v-else>
          <p class="text-content-muted text-xs">Off.</p>

          <div v-if="can.manage" class="mt-4">
            <AppButton size="sm" @click="editing = true">Turn on</AppButton>
          </div>
        </template>
      </AppCard>
    </div>
  </AdminLayout>
</template>
