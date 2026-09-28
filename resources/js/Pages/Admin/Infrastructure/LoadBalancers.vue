<script setup lang="ts">
/**
 * What every load balancer is serving, and the one thing an operator may
 * change about it (§9, §16).
 *
 * **Listeners with their backends underneath**, because the question this
 * screen is opened for is "can I take web-3 out", and that is answered by
 * seeing what else is behind the same listener. The count of backends still
 * taking traffic sits beside each listener's name for exactly that reason: a
 * flat list would hide the one fact that decides the answer.
 *
 * **Draining is level 3, with a reason.** It is reversible, so it is not the
 * four-level dialog a termination gets — but it changes what the world
 * reaches, so it asks why, and the sentence says what actually happens:
 * nothing already open is cut. The server asks for the password again on top.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface BackendRow {
  id: string
  name: string
  address: string | null
  port: number | null
  weight: number | null
  state: string
  stateLabel: string
  stateTone: string
  serving: boolean
}

interface ListenerRow {
  id: string
  name: string
  address: string | null
  port: number | null
  protocol: string | null
  backends: BackendRow[]
}

defineProps<{
  listeners: ListenerRow[]
  can: { drain: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'backend', label: t('infrastructure.loadbalancing.columns.backend') },
  { key: 'address', label: t('infrastructure.loadbalancing.columns.address') },
  {
    key: 'weight',
    label: t('infrastructure.loadbalancing.columns.weight'),
    numeric: true,
    optional: true,
  },
  { key: 'state', label: t('infrastructure.loadbalancing.columns.state') },
  { key: 'actions', label: '', sticky: true },
]

const acting = ref<{ backend: BackendRow; draining: boolean } | null>(null)
const form = useForm({ reason: '' })

const title = computed(() =>
  acting.value === null
    ? ''
    : t(
        acting.value.draining
          ? 'infrastructure.loadbalancing.confirm.drain_title'
          : 'infrastructure.loadbalancing.confirm.undrain_title',
        { name: acting.value.backend.name },
      ),
)

const body = computed(() =>
  acting.value === null
    ? ''
    : t(
        acting.value.draining
          ? 'infrastructure.loadbalancing.confirm.drain_body'
          : 'infrastructure.loadbalancing.confirm.undrain_body',
      ),
)

function serving(listener: ListenerRow): number {
  return listener.backends.filter((backend) => backend.serving).length
}

function endpoint(listener: ListenerRow): string {
  const address = listener.address ?? ''
  const port = listener.port === null ? '' : `:${listener.port}`

  return `${listener.protocol ? `${listener.protocol} ` : ''}${address}${port}`.trim()
}

function confirm(reason: string | null): void {
  const current = acting.value

  if (current === null) return

  const verb = current.draining ? 'drain' : 'undrain'

  form.reason = reason ?? ''
  form.post(`/admin/infrastructure/load-balancers/${current.backend.id}/${verb}`, {
    preserveScroll: true,
    onSuccess: () => {
      acting.value = null
      form.reset()
    },
  })
}
</script>

<template>
  <Head :title="t('infrastructure.loadbalancing.title')" />

  <AdminLayout
    :heading="t('ui.nav.load_balancers')"
    :description="t('infrastructure.loadbalancing.intro')"
  >
    <EmptyState
      v-if="listeners.length === 0"
      icon="servers"
      :title="t('infrastructure.loadbalancing.empty')"
      :description="t('infrastructure.loadbalancing.empty_detail')"
      boxed
    />

    <div v-else class="flex flex-col gap-8">
      <DetailSection
        v-for="listener in listeners"
        :key="listener.id"
        :title="listener.name"
        :description="endpoint(listener)"
      >
        <template #actions>
          <!--
            How many are left. The one fact that decides whether a backend can
            be taken out, so it sits beside the listener's name rather than
            being counted by eye.
          -->
          <span class="text-content-muted text-chrome tabular-nums">
            {{ serving(listener) }} / {{ listener.backends.length }}
            {{ t('infrastructure.loadbalancing.serving') }}
          </span>
        </template>

        <p v-if="listener.backends.length === 0" class="text-content-muted text-body">
          {{ t('infrastructure.loadbalancing.no_backends') }}
        </p>

        <AppTable v-else :name="`lb-${listener.id}`" :columns="COLUMNS">
          <AppTableRow v-for="backend in listener.backends" :key="backend.id">
            <td data-col="backend" class="font-medium">{{ backend.name }}</td>
            <td data-col="address" class="text-content-muted font-mono">
              {{ backend.address }}<template v-if="backend.port">:{{ backend.port }}</template>
            </td>
            <td data-col="weight" class="numeric">{{ backend.weight ?? '' }}</td>
            <td data-col="state">
              <AppStatus :tone="asTone(backend.stateTone)" :label="backend.stateLabel" />
            </td>
            <td data-col="actions">
              <div class="row-actions flex justify-end">
                <AppButton
                  v-if="can.drain"
                  size="sm"
                  :variant="backend.serving ? 'danger-subtle' : 'secondary'"
                  @click="acting = { backend, draining: backend.serving }"
                >
                  {{
                    backend.serving
                      ? t('infrastructure.loadbalancing.drain')
                      : t('infrastructure.loadbalancing.undrain')
                  }}
                </AppButton>
              </div>
            </td>
          </AppTableRow>
        </AppTable>
      </DetailSection>
    </div>

    <AppConfirm
      :open="acting !== null"
      level="high-risk"
      :title="title"
      :description="body"
      :confirm-label="
        acting?.draining
          ? t('infrastructure.loadbalancing.drain')
          : t('infrastructure.loadbalancing.undrain')
      "
      :busy="form.processing"
      @confirm="confirm"
      @close="acting = null"
    />
  </AdminLayout>
</template>
