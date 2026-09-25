<script setup lang="ts">
/**
 * What happens, and when, to an invoice nobody has paid.
 *
 * Rows an operator edits rather than constants: a sequence with no suspend
 * step is a valid configuration, and so is no sequence at all — until a
 * step exists, an unpaid invoice is chased by nobody, which the empty state
 * says out loud rather than implying.
 *
 * Removing a step asks first. It changes what the platform will do to
 * somebody's service next week, and nothing on the screen would otherwise
 * show that it used to do something else.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface Step {
  id: string
  offsetDays: number
  action: string
  actionLabel: string
  event: string | null
  isActive: boolean
  when: string
}

const props = defineProps<{
  steps: Step[]
  actions: { value: string; label: string; needsEvent: boolean }[]
  events: { value: string; label: string }[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const form = useForm({ offset_days: '-3', action: 'notify', event: '' })

const removing = ref<Step | null>(null)

const needsEvent = computed(
  () => props.actions.find((action) => action.value === form.action)?.needsEvent ?? false,
)

function add(): void {
  form.post('/admin/automation/dunning', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function remove(): void {
  const step = removing.value

  if (step === null) return

  router.delete(`/admin/automation/dunning/${step.id}`, {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  })
}

/**
 * The weight of what the step does, not a status: suspending somebody is
 * not a warning about the step, it is what the step is.
 */
function tone(action: string): 'neutral' | 'warning' | 'danger' {
  if (action === 'terminate') return 'danger'
  if (action === 'suspend') return 'warning'

  return 'neutral'
}
</script>

<template>
  <Head :title="t('automation.dunning.title')" />

  <AdminLayout
    :heading="t('automation.dunning.title')"
    :description="t('automation.dunning.description')"
  >
    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
      <div>
        <ol v-if="steps.length > 0" class="divide-line border-line divide-y border-y">
          <li
            v-for="step in steps"
            :key="step.id"
            class="flex flex-wrap items-center justify-between gap-3 py-3"
          >
            <div class="min-w-0">
              <p class="text-body flex flex-wrap items-center gap-2 font-medium">
                {{ step.when }}
                <AppBadge :tone="tone(step.action)">{{ step.actionLabel }}</AppBadge>
              </p>
              <p v-if="step.event" class="text-content-muted text-chrome mt-0.5">
                {{ step.event }}
              </p>
            </div>

            <AppButton v-if="can.manage" size="sm" variant="danger-subtle" @click="removing = step">
              {{ t('automation.dunning.remove') }}
            </AppButton>
          </li>
        </ol>

        <EmptyState
          v-else
          icon="automation"
          :title="t('automation.dunning.none')"
          :description="t('automation.dunning.none_description')"
        />
      </div>

      <DetailSection v-if="can.manage" :title="t('automation.dunning.add')">
        <div class="flex flex-col gap-4">
          <AppInput
            v-model="form.offset_days"
            :label="t('automation.dunning.offset')"
            type="number"
            inputmode="numeric"
            :hint="t('automation.dunning.offset_hint')"
            :error="form.errors.offset_days"
          />

          <AppSelect
            v-model="form.action"
            :label="t('automation.dunning.action')"
            :options="actions"
            :error="form.errors.action"
          />

          <AppSelect
            v-if="needsEvent"
            v-model="form.event"
            :label="t('automation.dunning.event')"
            :options="[{ value: '', label: t('automation.dunning.choose_event') }, ...events]"
            :error="form.errors.event"
          />

          <div>
            <AppButton variant="primary" :loading="form.processing" @click="add">
              {{ t('automation.dunning.add_step') }}
            </AppButton>
          </div>
        </div>
      </DetailSection>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('automation.dunning.remove_title')"
      :description="
        t('automation.dunning.remove_detail', {
          step: removing === null ? '' : `${removing.when} — ${removing.actionLabel}`,
        })
      "
      :confirm-label="t('automation.dunning.remove')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
