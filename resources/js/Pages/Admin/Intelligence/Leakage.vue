<script setup lang="ts">
/**
 * Money that quietly stopped arriving (§21).
 *
 * **The strip is a list, never a number.** There is no rate anywhere in this
 * product, so a total across currencies is a figure that means nothing — and
 * it is exactly the figure somebody would put in front of a board. One
 * figure per currency, and the sentence under it says what it is: what would
 * have been invoiced, not what is owed.
 *
 * **Why, not just what.** Every row carries the kind's own sentence, because
 * "Not invoiced" beside a service name is a fact an operator cannot act on
 * without knowing which of four questions found it.
 *
 * Nothing on this screen raises an invoice. Some of these are deliberate —
 * a charity given free hosting, a domain held for a customer arriving in
 * March — which is why the only write here is saying so.
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
import MetricStrip from '../../../Components/MetricStrip.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

interface KindOption {
  value: string
  label: string
  description: string
}

interface FindingRow {
  id: string
  subject: string
  kind: string
  kindLabel: string
  kindTone: string
  customer: string | null
  amount: string
  currency: string
  detail: Record<string, string>
  firstSeenAt: string
  clearedAt: string | null
  dismissal: { reason: string; until: string | null } | null
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
  atStake: { currency: string; amount: string; minor: number; count: number }[]
  kinds: KindOption[]
  can: { dismiss: boolean }
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'subject', label: t('intelligence.leakage.columns.subject') },
  { key: 'kind', label: t('intelligence.leakage.columns.kind') },
  { key: 'customer', label: t('intelligence.leakage.columns.customer') },
  { key: 'amount', label: t('intelligence.leakage.columns.amount'), numeric: true },
  { key: 'since', label: t('intelligence.leakage.columns.since') },
  { key: 'actions', label: '' },
]

const dismissing = ref<FindingRow | null>(null)

const form = useForm({ reason: '', until: '' })

const describe = (value: string): string =>
  props.kinds.find((kind) => kind.value === value)?.description ?? ''

function dismiss(): void {
  const finding = dismissing.value

  if (finding === null) return

  form.post(`/admin/intelligence/leakage/${finding.id}/dismiss`, {
    preserveScroll: true,
    onSuccess: () => {
      dismissing.value = null
      form.reset()
    },
  })
}

function undismiss(finding: FindingRow): void {
  router.delete(`/admin/intelligence/leakage/${finding.id}/dismiss`, { preserveScroll: true })
}

function toggleAll(): void {
  router.get(
    '/admin/intelligence/leakage',
    { all: props.filters.all ? undefined : 1 },
    { preserveState: true, replace: true },
  )
}

function day(value: string): string {
  return new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('intelligence.leakage.title')" />

  <AdminLayout :heading="t('ui.nav.leakage')" :description="t('intelligence.leakage.intro')">
    <template #actions>
      <AppButton variant="ghost" @click="toggleAll">
        {{ filters.all ? t('intelligence.leakage.show_open') : t('intelligence.leakage.show_all') }}
      </AppButton>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        One figure per currency. A single total would need a rate, there is
        none in this product, and the number it produced is the one somebody
        would quote.
      -->
      <div v-if="atStake.length > 0">
        <MetricStrip
          :items="
            atStake.map((row) => ({
              key: row.currency,
              label: row.currency,
              value: row.amount,
              hint: t('intelligence.leakage.count', { count: row.count }),
            }))
          "
        />
        <p class="text-content-muted text-chrome mt-2">
          {{ t('intelligence.leakage.total_hint') }}
        </p>
      </div>

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
              {{ t('intelligence.leakage.dismiss') }}
            </AppButton>
            <AppButton variant="ghost" @click="dismissing = null">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </form>
      </DetailSection>

      <EmptyState
        v-if="findings.data.length === 0"
        icon="ok"
        :title="t('intelligence.leakage.empty')"
        :description="t('intelligence.leakage.empty_detail')"
      />

      <AppTable v-else name="leakage" :columns="COLUMNS">
        <AppTableRow v-for="finding in findings.data" :key="finding.id">
          <td data-col="subject">
            <span class="font-medium">{{ finding.subject }}</span>
            <span v-if="finding.detail.service" class="text-content-muted text-chrome block">
              {{ finding.detail.service }}
            </span>
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
          <td data-col="kind">
            <AppStatus :tone="asTone(finding.kindTone)" :label="finding.kindLabel" />
            <!--
              Why, not just what: the kind's own sentence, because a name
              beside "Not invoiced" is a fact nobody can act on without
              knowing which of four questions found it.
            -->
            <span class="text-content-subtle text-chrome mt-1 block max-w-[52ch]">
              {{ describe(finding.kind) }}
            </span>
          </td>
          <td data-col="customer">
            <span v-if="finding.customer">{{ finding.customer }}</span>
            <span v-else class="text-content-subtle">
              {{ t('intelligence.leakage.no_customer') }}
            </span>
          </td>
          <td data-col="amount" class="numeric tabular-nums">{{ finding.amount }}</td>
          <td data-col="since" class="text-content-muted text-chrome tabular-nums">
            {{ day(finding.firstSeenAt) }}
          </td>
          <td data-col="actions" class="w-48 text-right whitespace-nowrap">
            <span v-if="can.dismiss" class="row-actions inline-flex gap-1">
              <AppButton
                v-if="finding.dismissal"
                size="sm"
                variant="ghost"
                @click="undismiss(finding)"
              >
                {{ t('intelligence.leakage.undismiss') }}
              </AppButton>
              <AppButton
                v-else-if="!finding.clearedAt"
                size="sm"
                variant="ghost"
                @click="dismissing = finding"
              >
                {{ t('intelligence.leakage.dismiss') }}
              </AppButton>
            </span>
          </td>
        </AppTableRow>
      </AppTable>

      <AppPagination :links="findings.links" :total="findings.total" />
    </div>
  </AdminLayout>
</template>
