<script setup lang="ts">
/**
 * Every host and the machines on it, and the most consequential button in the
 * product (§10).
 *
 * **Hosts with their machines underneath**, because "what goes down if I
 * reboot hv-3" is the question this screen is opened for. A host that
 * answered about no machines still appears: an empty host is a fact, and
 * leaving it out would read as a host that had gone.
 *
 * **A power action is level 4**: the reason, plus the machine's own name
 * typed out. That is the bar `CompleteCancellation` sets for terminating one
 * service, applied to something that takes down many — and the server asks
 * for the password on top of it.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppConfirm from '../../../Components/AppConfirm.vue'
import AppMenu from '../../../Components/AppMenu.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface MachineRow {
  id: string
  name: string
  kind: string | null
  vcpus: number | null
  state: string
  stateLabel: string
  stateTone: string
  running: boolean
}

interface HostRow {
  id: string
  name: string
  online: boolean | null
  cluster: string | null
  machines: MachineRow[]
}

interface ActionRow {
  value: string
  label: string
  abrupt: boolean
  stopsService: boolean
}

const props = defineProps<{
  hosts: HostRow[]
  unplaced: MachineRow[]
  can: { power: boolean }
  actions: ActionRow[]
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'machine', label: t('infrastructure.virtualisation.columns.machine') },
  { key: 'kind', label: t('infrastructure.virtualisation.columns.kind'), optional: true },
  {
    key: 'vcpus',
    label: t('infrastructure.virtualisation.columns.vcpus'),
    numeric: true,
    optional: true,
  },
  { key: 'state', label: t('infrastructure.virtualisation.columns.state') },
  { key: 'actions', label: '', sticky: true },
]

const acting = ref<{ machine: MachineRow; action: ActionRow } | null>(null)
const form = useForm({ action: '', reason: '', confirm: '' })

/*
 * A title and a sentence per action, keyed by the action itself.
 *
 * Interpolating a button's label into a question makes "web-1 Shut down?" in
 * English and something worse in Turkish, where the word order is different:
 * a label is a button word and a title is a sentence. And the four actions
 * are four different promises — one cuts power and loses work, one asks the
 * guest to stop, one restarts, one starts something that is off and cannot
 * destroy anything.
 */
const title = computed(() =>
  acting.value === null
    ? ''
    : t(`infrastructure.virtualisation.confirm.${acting.value.action.value}`, {
        name: acting.value.machine.name,
      }),
)

const body = computed(() =>
  acting.value === null
    ? ''
    : t(`infrastructure.virtualisation.confirm.${acting.value.action.value}_body`),
)

function offered(machine: MachineRow): ActionRow[] {
  return props.actions.filter((action) =>
    machine.running ? action.value !== 'start' : action.value === 'start',
  )
}

function confirm(reason: string | null): void {
  const current = acting.value

  if (current === null) return

  form.action = current.action.value
  form.reason = reason ?? ''
  form.confirm = current.machine.name
  form.post(`/admin/infrastructure/machines/${current.machine.id}/power`, {
    preserveScroll: true,
    onSuccess: () => {
      acting.value = null
      form.reset()
    },
  })
}
</script>

<template>
  <Head :title="t('infrastructure.virtualisation.title')" />

  <AdminLayout
    :heading="t('ui.nav.machines')"
    :description="t('infrastructure.virtualisation.intro')"
  >
    <EmptyState
      v-if="hosts.length === 0 && unplaced.length === 0"
      icon="servers"
      :title="t('infrastructure.virtualisation.empty')"
      :description="t('infrastructure.virtualisation.empty_detail')"
      boxed
    />

    <div v-else class="flex flex-col gap-8">
      <DetailSection
        v-for="host in hosts"
        :key="host.id"
        :title="host.name"
        :description="host.cluster ?? undefined"
      >
        <template #actions>
          <span class="text-content-muted text-chrome tabular-nums">
            {{ host.machines.filter((machine) => machine.running).length }} /
            {{ host.machines.length }}
            {{ t('infrastructure.virtualisation.running') }}
          </span>
        </template>

        <p v-if="host.machines.length === 0" class="text-content-muted text-body">
          {{ t('infrastructure.virtualisation.no_machines') }}
        </p>

        <AppTable v-else :name="`hv-${host.id}`" :columns="COLUMNS">
          <AppTableRow v-for="machine in host.machines" :key="machine.id">
            <td data-col="machine" class="font-medium">{{ machine.name }}</td>
            <td data-col="kind" class="text-content-muted font-mono">{{ machine.kind }}</td>
            <td data-col="vcpus" class="numeric">{{ machine.vcpus ?? '' }}</td>
            <td data-col="state">
              <AppStatus :tone="asTone(machine.stateTone)" :label="machine.stateLabel" />
            </td>
            <td data-col="actions">
              <div class="row-actions flex justify-end">
                <AppMenu
                  v-if="can.power"
                  v-slot="{ close }"
                  :label="t('infrastructure.virtualisation.power_menu', { name: machine.name })"
                  icon="more"
                  align="end"
                  width="13rem"
                >
                  <button
                    v-for="action in offered(machine)"
                    :key="action.value"
                    type="button"
                    role="menuitem"
                    class="hover:bg-surface-hover text-body block w-full rounded-sm px-2 py-1.5 text-left"
                    :class="action.stopsService ? 'text-danger' : ''"
                    @click="
                      () => {
                        // The slot's own `close`. Without it the menu stays
                        // open behind the dialog's scrim, which reads as two
                        // things having happened.
                        close()
                        acting = { machine, action }
                      }
                    "
                  >
                    {{ action.label }}
                  </button>
                </AppMenu>
              </div>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>

      <!--
        A hypervisor that answers about machines and not about hosts is a real
        configuration. These must not vanish.
      -->
      <DetailSection
        v-if="unplaced.length > 0"
        :title="t('infrastructure.virtualisation.unplaced')"
        :description="t('infrastructure.virtualisation.unplaced_detail')"
      >
        <AppTable name="hv-unplaced" :columns="COLUMNS">
          <AppTableRow v-for="machine in unplaced" :key="machine.id">
            <td data-col="machine" class="font-medium">{{ machine.name }}</td>
            <td data-col="kind" class="text-content-muted font-mono">{{ machine.kind }}</td>
            <td data-col="vcpus" class="numeric">{{ machine.vcpus ?? '' }}</td>
            <td data-col="state">
              <AppStatus :tone="asTone(machine.stateTone)" :label="machine.stateLabel" />
            </td>
            <td data-col="actions"></td>
          </AppTableRow>
        </AppTable>
      </DetailSection>
    </div>

    <AppConfirm
      :open="acting !== null"
      level="destructive"
      :title="title"
      :description="body"
      :phrase="acting?.machine.name"
      :confirm-label="acting?.action.label"
      :busy="form.processing"
      @confirm="confirm"
      @close="acting = null"
    />
  </AdminLayout>
</template>
