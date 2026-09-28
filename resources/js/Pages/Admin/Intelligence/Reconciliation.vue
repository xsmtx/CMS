<script setup lang="ts">
/**
 * What this platform believes, against what each provider reports (§22).
 *
 * **Worst first, and “nobody could ask” last.** The order is the screen's
 * whole argument: a customer paying for an account that is not there comes
 * before a machine nobody is billing for, and both come before a provider
 * that did not answer — which is a fact about the connection rather than
 * about anybody's account. The order is the server's, so the page never
 * re-sorts.
 *
 * **Both words are on the row.** “We say Active, they say Suspended” is the
 * whole finding, and putting either half behind a click would make an
 * operator open fifty drawers to read fifty sentences.
 *
 * Nothing here repairs anything. The only writes on this screen are an
 * operator saying a difference is deliberate, and undoing that.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppPagination from '../../../Components/AppPagination.vue'
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

interface Dismissal {
  reason: string
  until: string | null
  by: string | null
}

interface FindingRow {
  id: string
  subject: string
  resource: string
  resourceLabel: string
  class: string
  classLabel: string
  classTone: string
  field: string | null
  expected: string | null
  found: string | null
  detail: Record<string, string>
  remoteKey: string | null
  firstSeenAt: string
  lastSeenAt: string
  clearedAt: string | null
  dismissal: Dismissal | null
}

const props = defineProps<{
  findings: {
    data: FindingRow[]
    links: PaginationLink[]
    currentPage: number
    lastPage: number
    total: number
  }
  filters: { all: boolean }
  compared: boolean
  can: { remediate: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('intelligence.reconciliation.columns.subject') },
  { key: 'class', label: t('intelligence.reconciliation.columns.class') },
  { key: 'expected', label: t('intelligence.reconciliation.columns.expected') },
  { key: 'found', label: t('intelligence.reconciliation.columns.found') },
  { key: 'since', label: t('intelligence.reconciliation.columns.since') },
  { key: 'actions', label: '' },
]

const dismissing = ref<FindingRow | null>(null)

const form = useForm({ reason: '', until: '' })

function dismiss(): void {
  const finding = dismissing.value

  if (finding === null) return

  form.post(`/admin/intelligence/reconciliation/${finding.id}/dismiss`, {
    preserveScroll: true,
    onSuccess: () => {
      dismissing.value = null
      form.reset()
    },
  })
}

function undismiss(finding: FindingRow): void {
  router.delete(`/admin/intelligence/reconciliation/${finding.id}/dismiss`, {
    preserveScroll: true,
  })
}

function toggleAll(): void {
  router.get(
    '/admin/intelligence/reconciliation',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('intelligence.reconciliation.title')" />

  <AdminLayout
    :heading="t('ui.nav.reconciliation')"
    :description="t('intelligence.reconciliation.intro')"
  >
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{
          filters.all
            ? t('intelligence.reconciliation.show_open')
            : t('intelligence.reconciliation.show_all')
        }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <DetailSection
        v-if="dismissing"
        :title="`${dismissing.subject} — ${t('intelligence.reconciliation.dismiss_title')}`"
        :description="t('intelligence.reconciliation.dismiss_body')"
      >
        <form class="flex max-w-3xl flex-col gap-4" @submit.prevent="dismiss">
          <AppTextarea
            v-model="form.reason"
            :label="t('intelligence.reconciliation.reason')"
            :hint="t('intelligence.reconciliation.reason_hint')"
            :error="form.errors.reason"
          />
          <div class="grid gap-3 sm:grid-cols-2">
            <AppInput
              v-model="form.until"
              type="datetime-local"
              :label="t('intelligence.reconciliation.until')"
              :hint="t('intelligence.reconciliation.until_hint')"
              :error="form.errors.until"
            />
          </div>
          <div class="flex gap-3">
            <AppButton type="submit" variant="primary" :loading="form.processing">
              {{ t('intelligence.reconciliation.dismiss') }}
            </AppButton>
            <AppButton variant="ghost" @click="dismissing = null">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="findings.data.length === 0 && !compared"
        icon="sync"
        :title="t('intelligence.reconciliation.empty_all')"
        :description="t('intelligence.reconciliation.empty_all_detail')"
      />

      <EmptyState
        v-else-if="findings.data.length === 0"
        icon="ok"
        :title="t('intelligence.reconciliation.empty')"
        :description="t('intelligence.reconciliation.empty_detail')"
      />

      <AppTable v-else name="reconciliation" :columns="COLUMNS">
        <AppTableRow v-for="finding in findings.data" :key="finding.id">
          <td data-col="subject">
            <span class="font-medium">{{ finding.subject }}</span>
            <span class="text-content-muted text-chrome block">
              {{ finding.resourceLabel }}
              <template v-if="finding.detail.module">
                · {{ t('intelligence.reconciliation.through', { module: finding.detail.module }) }}
              </template>
            </span>
            <span v-if="finding.remoteKey" class="text-content-subtle text-chrome block font-mono">
              {{ finding.remoteKey }}
            </span>
            <!--
              On the row rather than in a block of its own underneath: a
              second list repeating rows that are already on screen is two
              places for one fact.
            -->
            <span
              v-if="finding.dismissal"
              class="text-content-muted text-chrome mt-1 block max-w-[60ch]"
            >
              {{
                finding.dismissal.until
                  ? t('intelligence.reconciliation.dismissed_until', {
                      date: day(finding.dismissal.until),
                    })
                  : t('intelligence.reconciliation.dismissed_indefinitely')
              }}
              — {{ finding.dismissal.reason }}
            </span>
          </td>
          <td data-col="class">
            <AppStatus :tone="asTone(finding.classTone)" :label="finding.classLabel" />
            <!--
              A sentence rather than a dash: a difference nobody could ask
              about is a finding about the connection, not about the
              customer, and an operator reading it as the second goes and
              looks in the wrong place.
            -->
            <span
              v-if="finding.class === 'unknown'"
              class="text-content-subtle text-chrome mt-1 block max-w-[48ch]"
            >
              {{ t('intelligence.reconciliation.unknown_detail') }}
            </span>
          </td>
          <td data-col="expected" class="text-content-muted">{{ finding.expected ?? '—' }}</td>
          <td data-col="found">
            <span v-if="finding.found">{{ finding.found }}</span>
            <span v-else class="text-content-subtle">
              {{ t('intelligence.reconciliation.nothing_said') }}
            </span>
          </td>
          <td data-col="since" class="text-content-muted text-chrome tabular-nums">
            {{ day(finding.firstSeenAt) }}
          </td>
          <td data-col="actions" class="text-right whitespace-nowrap">
            <span v-if="can.remediate" class="row-actions inline-flex gap-1">
              <AppButton
                v-if="finding.dismissal"
                size="sm"
                variant="ghost"
                @click="undismiss(finding)"
              >
                {{ t('intelligence.reconciliation.undismiss') }}
              </AppButton>
              <AppButton v-else size="sm" variant="ghost" @click="dismissing = finding">
                {{ t('intelligence.reconciliation.dismiss') }}
              </AppButton>
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="findings.links" :total="findings.total" />
    </div>
  </AdminLayout>
</template>
