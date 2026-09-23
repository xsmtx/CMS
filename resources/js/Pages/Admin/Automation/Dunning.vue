<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

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

const form = useForm({ offset_days: '-3', action: 'notify', event: '' })

const needsEvent = computed(
  () => props.actions.find((action) => action.value === form.action)?.needsEvent ?? false,
)

function add(): void {
  form.post('/admin/automation/dunning', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function remove(step: Step): void {
  router.delete(`/admin/automation/dunning/${step.id}`, { preserveScroll: true })
}

function tone(action: string): 'neutral' | 'warning' | 'danger' {
  if (action === 'terminate') return 'danger'
  if (action === 'suspend') return 'warning'

  return 'neutral'
}
</script>

<template>
  <Head title="Unpaid invoices" />

  <AdminLayout
    heading="Unpaid invoices"
    description="What happens, and when, to an invoice nobody has paid."
  >
    <div class="grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <ol v-if="steps.length > 0" class="divide-line border-line divide-y border-y">
          <li
            v-for="step in steps"
            :key="step.id"
            class="flex flex-wrap items-center justify-between gap-3 py-4"
          >
            <div>
              <p class="text-sm font-medium">
                {{ step.when }}
                <AppBadge :tone="tone(step.action)" class="ml-2">{{ step.actionLabel }}</AppBadge>
              </p>
              <p v-if="step.event" class="text-content-muted mt-1 text-xs">{{ step.event }}</p>
            </div>

            <AppButton v-if="can.manage" size="sm" variant="ghost" @click="remove(step)">
              Remove
            </AppButton>
          </li>
        </ol>

        <EmptyState
          v-else
          title="No sequence configured"
          description="Until a step exists, an unpaid invoice is chased by nobody. A sequence with no suspend step is a valid choice too."
        />
      </div>

      <div v-if="can.manage">
        <AppCard title="Add a step">
          <div class="flex flex-col gap-4">
            <AppInput
              v-model="form.offset_days"
              label="Days"
              type="number"
              hint="Negative is before the due date, positive is after."
              :error="form.errors.offset_days"
            />

            <AppSelect
              v-model="form.action"
              label="Action"
              :options="actions"
              :error="form.errors.action"
            />

            <AppSelect
              v-if="needsEvent"
              v-model="form.event"
              label="Message"
              :options="[{ value: '', label: 'Choose a message' }, ...events]"
              :error="form.errors.event"
            />
          </div>

          <div class="mt-5">
            <AppButton size="sm" variant="primary" :loading="form.processing" @click="add">
              Add
            </AppButton>
          </div>
        </AppCard>
      </div>
    </div>
  </AdminLayout>
</template>
