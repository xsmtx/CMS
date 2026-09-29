<script setup lang="ts">
/**
 * Which customers somebody should look at, and why (§21).
 *
 * **There is no score on this screen and there will not be one.** §21 asks
 * for customer health to be explainable, and the only honest way to be
 * explainable is not to compute the thing that would need explaining. Each
 * signal carries its own arithmetic and its own sentence; nothing is added
 * to anything else.
 *
 * The rows are ordered by the **worst thing that is true** of each customer
 * — an ordering the product already has words for — and alphabetically
 * within that. Never by a count, which would be the weighted total this
 * screen refuses to compute.
 */
import { Head, Link } from '@inertiajs/vue3'

import AppAlert from '../../../Components/AppAlert.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface Amount {
  currency: string
  amount: string
  minor: number
}

interface Signal {
  kind: string
  label: string
  tone: string
  count: number
  days: number | null
  figure: string
  money: Amount[] | null
}

interface Row {
  id: string
  name: string
  href: string
  worst: string
  signals: Signal[]
}

defineProps<{
  rows: Row[]
  examined: string
  kinds: { value: string; label: string; description: string }[]
}>()

const { t } = useTranslations()

const COLUMNS: TableColumn[] = [
  { key: 'customer', label: t('intelligence.health.columns.customer') },
  { key: 'signals', label: t('intelligence.health.columns.signals') },
]
</script>

<template>
  <Head :title="t('intelligence.health.title')" />

  <AdminLayout :heading="t('ui.nav.customer_health')" :description="t('intelligence.health.intro')">
    <template #meta>
      <span class="text-content-muted">
        {{ examined }}
      </span>
    </template>

    <div class="flex flex-col gap-8">
      <EmptyState
        v-if="rows.length === 0"
        icon="ok"
        :title="t('intelligence.health.empty')"
        :description="t('intelligence.health.empty_detail')"
      />

      <template v-else>
        <AppTable name="customer-health" :columns="COLUMNS">
          <AppTableRow v-for="row in rows" :key="row.id">
            <td data-col="customer">
              <Link :href="row.href" class="text-brand font-medium hover:underline">
                {{ row.name }}
              </Link>
            </td>
            <td data-col="signals">
              <!--
                Every signal, each with its own figure. Nothing is summed:
                three overdue invoices and one failed service are two facts,
                not a four.
              -->
              <div class="flex flex-col gap-1">
                <div
                  v-for="signal in row.signals"
                  :key="signal.kind"
                  class="flex flex-wrap items-baseline gap-x-2"
                >
                  <AppStatus :tone="asTone(signal.tone)" :label="signal.label" />
                  <span class="text-content-muted text-chrome tabular-nums">
                    {{ signal.figure }}
                  </span>
                  <span
                    v-for="amount in signal.money ?? []"
                    :key="amount.currency"
                    class="text-content-muted text-chrome tabular-nums"
                  >
                    {{ amount.amount }}
                  </span>
                </div>
              </div>
            </td>
          </AppTableRow>
        </AppTable>

        <!--
          Said in words rather than assumed: a reader who expects a score
          deserves to be told there is not one, and why.
        -->
        <AppAlert tone="info">
          {{ t('intelligence.health.no_score') }}
        </AppAlert>
      </template>
    </div>
  </AdminLayout>
</template>
