<script setup lang="ts">
/**
 * The elevation: every unit of one rack (§11).
 *
 * **Top to bottom, as somebody standing in front of the cabinet reads it** —
 * even though the units are numbered from the bottom, which is how they are
 * labelled on the rails. Both facts are on the screen, because an operator
 * about to send a technician needs to be sure which way round it is.
 *
 * **A device occupying four units is drawn once and continued three times**,
 * rather than its name repeated four times: repeating it reads as four
 * machines, which is exactly the mistake a rack diagram exists to stop.
 *
 * **The free units are the recessed ones, not the occupied ones.** Tinting
 * what is there makes a full rack look like a rack of holes, and free space
 * is the absence — it is also what fixed a contrast failure, because the
 * danger-toned Take out button on `surface-secondary` was 4.49:1 at 12px
 * where 4.5 is the floor.
 *
 * Taking something out is level 2 with a sentence, because it changes nothing
 * physical: the danger is the diagram going wrong, not the machine going off.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface DeviceRow {
  id: string
  name: string
  height: number
}

interface UnitRow {
  unit: number
  device: DeviceRow | null
  covered: boolean
}

const props = defineProps<{
  rack: {
    id: string
    name: string
    units: number
    free: number
    room: string | null
    datacenter: string | null
    row: string | null
    note: string | null
  }
  units: UnitRow[]
  servers: { id: string; name: string }[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const removing = ref<DeviceRow | null>(null)

const form = useForm<{
  start_unit: number
  unit_height: number
  server_id: string
  label: string
}>({ start_unit: 1, unit_height: 1, server_id: '', label: '' })

const serverOptions = [
  { value: '', label: t('dcim.rack.no_server') },
  ...props.servers.map((server) => ({ value: server.id, label: server.name })),
]

function place(): void {
  form.post(`/admin/infrastructure/dcim/racks/${props.rack.id}/positions`, {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}

function confirmRemoval(): void {
  const device = removing.value

  if (device === null) return

  router.delete(`/admin/infrastructure/dcim/racks/${props.rack.id}/positions/${device.id}`, {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  })
}

function startPlacing(unit: number): void {
  form.start_unit = unit
  form.clearErrors()
}
</script>

<template>
  <Head :title="rack.name" />

  <AdminLayout :heading="rack.name">
    <template #meta>
      <span class="text-content-muted text-chrome">
        {{ [rack.datacenter, rack.room, rack.row].filter(Boolean).join(' — ') }}
      </span>
    </template>

    <template #actions>
      <AppButton variant="ghost" href="/admin/infrastructure/dcim">
        {{ t('ui.nav.dcim') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection :title="t('dcim.rack.elevation')" :description="t('dcim.rack.elevation_hint')">
        <template #actions>
          <span class="text-content-muted text-chrome tabular-nums">
            {{ t('dcim.free_units', { free: String(rack.free), units: String(rack.units) }) }}
          </span>
        </template>

        <ol class="hover-rows border-line divide-line divide-y rounded-lg border">
          <li
            v-for="row in units"
            :key="row.unit"
            class="flex items-center gap-3 px-3"
            :class="row.device || row.covered ? '' : 'bg-surface-secondary'"
            :style="{ minHeight: 'var(--control-h)' }"
          >
            <span class="text-content-subtle text-label w-8 shrink-0 text-right tabular-nums">
              {{ row.unit }}
            </span>

            <template v-if="row.device">
              <!--
                Not a link. There is no per-server screen in this product —
                servers are managed on Apps → Infrastructure — and a path a
                page names is a promise, which `AdminActionRoutesTest` checks.
                It caught this one.
              -->
              <span class="text-body font-medium">{{ row.device.name }}</span>

              <span v-if="row.device.height > 1" class="text-content-subtle text-chrome">
                {{ row.device.height }}U
              </span>

              <div class="row-actions ml-auto">
                <AppButton
                  v-if="can.manage"
                  size="sm"
                  variant="danger-subtle"
                  @click="removing = row.device"
                >
                  {{ t('dcim.rack.remove') }}
                </AppButton>
              </div>
            </template>

            <!--
              The same device continuing upward. Repeating its name would read
              as four machines, which is the mistake a rack diagram exists to
              stop.
            -->
            <span v-else-if="row.covered" class="text-content-subtle text-chrome">
              {{ t('dcim.rack.continues') }}
            </span>

            <template v-else>
              <span class="text-content-subtle text-chrome">{{ t('dcim.rack.free') }}</span>
              <button
                v-if="can.manage"
                type="button"
                class="row-actions text-brand text-chrome ml-auto hover:underline"
                @click="startPlacing(row.unit)"
              >
                {{ t('dcim.rack.add') }}
              </button>
            </template>
          </li>
        </ol>
      </DetailSection>

      <DetailSection v-if="can.manage" :title="t('dcim.rack.add')">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="place">
          <AppInput
            v-model.number="form.start_unit"
            type="number"
            :label="t('dcim.rack.start_unit')"
            :error="form.errors.start_unit"
          />
          <AppInput
            v-model.number="form.unit_height"
            type="number"
            :label="t('dcim.rack.unit_height')"
            :error="form.errors.unit_height"
          />
          <AppSelect
            v-model="form.server_id"
            :label="t('dcim.rack.server')"
            :options="serverOptions"
            :error="form.errors.server_id"
          />
          <AppInput
            v-model="form.label"
            :label="t('dcim.rack.label')"
            :hint="t('dcim.rack.label_hint')"
            :error="form.errors.label"
          />
          <div>
            <AppButton type="submit" :loading="form.processing">
              {{ t('dcim.rack.save') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="consequential"
      :title="t('dcim.rack.remove_title', { name: removing?.name ?? '', rack: rack.name })"
      :description="t('dcim.rack.remove_body')"
      :confirm-label="t('dcim.rack.remove')"
      @confirm="confirmRemoval"
      @close="removing = null"
    />
  </AdminLayout>
</template>
