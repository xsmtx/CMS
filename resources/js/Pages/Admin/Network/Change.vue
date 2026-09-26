<script setup lang="ts">
/**
 * One change, the diff somebody is agreeing to, and the three buttons.
 *
 * The diff is the screen. Everything else — who asked, why, which ticket — is
 * the frame around it, because the question an approver is answering is "do I
 * agree with these lines" and a page that made them hunt for the lines would be
 * a page that got approvals by exhaustion.
 *
 * **Apply is level 4 and asks for the device's own name.** It is the most
 * consequential thing this platform can do and the second most destructive
 * action in the product after terminating a service. The dialog says what
 * actually happens: a backup is taken first, and the change is refused if the
 * box has moved since the diff was read.
 *
 * Approve and reject are level 2: they change a record, not a firewall.
 */
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DescriptionList from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { statusTone } from '../../../status'

interface Change {
  id: string
  summary: string
  status: string
  statusLabel: string
  device: string | null
  deviceKey: string | null
  requester: string | null
  decider: string | null
  requiresApproval: boolean
  requestedAt: string | null
  decidedAt: string | null
  reason: string
  ticket: string | null
  decisionNote: string | null
  diff: string | null
  result: string | null
  backedUpAt: string | null
  appliedAt: string | null
  operationId: string | null
}

const props = defineProps<{
  change: Change
  can: { decide: boolean; apply: boolean; cancel: boolean; isRequester: boolean }
}>()

const { t } = useTranslations()

const decision = useForm({ decision: 'approve', note: '' })
const applying = useForm({ note: '' })

const confirming = ref<'approve' | 'reject' | 'cancel' | 'apply' | null>(null)

const facts = computed(() => [
  { key: 'device', label: t('network.changes.columns.device'), value: props.change.device ?? '—' },
  {
    key: 'requester',
    label: t('network.changes.columns.requester'),
    value: props.change.requester ?? '—',
  },
  { key: 'ticket', label: t('network.changes.ticket'), value: props.change.ticket ?? '—' },
  {
    key: 'requested',
    label: t('network.changes.columns.requested'),
    value: when(props.change.requestedAt),
  },
  {
    key: 'decided',
    label: t('network.changes.decided'),
    value: props.change.decider ? `${props.change.decider} · ${when(props.change.decidedAt)}` : '—',
  },
])

/** The diff, split so each line can carry its own colour. */
const lines = computed(() => (props.change.diff ?? '').split('\n'))

function toneFor(line: string): string {
  if (line.startsWith('+')) return 'text-success'
  if (line.startsWith('-')) return 'text-danger'
  // A comment is what the diff writes when the configuration was too large to
  // show line by line.
  if (line.startsWith('#')) return 'text-content-muted'

  return 'text-content-subtle'
}

function decide(verb: 'approve' | 'reject' | 'cancel'): void {
  decision.decision = verb
  decision.post(`/admin/network/changes/${props.change.id}/decide`, {
    preserveScroll: true,
    onFinish: () => (confirming.value = null),
  })
}

/**
 * The reason the dialog required is sent, not discarded.
 *
 * A reason a screen collects and an endpoint throws away is a sentence
 * nobody reads — the rule the cancellation queue taught. It lands on the
 * audit row beside the operator's name.
 */
function apply(reason: string | null): void {
  applying.note = reason ?? ''
  applying.post(`/admin/network/changes/${props.change.id}/apply`, {
    preserveScroll: true,
    onFinish: () => (confirming.value = null),
  })
}

function when(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}
</script>

<template>
  <Head :title="change.summary" />

  <AdminLayout :heading="change.summary">
    <template #status>
      <AppStatus :tone="statusTone(change.status)" :label="change.statusLabel" />
    </template>

    <template #actions>
      <AppButton v-if="can.decide" variant="secondary" @click="confirming = 'reject'">
        {{ t('network.changes.reject') }}
      </AppButton>
      <AppButton v-if="can.decide" variant="primary" @click="confirming = 'approve'">
        {{ t('network.changes.approve') }}
      </AppButton>
      <AppButton v-if="can.apply" variant="primary" icon="send" @click="confirming = 'apply'">
        {{ t('network.changes.apply') }}
      </AppButton>
      <AppButton v-if="can.cancel" variant="danger-subtle" @click="confirming = 'cancel'">
        {{ t('network.changes.cancel') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="decision.errors.decision" tone="danger">
        {{ decision.errors.decision }}
      </AppAlert>

      <AppAlert v-if="change.result" :tone="change.status === 'completed' ? 'success' : 'warning'">
        {{ change.result }}
      </AppAlert>

      <DetailSection :title="t('network.changes.about')">
        <DescriptionList :items="facts">
          <template #device>
            <span>{{ change.device ?? '—' }}</span>
            <span class="text-content-subtle block font-mono">{{ change.deviceKey }}</span>
          </template>
        </DescriptionList>

        <p class="text-body mt-4 max-w-[80ch]">{{ change.reason }}</p>

        <p v-if="change.decisionNote" class="text-content-muted text-chrome mt-2 max-w-[80ch]">
          {{ change.decisionNote }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <AppBadge :tone="change.requiresApproval ? 'brand' : 'neutral'">
            {{
              change.requiresApproval
                ? t('network.changes.approval_required')
                : t('network.changes.approval_not_required')
            }}
          </AppBadge>
          <AppBadge v-if="change.backedUpAt" tone="neutral">
            {{ t('network.changes.backed_up', { at: when(change.backedUpAt) }) }}
          </AppBadge>
        </div>
      </DetailSection>

      <DetailSection
        :title="t('network.changes.diff')"
        :description="t('network.changes.diff_intro')"
      >
        <pre
          v-if="change.diff"
          class="border-line bg-surface-secondary text-chrome max-h-[36rem] overflow-auto rounded-lg border p-4 font-mono"
        ><code><span v-for="(line, index) in lines" :key="index" class="block" :class="toneFor(line)">{{ line || ' ' }}</span></code></pre>

        <p v-else class="text-content-muted text-body">{{ t('network.changes.no_diff') }}</p>
      </DetailSection>
    </div>

    <AppConfirm
      :open="confirming === 'approve'"
      level="consequential"
      :title="t('network.changes.approve')"
      :description="t('network.changes.approve_body')"
      :confirm-label="t('network.changes.approve')"
      :busy="decision.processing"
      @close="confirming = null"
      @confirm="decide('approve')"
    >
      <AppTextarea v-model="decision.note" :label="t('network.changes.note')" :rows="3" />
    </AppConfirm>

    <AppConfirm
      :open="confirming === 'reject'"
      level="consequential"
      :title="t('network.changes.reject')"
      :description="t('network.changes.reject_body')"
      :confirm-label="t('network.changes.reject')"
      :busy="decision.processing"
      @close="confirming = null"
      @confirm="decide('reject')"
    >
      <AppTextarea v-model="decision.note" :label="t('network.changes.note')" :rows="3" />
    </AppConfirm>

    <AppConfirm
      :open="confirming === 'cancel'"
      level="consequential"
      :title="t('network.changes.cancel')"
      :description="t('network.changes.cancel_body')"
      :confirm-label="t('network.changes.cancel')"
      :busy="decision.processing"
      @close="confirming = null"
      @confirm="decide('cancel')"
    />

    <!--
      Level four, and the phrase is the device's own name. The most
      consequential thing this platform can do, and the only one where typing
      the name of the box is proportionate.
    -->
    <AppConfirm
      :open="confirming === 'apply'"
      level="destructive"
      :title="t('network.changes.apply')"
      :description="t('network.changes.apply_body')"
      :confirm-label="t('network.changes.apply')"
      :phrase="change.deviceKey ?? ''"
      :busy="applying.processing"
      @close="confirming = null"
      @confirm="apply"
    />
  </AdminLayout>
</template>
