<script setup lang="ts">
/**
 * Planned work (§16).
 *
 * **What a window buys is that nobody is woken by the work they themselves
 * scheduled.** The alerts it causes are still raised, still counted and still
 * on the alerts screen carrying the window that held them — which is why this
 * page shows how many each one held rather than pretending nothing happened.
 *
 * There is nothing here that moves a window along, because a window has no
 * stored state: it starts when the clock passes its start. The only human act
 * after scheduling is calling it off, and even that leaves the record saying
 * it was planned — "nobody warned us" and "we warned you and then called it
 * off" are different conversations.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
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

interface Window {
  id: string
  title: string
  body: string | null
  state: string
  stateLabel: string
  stateTone: string
  startsAt: string
  endsAt: string
  isPublic: boolean
  nodeKeys: string[]
  author: string | null
  suppressed: number
  canCancel: boolean
}

defineProps<{
  windows: Window[]
  can: { manage: boolean }
}>()

const { t } = useTranslations()

const planning = ref(false)
const cancelling = ref<Window | null>(null)

const form = useForm({
  title: '',
  body: '',
  starts_at: '',
  ends_at: '',
  node_keys: '',
  is_public: false,
})

const COLUMNS: TableColumn[] = [
  { key: 'window', label: t('reliability.maintenance.columns.window') },
  { key: 'state', label: t('reliability.maintenance.columns.state') },
  { key: 'when', label: t('reliability.maintenance.columns.when') },
  { key: 'scope', label: t('reliability.maintenance.columns.scope') },
  { key: 'held', label: t('reliability.maintenance.columns.held'), numeric: true },
  { key: 'actions', label: '' },
]

function submit(): void {
  form.post('/admin/reliability/maintenance', {
    onSuccess: () => {
      form.reset()
      planning.value = false
    },
  })
}

function cancel(): void {
  const window = cancelling.value

  if (window === null) return

  router.delete(`/admin/reliability/maintenance/${window.id}`, {
    preserveScroll: true,
    onFinish: () => (cancelling.value = null),
  })
}

/**
 * The window, as one phrase.
 *
 * `toLocaleString()` on both ends gave "30.09.2026 03:01:05 — 30.09.2026
 * 07:01:05": the date twice, and seconds on work somebody planned for next
 * Tuesday. A window inside one day prints its date once, and no window has
 * ever been scheduled to the second.
 */
function span(from: string, to: string): string {
  const start = new Date(from)
  const end = new Date(to)

  const date = (at: Date): string => at.toLocaleDateString()
  const time = (at: Date): string =>
    at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

  return date(start) === date(end)
    ? `${date(start)} ${time(start)} — ${time(end)}`
    : `${date(start)} ${time(start)} — ${date(end)} ${time(end)}`
}

/**
 * How many machines, or the word for all of them. An empty list is the whole
 * installation, and printing "0" there would say the opposite of what it
 * means.
 */
function scope(row: Window): string {
  return row.nodeKeys.length === 0
    ? t('reliability.maintenance.everywhere')
    : String(row.nodeKeys.length)
}
</script>

<template>
  <Head :title="t('reliability.maintenance.title')" />

  <AdminLayout :heading="t('ui.nav.maintenance')" :description="t('reliability.maintenance.intro')">
    <template #actions>
      <AppButton v-if="can.manage" variant="primary" icon="add" @click="planning = !planning">
        {{ t('reliability.maintenance.plan') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="planning && can.manage"
        :title="t('reliability.maintenance.plan')"
        :description="t('reliability.maintenance.plan_intro')"
      >
        <form class="flex max-w-[80ch] flex-col gap-4" @submit.prevent="submit">
          <AppInput
            v-model="form.title"
            :label="t('reliability.maintenance.window_title')"
            :error="form.errors.title"
          />

          <div class="grid gap-4 md:grid-cols-2">
            <AppInput
              v-model="form.starts_at"
              type="datetime-local"
              :label="t('reliability.maintenance.starts_at')"
              :error="form.errors.starts_at"
            />
            <AppInput
              v-model="form.ends_at"
              type="datetime-local"
              :label="t('reliability.maintenance.ends_at')"
              :error="form.errors.ends_at"
            />
          </div>

          <AppTextarea
            v-model="form.node_keys"
            mono
            :label="t('reliability.maintenance.nodes')"
            :hint="t('reliability.maintenance.nodes_hint')"
            :rows="3"
            :error="form.errors.node_keys"
          />

          <AppTextarea
            v-model="form.body"
            :label="t('reliability.maintenance.body')"
            :hint="t('reliability.maintenance.body_hint')"
            :rows="3"
            :error="form.errors.body"
          />

          <AppCheckbox
            v-model="form.is_public"
            :label="t('reliability.maintenance.is_public')"
            :description="t('reliability.maintenance.is_public_hint')"
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('reliability.maintenance.plan_it') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="windows.length === 0"
        icon="history"
        :title="t('reliability.maintenance.empty')"
        :description="t('reliability.maintenance.empty_detail')"
        boxed
      />

      <AppTable v-else name="maintenance-windows" :columns="COLUMNS">
        <AppTableRow v-for="row in windows" :key="row.id">
          <td data-col="window">
            <span class="font-medium">{{ row.title }}</span>
            <AppBadge v-if="row.isPublic" tone="brand" class="ml-2">
              {{ t('reliability.incidents.published') }}
            </AppBadge>
            <span v-if="row.author" class="text-content-subtle text-chrome block">
              {{ row.author }}
            </span>
          </td>
          <td data-col="state">
            <AppStatus :tone="asTone(row.stateTone)" :label="row.stateLabel" />
          </td>
          <td data-col="when" class="text-content-muted text-chrome tabular-nums">
            {{ span(row.startsAt, row.endsAt) }}
          </td>
          <td data-col="scope" class="text-content-muted">{{ scope(row) }}</td>
          <!--
            How many alerts it actually held. The point of showing it: a
            window that suppressed nothing either covered the wrong machines
            or the work went better than expected, and both are worth knowing.
          -->
          <td data-col="held" class="numeric tabular-nums">{{ row.suppressed }}</td>
          <td data-col="actions" class="text-right whitespace-nowrap">
            <AppButton
              v-if="can.manage && row.canCancel"
              size="sm"
              variant="danger-subtle"
              @click="cancelling = row"
            >
              {{ t('reliability.maintenance.cancel') }}
            </AppButton>
          </td>
        </AppTableRow>
      </AppTable>
    </div>

    <AppConfirm
      :open="cancelling !== null"
      level="consequential"
      :title="t('reliability.maintenance.cancel_title')"
      :description="t('reliability.maintenance.cancel_body')"
      :confirm-label="t('reliability.maintenance.cancel')"
      @close="cancelling = null"
      @confirm="cancel"
    />
  </AdminLayout>
</template>
