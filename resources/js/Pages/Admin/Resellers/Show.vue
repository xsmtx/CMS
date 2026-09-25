<script setup lang="ts">
/**
 * One reseller: what they may sell, for how much, and what they owe.
 *
 * Three blocks, in the order somebody works through them:
 *
 * 1. **What they may sell.** A row per product in the provider's catalogue —
 *    the whole catalogue, because this is the screen where somebody decides
 *    what to allow, and a list of what is already allowed cannot be used to
 *    allow anything new. A row saves only when it is dirty, so opening the
 *    screen writes nothing.
 * 2. **Exact prices.** A number beats any margin, because somebody who typed
 *    a number meant that number. Clearing one removes the row rather than
 *    writing zero: absence means "not priced this way" and zero means free.
 * 3. **The account.** A positive balance is what the reseller holds with us;
 *    negative means they owe us. Said on the page, because it is the sort of
 *    convention that gets read backwards.
 *
 * The movement form offers no sign. The kind carries the direction (ADR
 * 0024), so there is no way to record a payment that took money away.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import MetricStrip, { type Metric } from '../../../Components/MetricStrip.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface CatalogueRow {
  id: string
  name: string
  group: string | null
  isEnabled: boolean
  marginPercent: string | null
}

interface PriceRow {
  id: string
  productId: string
  product: string | null
  cycle: string
  cycleLabel: string
  currency: string
  recurringMinor: number
  setupMinor: number
  recurring: string
  setup: string
}

interface StatementRow {
  id: string
  kind: string
  kindLabel: string
  increases: boolean
  amount: string
  balance: string
  currency: string
  description: string | null
  recordedBy: string | null
  occurredAt: string
}

const props = defineProps<{
  reseller: {
    id: string
    name: string
    slug: string
    isActive: boolean
    customers: number
    createdAt: string
    staff: { id: string; name: string; email: string; status: string; statusLabel: string }[]
  }
  catalogue: CatalogueRow[]
  prices: PriceRow[]
  cycles: { value: string; label: string }[]
  balances: { currency: string; amount: string; minor: number }[]
  statement: StatementRow[]
  kinds: { value: string; label: string; increases: boolean }[]
}>()

const { t } = useTranslations()

const CATALOGUE_COLUMNS: TableColumn[] = [
  { key: 'product', label: t('ui.reseller.product') },
  { key: 'group', label: t('ui.reseller.group'), optional: true },
  { key: 'enabled', label: t('ui.reseller.may_sell') },
  { key: 'margin', label: t('ui.reseller.margin'), numeric: true },
  { key: 'save', label: '' },
]

const PRICE_COLUMNS: TableColumn[] = [
  { key: 'product', label: t('ui.reseller.product') },
  { key: 'cycle', label: t('ui.reseller.cycle') },
  { key: 'currency', label: t('ui.reseller.currency') },
  { key: 'recurring', label: t('ui.reseller.recurring'), numeric: true },
  { key: 'setup', label: t('ui.reseller.setup'), numeric: true },
  { key: 'clear', label: '' },
]

const STATEMENT_COLUMNS: TableColumn[] = [
  { key: 'when', label: t('ui.reseller.when') },
  { key: 'movement', label: t('ui.reseller.movement') },
  { key: 'amount', label: t('ui.reseller.amount'), numeric: true },
  { key: 'balance', label: t('ui.reseller.balance'), numeric: true },
  { key: 'note', label: t('ui.reseller.note') },
]

/**
 * The edited state of each row, keyed by product.
 *
 * Kept beside the server's answer rather than replacing it, so "dirty" is a
 * comparison rather than a flag somebody has to remember to clear.
 */
const edited = reactive<Record<string, { isEnabled: boolean; margin: string }>>(
  Object.fromEntries(
    props.catalogue.map((row) => [
      row.id,
      { isEnabled: row.isEnabled, margin: row.marginPercent ?? '' },
    ]),
  ),
)

const saving = ref<string | null>(null)

function isDirty(row: CatalogueRow): boolean {
  const state = edited[row.id]

  if (state === undefined) return false

  return state.isEnabled !== row.isEnabled || state.margin !== (row.marginPercent ?? '')
}

function saveRow(row: CatalogueRow): void {
  const state = edited[row.id]

  if (state === undefined) return

  saving.value = row.id

  router.post(
    `/admin/resellers/${props.reseller.id}/availability`,
    {
      product_id: row.id,
      is_enabled: state.isEnabled,
      // An empty field is "the provider's price", which is not the same
      // answer as zero — so it is sent as null rather than as 0.
      margin_percent: state.margin.trim() === '' ? null : state.margin.trim(),
    },
    {
      preserveScroll: true,
      onFinish: () => {
        saving.value = null
      },
    },
  )
}

const enabledCount = computed(() => props.catalogue.filter((row) => row.isEnabled).length)

// --- exact prices -----------------------------------------------------------

const priceForm = useForm({
  product_id: props.catalogue[0]?.id ?? '',
  billing_cycle: props.cycles[0]?.value ?? '',
  currency_code: props.balances[0]?.currency ?? 'EUR',
  recurring_minor: 0,
  setup_minor: 0,
})

const productOptions = computed(() =>
  props.catalogue.map((row) => ({ value: row.id, label: row.name })),
)

function savePrice(): void {
  priceForm.post(`/admin/resellers/${props.reseller.id}/prices`, { preserveScroll: true })
}

function clearPrice(price: PriceRow): void {
  router.post(
    `/admin/resellers/${props.reseller.id}/prices`,
    {
      product_id: price.productId,
      billing_cycle: price.cycle,
      currency_code: price.currency,
      // Null, not zero: this removes the row, and zero would mean free.
      recurring_minor: null,
    },
    { preserveScroll: true },
  )
}

// --- the account ------------------------------------------------------------

const entryForm = useForm({
  kind: props.kinds[0]?.value ?? '',
  currency_code: props.balances[0]?.currency ?? 'EUR',
  amount_minor: 0,
  description: '',
  occurred_at: '',
})

function recordEntry(): void {
  entryForm.post(`/admin/resellers/${props.reseller.id}/ledger`, {
    preserveScroll: true,
    onSuccess: () => entryForm.reset('amount_minor', 'description', 'occurred_at'),
  })
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString()
}

/**
 * What they hold and what they may sell, in one strip.
 *
 * The balance renders through its slot because it is a list: a reseller
 * selling in lira and euros owes two amounts and there is no rate here to
 * make them one. It carries a tone only when it is negative, which is the
 * one case where the figure *is* a state.
 */
const figures = computed<Metric[]>(() => [
  {
    key: 'customers',
    label: t('ui.reseller.customers'),
    value: props.reseller.customers,
  },
  {
    key: 'catalogue',
    label: t('ui.reseller.may_sell'),
    value: enabledCount.value,
    hint: t('ui.reseller.of_catalogue', { count: props.catalogue.length }),
  },
  {
    key: 'balance',
    label: t('ui.reseller.balance'),
    value: '—',
    hint: t('ui.reseller.balance_hint'),
  },
])
</script>

<template>
  <Head :title="reseller.name" />

  <AdminLayout :heading="reseller.name">
    <template #header>
      <PageHeader :title="reseller.name">
        <template #status>
          <AppStatus
            :tone="reseller.isActive ? 'healthy' : 'neutral'"
            :label="reseller.isActive ? t('ui.reseller.active') : t('ui.reseller.inactive')"
          />
        </template>

        <template #meta>
          <span class="font-mono">{{ reseller.slug }}</span>
          <span aria-hidden="true">·</span>
          <span>{{ t('ui.reseller.since', { date: formatDateTime(reseller.createdAt) }) }}</span>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <MetricStrip :items="figures">
        <template #balance>
          <span
            v-for="balance in balances"
            :key="balance.currency"
            class="block"
            :class="balance.minor < 0 ? 'text-danger' : ''"
          >
            {{ balance.amount }}
          </span>
          <span v-if="balances.length === 0" class="text-content-subtle">—</span>
        </template>
      </MetricStrip>

      <DetailSection :title="t('ui.reseller.who')" :description="t('ui.reseller.who_intro')">
        <ul v-if="reseller.staff.length > 0" class="divide-line-subtle divide-y">
          <!--
            Name and address together on the left rather than pushed to
            opposite edges: `justify-between` across a full-width row put a
            person's email 900px from their name, which reads as two columns
            of unrelated things.
          -->
          <li
            v-for="member in reseller.staff"
            :key="member.id"
            class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2 first:pt-0 last:pb-0"
          >
            <span class="flex flex-wrap items-baseline gap-x-2">
              <span class="text-body font-medium">{{ member.name }}</span>
              <span class="text-content-muted text-chrome">{{ member.email }}</span>
            </span>
            <AppStatus
              v-if="member.status !== 'active'"
              tone="warning"
              :label="member.statusLabel"
            />
          </li>
        </ul>
        <EmptyState
          v-else
          variant="plain"
          icon="user"
          :title="t('ui.reseller.nobody')"
          :description="t('ui.reseller.nobody_detail')"
        />
      </DetailSection>

      <DetailSection
        :title="t('ui.reseller.catalogue')"
        :description="t('ui.reseller.catalogue_intro')"
        :divided="catalogue.length === 0"
      >
        <AppTable
          v-if="catalogue.length > 0"
          name="reseller-catalogue"
          :columns="CATALOGUE_COLUMNS"
        >
          <AppTableRow v-for="row in catalogue" :key="row.id">
            <td data-col="product">{{ row.name }}</td>
            <td data-col="group" class="text-content-muted">{{ row.group ?? '—' }}</td>
            <td data-col="enabled">
              <input
                v-model="edited[row.id]!.isEnabled"
                type="checkbox"
                class="border-line-strong accent-brand size-3.5 rounded-sm border"
                :aria-label="
                  t('ui.reseller.may_sell_aria', { reseller: reseller.name, product: row.name })
                "
              />
            </td>
            <td data-col="margin" class="numeric">
              <input
                v-model="edited[row.id]!.margin"
                type="text"
                inputmode="decimal"
                placeholder="—"
                class="border-line bg-surface-primary text-chrome w-20 rounded-sm border px-2 py-1 text-right tabular-nums"
                :aria-label="t('ui.reseller.margin_aria', { product: row.name })"
              />
            </td>
            <td data-col="save" class="text-right">
              <!-- Only when the row has changed: a screen with forty Save
                   buttons on it is a screen where nobody knows which one
                   they still have to press. -->
              <AppButton
                v-if="isDirty(row)"
                size="sm"
                variant="primary"
                :loading="saving === row.id"
                @click="saveRow(row)"
              >
                {{ t('ui.reseller.save') }}
              </AppButton>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          variant="plain"
          icon="catalog"
          :title="t('ui.reseller.no_catalogue')"
          :description="t('ui.reseller.no_catalogue_detail')"
        />
      </DetailSection>

      <DetailSection :title="t('ui.reseller.prices')" :description="t('ui.reseller.prices_intro')">
        <form class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="savePrice">
          <AppSelect
            v-model="priceForm.product_id"
            :label="t('ui.reseller.product')"
            :options="productOptions"
            :error="priceForm.errors.product_id"
          />
          <AppSelect
            v-model="priceForm.billing_cycle"
            :label="t('ui.reseller.cycle')"
            :options="cycles"
            :error="priceForm.errors.billing_cycle"
          />
          <AppInput
            v-model="priceForm.currency_code"
            :label="t('ui.reseller.currency')"
            :error="priceForm.errors.currency_code"
          />
          <AppInput
            v-model="priceForm.recurring_minor"
            :label="t('ui.reseller.recurring_minor')"
            type="number"
            :error="priceForm.errors.recurring_minor"
            :hint="t('ui.reseller.minor_hint')"
          />
          <div class="flex items-end">
            <AppButton type="submit" variant="primary" :loading="priceForm.processing">
              {{ t('ui.reseller.set_price') }}
            </AppButton>
          </div>
        </form>

        <AppTable v-if="prices.length > 0" name="reseller-prices" :columns="PRICE_COLUMNS">
          <AppTableRow v-for="price in prices" :key="price.id">
            <td data-col="product">{{ price.product ?? '—' }}</td>
            <td data-col="cycle" class="text-content-muted">{{ price.cycleLabel }}</td>
            <td data-col="currency" class="text-content-muted">{{ price.currency }}</td>
            <td data-col="recurring" class="numeric">{{ price.recurring }}</td>
            <td data-col="setup" class="numeric">{{ price.setup }}</td>
            <td data-col="clear" class="text-right">
              <AppButton size="sm" variant="ghost" @click="clearPrice(price)">
                {{ t('ui.reseller.clear') }}
              </AppButton>
            </td>
          </AppTableRow>
        </AppTable>

        <p v-else class="text-content-muted text-body">{{ t('ui.reseller.no_prices') }}</p>
      </DetailSection>

      <DetailSection
        :title="t('ui.reseller.account')"
        :description="t('ui.reseller.account_intro')"
      >
        <form class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="recordEntry">
          <AppSelect
            v-model="entryForm.kind"
            :label="t('ui.reseller.movement')"
            :options="kinds"
            :error="entryForm.errors.kind"
          />
          <AppInput
            v-model="entryForm.currency_code"
            :label="t('ui.reseller.currency')"
            :error="entryForm.errors.currency_code"
          />
          <AppInput
            v-model="entryForm.amount_minor"
            :label="t('ui.reseller.amount_minor')"
            type="number"
            :error="entryForm.errors.amount_minor"
          />
          <AppInput
            v-model="entryForm.occurred_at"
            :label="t('ui.reseller.when_moved')"
            type="date"
            :error="entryForm.errors.occurred_at"
            :hint="t('ui.reseller.when_moved_hint')"
          />
          <div class="flex items-end">
            <AppButton type="submit" variant="primary" :loading="entryForm.processing">
              {{ t('ui.reseller.record') }}
            </AppButton>
          </div>
          <AppInput
            v-model="entryForm.description"
            :label="t('ui.reseller.note')"
            class="sm:col-span-2 lg:col-span-5"
            :error="entryForm.errors.description"
            :hint="t('ui.reseller.note_hint')"
          />
        </form>

        <AppTable
          v-if="statement.length > 0"
          name="reseller-statement"
          :columns="STATEMENT_COLUMNS"
        >
          <AppTableRow v-for="row in statement" :key="row.id">
            <td data-col="when" class="text-content-muted whitespace-nowrap">
              {{ formatDateTime(row.occurredAt) }}
            </td>
            <td data-col="movement">
              <AppStatus :tone="row.increases ? 'healthy' : 'warning'" :label="row.kindLabel" />
            </td>
            <!-- The sign is drawn here and stored nowhere: the amount in the
                 table is positive on purpose, and a column where the sign and
                 the kind could disagree is a ledger that lies twice. -->
            <td data-col="amount" class="numeric" :class="row.increases ? '' : 'text-danger'">
              {{ row.increases ? '+' : '−' }}{{ row.amount }}
            </td>
            <td data-col="balance" class="numeric">{{ row.balance }}</td>
            <td data-col="note" class="text-content-muted">
              {{ row.description ?? '—' }}
              <span v-if="row.recordedBy" class="text-content-subtle text-chrome block">
                {{ row.recordedBy }}
              </span>
            </td>
          </AppTableRow>
        </AppTable>

        <p v-else class="text-content-muted text-body">{{ t('ui.reseller.no_movements') }}</p>
      </DetailSection>

      <p class="text-content-subtle text-chrome">
        <Link href="/admin/organizations" class="underline-offset-4 hover:underline">
          {{ t('ui.reseller.tree_link') }}
        </Link>
      </p>
    </div>
  </AdminLayout>
</template>
