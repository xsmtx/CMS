<script setup lang="ts">
/**
 * Asking somebody at the datacenter to go and touch a machine (§11).
 *
 * **Open first, oldest first.** This queue is read by whoever is chasing the
 * datacenter, and the thing they need is the one that has been waiting
 * longest — not the one raised most recently.
 *
 * **The instructions are on the row.** A remote-hands task whose whole point
 * is "bay 4, amber light, swap it" is unreadable if the sentence is behind a
 * click, and there are never four hundred of these.
 *
 * Closing one asks for both serials and for what happened, because those
 * three fields are the register for the next warranty claim — and a task
 * closed with nothing written is a visit nobody can read afterwards.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface NextState {
  value: string
  label: string
}

interface TaskRow {
  id: string
  summary: string
  instructions: string
  state: string
  stateLabel: string
  stateTone: string
  next: NextState[]
  rack: string | null
  server: string | null
  part: string | null
  requestedBy: string | null
  requestedAt: string
  scheduledFor: string | null
  completedAt: string | null
  technician: string | null
  oldSerial: string | null
  newSerial: string | null
  outcome: string | null
  evidence: string | null
}

const props = defineProps<{
  tasks: {
    data: TaskRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  racks: { id: string; name: string }[]
  servers: { id: string; name: string }[]
  parts: { id: string; name: string }[]
  can: { request: boolean; complete: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'task', label: t('dcim.remote_hands.columns.task') },
  { key: 'where', label: t('dcim.remote_hands.columns.where') },
  { key: 'state', label: t('dcim.remote_hands.columns.state') },
  { key: 'waiting', label: t('dcim.remote_hands.columns.waiting') },
  { key: 'actions', label: '' },
]

const adding = ref(false)
const moving = ref<{ task: TaskRow; to: NextState } | null>(null)

const form = useForm({
  summary: '',
  instructions: '',
  rack_id: '',
  server_id: '',
  hardware_part_id: '',
})

const move = useForm({
  state: '',
  technician: '',
  scheduled_for: '',
  old_serial: '',
  new_serial: '',
  outcome: '',
  evidence: '',
})

const none = { value: '', label: t('dcim.remote_hands.fields.none') }

function add(): void {
  form.post('/admin/infrastructure/remote-hands', {
    preserveScroll: true,
    onSuccess: () => {
      adding.value = false
      form.reset()
    },
  })
}

function submitMove(): void {
  const current = moving.value

  if (current === null) return

  move.state = current.to.value
  move.post(`/admin/infrastructure/remote-hands/${current.task.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      moving.value = null
      move.reset()
    },
  })
}

function toggleAll(): void {
  router.get(
    '/admin/infrastructure/remote-hands',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function day(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('dcim.remote_hands.title')" />

  <AdminLayout :heading="t('ui.nav.remote_hands')" :description="t('dcim.remote_hands.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('dcim.remote_hands.show_open') : t('dcim.remote_hands.show_all') }}
      </AppButton>
      <AppButton v-if="can.request" variant="secondary" @click="adding = !adding">
        {{ t('dcim.remote_hands.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection v-if="adding" :title="t('dcim.remote_hands.add')">
        <form class="flex max-w-3xl flex-col gap-4" @submit.prevent="add">
          <AppInput
            v-model="form.summary"
            :label="t('dcim.remote_hands.fields.summary')"
            :error="form.errors.summary"
          />
          <AppTextarea
            v-model="form.instructions"
            :label="t('dcim.remote_hands.fields.instructions')"
            :hint="t('dcim.remote_hands.fields.instructions_hint')"
            :error="form.errors.instructions"
          />
          <div class="flex flex-wrap items-end gap-3">
            <AppSelect
              v-model="form.rack_id"
              :label="t('dcim.remote_hands.fields.rack')"
              :options="[none, ...racks.map((r) => ({ value: r.id, label: r.name }))]"
            />
            <AppSelect
              v-model="form.server_id"
              :label="t('dcim.remote_hands.fields.server')"
              :options="[none, ...servers.map((s) => ({ value: s.id, label: s.name }))]"
            />
            <AppSelect
              v-model="form.hardware_part_id"
              :label="t('dcim.remote_hands.fields.part')"
              :options="[none, ...parts.map((p) => ({ value: p.id, label: p.name }))]"
            />
            <div>
              <AppButton type="submit" variant="primary" :loading="form.processing">
                {{ t('dcim.remote_hands.fields.save') }}
              </AppButton>
            </div>
          </div>
        </form>
      </DetailSection>

      <DetailSection v-if="moving" :title="`${moving.task.summary} — ${moving.to.label}`">
        <form class="flex max-w-3xl flex-col gap-4" @submit.prevent="submitMove">
          <!--
            `items-start`, not `items-end`: the technician carries a hint and
            the serials do not, so aligning the bottoms of the wrappers put
            three controls in one row at three different heights.
          -->
          <div class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-model="move.technician"
              :label="t('dcim.remote_hands.fields.technician')"
              :hint="t('dcim.remote_hands.fields.technician_hint')"
              :error="move.errors.technician"
            />
            <AppInput
              v-if="moving.to.value === 'scheduled'"
              v-model="move.scheduled_for"
              type="datetime-local"
              :label="t('dcim.remote_hands.fields.scheduled_for')"
              :error="move.errors.scheduled_for"
            />
          </div>

          <!--
            Both serials, side by side, and only when closing: they are one
            fact — the register for the next warranty claim — and asking for
            them earlier would be asking for something nobody can know yet.
          -->
          <div v-if="moving.to.value === 'done'" class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-model="move.old_serial"
              :label="t('dcim.remote_hands.fields.old_serial')"
              :error="move.errors.old_serial"
            />
            <AppInput
              v-model="move.new_serial"
              :label="t('dcim.remote_hands.fields.new_serial')"
              :error="move.errors.new_serial"
            />
          </div>

          <AppTextarea
            v-if="moving.to.value === 'done'"
            v-model="move.outcome"
            :label="t('dcim.remote_hands.fields.outcome')"
            :error="move.errors.outcome ?? move.errors.state"
          />

          <AppInput
            v-if="moving.to.value === 'done'"
            v-model="move.evidence"
            :label="t('dcim.remote_hands.fields.evidence')"
            :hint="t('dcim.remote_hands.fields.evidence_hint')"
            :error="move.errors.evidence"
          />

          <div class="flex gap-3">
            <AppButton type="submit" variant="primary" :loading="move.processing">
              {{ moving.to.label }}
            </AppButton>
            <AppButton variant="ghost" @click="moving = null">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="tasks.data.length === 0"
        icon="ok"
        :title="t('dcim.remote_hands.empty')"
        :description="t('dcim.remote_hands.empty_detail')"
      />

      <AppTable v-else name="remote-hands" :columns="COLUMNS">
        <AppTableRow v-for="task in tasks.data" :key="task.id">
          <td data-col="task">
            <span class="font-medium">{{ task.summary }}</span>
            <!--
              On the row. A task whose whole point is "bay 4, amber light,
              swap it" is unreadable if the sentence is behind a click.
            -->
            <span class="text-content-muted text-chrome block max-w-[70ch]">
              {{ task.instructions }}
            </span>
            <span
              v-if="task.outcome"
              class="text-content-subtle text-chrome mt-1 block max-w-[70ch]"
            >
              {{ task.outcome }}
            </span>
            <span
              v-if="task.oldSerial && task.newSerial"
              class="text-content-subtle text-chrome block font-mono"
            >
              {{
                t('dcim.remote_hands.serials', {
                  old: task.oldSerial,
                  new: task.newSerial,
                })
              }}
            </span>
          </td>
          <td data-col="where">
            <span v-if="task.rack || task.server">
              {{ [task.rack, task.server].filter(Boolean).join(' — ') }}
            </span>
            <span v-else class="text-content-subtle">
              {{ t('dcim.remote_hands.unplaced') }}
            </span>
            <span v-if="task.part" class="text-content-muted text-chrome block font-mono">
              {{ task.part }}
            </span>
          </td>
          <td data-col="state">
            <AppStatus :tone="asTone(task.stateTone)" :label="task.stateLabel" />
            <span v-if="task.technician" class="text-content-subtle text-chrome block">
              {{ task.technician }}
            </span>
          </td>
          <td data-col="waiting" class="text-content-muted text-chrome tabular-nums">
            {{ day(task.requestedAt) }}
            <span v-if="task.scheduledFor" class="text-content-subtle block">
              {{ t('dcim.remote_hands.scheduled_for', { date: day(task.scheduledFor) }) }}
            </span>
          </td>
          <td data-col="actions" class="text-right whitespace-nowrap">
            <span class="row-actions inline-flex gap-1">
              <AppButton
                v-for="to in task.next"
                :key="to.value"
                size="sm"
                :variant="to.value === 'cancelled' ? 'danger-subtle' : 'ghost'"
                @click="moving = { task, to }"
              >
                {{ to.label }}
              </AppButton>
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="tasks.links" :total="tasks.total" />
    </div>
  </AdminLayout>
</template>
