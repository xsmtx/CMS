<script setup lang="ts">
/**
 * What is waiting on somebody, and a form for asking.
 *
 * The queue an operator opens to answer one question — is anything of mine
 * waiting — so the default is the open states and "everything" is a toggle. A
 * list of four hundred completed changes is a list nobody reads to the bottom.
 *
 * Asking is on this screen rather than behind a second one because a change is
 * a paragraph and a configuration, and a wizard for two fields is a wizard
 * nobody thanks you for. It is hidden until pressed: an operator arrives here
 * to read the queue far more often than to add to it.
 *
 * **One form, two shapes** (§25). A device change is a configuration somebody
 * types; a workspace change is a revision and a plan the tool produces. The
 * target decides which fields are drawn, and it comes from the server rather
 * than from a literal here — the alert rule form drifted from its enum for
 * three phases by deciding that in a template.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

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
import { statusTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface ChangeRow {
  id: string
  summary: string
  status: string
  statusLabel: string
  device: string | null
  deviceKey: string | null
  target: string
  targetLabel: string
  requester: string | null
  decider: string | null
  requiresApproval: boolean
  requestedAt: string | null
  decidedAt: string | null
}

const props = defineProps<{
  changes: {
    data: ChangeRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  devices: { id: string; label: string; key: string }[]
  workspaces: { id: string; label: string; key: string }[]
  targets: { value: string; label: string }[]
  can: { request: boolean; approve: boolean; apply: boolean }
}>()

const { t } = useTranslations()

const locale = computed(() => usePage().props.locale ?? 'en')

const asking = ref(false)

const form = useForm({
  target: 'device',
  device: props.devices[0]?.id ?? '',
  summary: '',
  reason: '',
  ticket: '',
  intended: '',
  ref: '',
})

const isWorkspace = computed(() => form.target === 'workspace')

/** Whichever list the chosen target is about. */
const subjects = computed(() => (isWorkspace.value ? props.workspaces : props.devices))

/**
 * Only the targets this installation can actually act on.
 *
 * A choice with nothing discovered behind it is an empty required select
 * above an enabled button, which this product has found four times.
 */
const available = computed(() =>
  props.targets.filter((target) =>
    target.value === 'workspace' ? props.workspaces.length > 0 : props.devices.length > 0,
  ),
)

const canAsk = computed(() => available.value.length > 0)

/** Re-point the subject when the target changes, or the select keeps an id
 *  from the other list and the server refuses a row of the wrong kind. */
function chooseTarget(value: string): void {
  form.target = value
  form.device = (value === 'workspace' ? props.workspaces : props.devices)[0]?.id ?? ''
}

const columns: TableColumn[] = [
  { key: 'summary', label: t('network.changes.columns.summary') },
  // "Device" no longer names what is in the cell: half of them are
  // workspaces. A column header names what is in a row, which is a rule this
  // product has broken seven times.
  { key: 'device', label: t('network.changes.columns.subject') },
  { key: 'state', label: t('network.changes.columns.state') },
  { key: 'requester', label: t('network.changes.columns.requester') },
  { key: 'requested', label: t('network.changes.columns.requested') },
]

function submit(): void {
  form.post('/admin/network/changes', { preserveScroll: true })
}

function toggleAll(): void {
  router.get(
    '/admin/network/changes',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

/** A date the server sends as an instant, read where the operator is. */
function when(value: string | null): string {
  return value === null ? '' : new Date(value).toLocaleString(locale.value)
}
</script>

<template>
  <Head :title="t('network.changes.title')" />

  <AdminLayout :heading="t('network.changes.title')" :description="t('network.changes.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('network.changes.show_open') : t('network.changes.show_all') }}
      </AppButton>
      <AppButton
        v-if="can.request && canAsk"
        variant="primary"
        icon="add"
        @click="asking = !asking"
      >
        {{ t('network.changes.request') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        Nothing has been discovered yet, so there is nothing to change. Said
        rather than offered: a form whose only select is empty is a form that
        cannot be submitted, which is worse than no form.
      -->
      <EmptyState
        v-if="!canAsk"
        :title="t('network.changes.no_devices')"
        :description="t('network.changes.no_devices_detail')"
        icon="connection"
        boxed
      />

      <DetailSection
        v-if="asking && can.request && canAsk"
        :title="t('network.changes.request')"
        :description="t('network.changes.request_intro')"
      >
        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <div class="grid gap-4 md:grid-cols-2">
            <!--
              Drawn only when there is a choice to make. An installation with
              devices and no workspaces should not be asked which kind of
              thing it means.
            -->
            <AppSelect
              v-if="available.length > 1"
              :model-value="form.target"
              :label="t('network.changes.target')"
              :options="available"
              :error="form.errors.target"
              @update:model-value="chooseTarget(String($event))"
            />
            <AppSelect
              v-model="form.device"
              :label="
                isWorkspace ? t('network.workspace.title') : t('network.changes.columns.device')
              "
              :options="subjects.map((row) => ({ value: row.id, label: row.label }))"
              :error="form.errors.device"
            />
            <AppInput
              v-model="form.ticket"
              :label="t('network.changes.ticket')"
              :hint="t('network.changes.ticket_hint')"
              :error="form.errors.ticket"
            />
          </div>

          <AppInput
            v-model="form.summary"
            :label="t('network.changes.columns.summary')"
            :error="form.errors.summary"
          />

          <AppTextarea
            v-model="form.reason"
            :label="t('network.changes.reason')"
            :hint="t('network.changes.reason_hint')"
            :rows="3"
            :error="form.errors.reason"
          />

          <!--
            A workspace has no configuration to type: core holds no Terraform
            code, and what is asked for is a revision. The plan is produced by
            the tool when this is submitted.
          -->
          <AppInput
            v-if="isWorkspace"
            v-model="form.ref"
            :label="t('network.workspace.ref')"
            :hint="t('network.workspace.ref_hint')"
            mono
            :error="form.errors.ref"
          />

          <AppTextarea
            v-else
            v-model="form.intended"
            :label="t('network.changes.intended')"
            :hint="t('network.changes.intended_hint')"
            :rows="10"
            mono
            :error="form.errors.intended"
          />

          <div>
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('network.changes.request_submit') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="changes.data.length === 0 && devices.length > 0"
        :title="t('network.changes.empty')"
        :description="t('network.changes.empty_detail')"
        icon="connection"
        boxed
      />

      <AppTable v-else-if="changes.data.length > 0" name="network-changes" :columns="columns">
        <AppTableRow v-for="change in changes.data" :key="change.id">
          <!-- The link is in the identity cell: a `<tr>` cannot be wrapped in
               an anchor, and `AppTableRow` has no `href`. -->
          <td data-col="summary">
            <Link
              :href="`/admin/network/changes/${change.id}`"
              class="text-brand font-medium hover:underline"
            >
              {{ change.summary }}
            </Link>
          </td>
          <td data-col="device">
            {{ change.device ?? '' }}
            <span class="text-content-subtle text-chrome block font-mono">
              {{ change.deviceKey }}
            </span>
          </td>
          <td data-col="state">
            <!-- Two fields: the tone from `status`, the word from
                 `statusLabel`. Sending one of them is either untranslated or
                 untoned, which this product has learned four times. -->
            <AppStatus :tone="statusTone(change.status)" :label="change.statusLabel" />
          </td>
          <td data-col="requester" class="text-content-muted">{{ change.requester ?? '' }}</td>
          <td data-col="requested" class="text-content-muted text-chrome tabular-nums">
            {{ when(change.requestedAt) }}
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="changes.links" :total="changes.total" />
    </div>
  </AdminLayout>
</template>
