<script setup lang="ts">
/**
 * Every rack, grouped by room, with how much of it is free (§11).
 *
 * **"Where is there space" is what an operator opens this for**, so the free
 * figure is on the row rather than behind a click. A rack with a name and no
 * capacity beside it is a row that makes somebody open it to find out.
 *
 * Nothing here is discovered. A rack is not an API, and the empty state says
 * so rather than implying a missing adapter.
 */
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'

interface RackRow {
  id: string
  name: string
  units: number
  free: number
  used: number
}

interface RoomRow {
  id: string
  name: string
  racks: RackRow[]
}

interface DatacenterRow {
  id: string
  name: string
  code: string | null
  rooms: RoomRow[]
}

const props = defineProps<{
  datacenters: DatacenterRow[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'rack', label: t('dcim.racks.name') },
  { key: 'used', label: t('dcim.racks.used'), numeric: true },
  { key: 'free', label: t('dcim.rack.free'), numeric: true },
]

const rooms = computed(() =>
  props.datacenters.flatMap((datacenter) =>
    datacenter.rooms.map((room) => ({
      value: room.id,
      label: `${datacenter.name} — ${room.name}`,
    })),
  ),
)

const adding = ref(false)
const form = useForm({ room_id: '', name: '', units: 42 })

function add(): void {
  form.post('/admin/infrastructure/dcim/racks', {
    preserveScroll: true,
    onSuccess: () => {
      adding.value = false
      form.reset()
    },
  })
}
</script>

<template>
  <Head :title="t('dcim.title')" />

  <AdminLayout :heading="t('ui.nav.dcim')" :description="t('dcim.intro')">
    <template v-if="can.manage && rooms.length > 0" #actions>
      <AppButton variant="secondary" @click="adding = !adding">
        {{ t('dcim.racks.add') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection v-if="adding" :title="t('dcim.racks.add')">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="add">
          <AppSelect
            v-model="form.room_id"
            :label="t('dcim.racks.room')"
            :options="rooms"
            :error="form.errors.room_id"
          />
          <AppInput v-model="form.name" :label="t('dcim.racks.name')" :error="form.errors.name" />
          <AppInput
            v-model.number="form.units"
            type="number"
            :label="t('dcim.racks.units')"
            :error="form.errors.units"
          />
          <div>
            <AppButton type="submit" :loading="form.processing">
              {{ t('dcim.racks.save') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="datacenters.length === 0"
        icon="servers"
        :title="t('dcim.empty')"
        :description="t('dcim.empty_detail')"
        boxed
      />

      <template v-for="datacenter in datacenters" v-else :key="datacenter.id">
        <DetailSection
          v-for="room in datacenter.rooms"
          :key="room.id"
          :title="`${datacenter.name} — ${room.name}`"
          :description="datacenter.code ?? undefined"
        >
          <p v-if="room.racks.length === 0" class="text-content-muted text-body">
            {{ t('dcim.no_racks') }}
          </p>

          <AppTable v-else :name="`racks-${room.id}`" :columns="COLUMNS">
            <AppTableRow v-for="rack in room.racks" :key="rack.id">
              <td data-col="rack">
                <!--
                  A `<tr>` cannot be wrapped in an anchor, so the link lives in
                  the identity cell — the convention `ComponentPropsTest` was
                  written to keep after two screens passed `href` to a row.
                -->
                <Link
                  :href="`/admin/infrastructure/dcim/racks/${rack.id}`"
                  class="text-brand font-medium hover:underline"
                >
                  {{ rack.name }}
                </Link>
              </td>
              <td data-col="used" class="numeric">{{ rack.used }} / {{ rack.units }}</td>
              <td data-col="free" class="numeric">{{ rack.free }}</td>
            </AppTableRow>
          </AppTable>
        </DetailSection>
      </template>
    </div>
  </AdminLayout>
</template>
