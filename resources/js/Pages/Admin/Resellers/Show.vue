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
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { type TableColumn } from '../../../Components/tableContext'
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

const CATALOGUE_COLUMNS: TableColumn[] = [
  { key: 'product', label: 'Product' },
  { key: 'group', label: 'Group', optional: true },
  { key: 'enabled', label: 'May sell' },
  { key: 'margin', label: 'Margin %', numeric: true },
  { key: 'save', label: '' },
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
</script>

<template>
  <Head :title="reseller.name" />

  <AdminLayout
    :heading="reseller.name"
    :description="`${reseller.customers} customer(s), ${enabledCount} product(s) they may sell.`"
  >
    <div class="flex flex-col gap-5">
      <!-- Who runs it, and what they hold. The two facts somebody arriving
           from the list wants before they change anything. -->
      <div class="grid gap-5 lg:grid-cols-3">
        <AppCard class="lg:col-span-2" title="Who runs it">
          <ul v-if="reseller.staff.length > 0" class="divide-line -my-1 divide-y">
            <li
              v-for="member in reseller.staff"
              :key="member.id"
              class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2"
            >
              <span class="text-body font-medium">{{ member.name }}</span>
              <span class="text-content-muted text-chrome">{{ member.email }}</span>
              <AppStatus
                v-if="member.status !== 'active'"
                tone="warning"
                :label="member.statusLabel"
                compact
              />
            </li>
          </ul>
          <p v-else class="text-content-muted text-body">Nobody yet.</p>
        </AppCard>

        <AppCard
          title="Balance"
          description="A positive balance is what they hold with you. Negative means they owe you."
        >
          <ul v-if="balances.length > 0" class="flex flex-col gap-1.5">
            <li
              v-for="balance in balances"
              :key="balance.currency"
              class="flex items-baseline justify-between gap-4"
            >
              <span class="text-content-muted text-chrome">{{ balance.currency }}</span>
              <span
                class="text-title font-semibold tabular-nums"
                :class="balance.minor < 0 ? 'text-danger' : ''"
              >
                {{ balance.amount }}
              </span>
            </li>
          </ul>
          <p v-else class="text-content-muted text-body">No movements yet.</p>
        </AppCard>
      </div>

      <AppCard
        title="What they may sell"
        description="Absence is a refusal: a product with nothing ticked is not sold, and an empty margin is the provider price rather than zero."
      >
        <AppTable
          v-if="catalogue.length > 0"
          name="reseller-catalogue"
          :columns="CATALOGUE_COLUMNS"
        >
          <AppTableRow v-for="row in catalogue" :key="row.id">
            <td data-col="product" class="px-4 py-2.5">{{ row.name }}</td>
            <td data-col="group" class="text-content-muted px-4 py-2.5">{{ row.group ?? '—' }}</td>
            <td data-col="enabled" class="px-4 py-2.5">
              <input
                v-model="edited[row.id]!.isEnabled"
                type="checkbox"
                class="border-line-strong accent-brand size-3.5 rounded-[3px] border"
                :aria-label="`${reseller.name} may sell ${row.name}`"
              />
            </td>
            <td data-col="margin" class="numeric px-4 py-2.5">
              <input
                v-model="edited[row.id]!.margin"
                type="text"
                inputmode="decimal"
                placeholder="—"
                class="border-line bg-surface-primary w-20 rounded-[var(--radius-sm)] border px-2 py-1 text-right text-xs tabular-nums"
                :aria-label="`Margin for ${row.name}, per cent`"
              />
            </td>
            <td data-col="save" class="px-4 py-2.5 text-right">
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
                Save
              </AppButton>
            </td>
          </AppTableRow>
        </AppTable>

        <EmptyState
          v-else
          icon="catalog"
          title="Nothing to sell yet"
          description="Add a product to your own catalogue first, then decide which resellers may offer it."
        />
      </AppCard>

      <AppCard
        title="Exact prices"
        description="An exact number beats any margin, because somebody who typed a number meant that number."
      >
        <form class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="savePrice">
          <AppSelect
            v-model="priceForm.product_id"
            label="Product"
            :options="productOptions"
            :error="priceForm.errors.product_id"
          />
          <AppSelect
            v-model="priceForm.billing_cycle"
            label="Cycle"
            :options="cycles"
            :error="priceForm.errors.billing_cycle"
          />
          <AppInput
            v-model="priceForm.currency_code"
            label="Currency"
            :error="priceForm.errors.currency_code"
          />
          <AppInput
            v-model="priceForm.recurring_minor"
            label="Recurring (minor units)"
            type="number"
            :error="priceForm.errors.recurring_minor"
            hint="Integer minor units, never a decimal."
          />
          <div class="flex items-end">
            <AppButton type="submit" variant="primary" :loading="priceForm.processing">
              Set price
            </AppButton>
          </div>
        </form>

        <AppTable
          v-if="prices.length > 0"
          :headers="['Product', 'Cycle', 'Currency', 'Recurring', 'Setup', '']"
          :numeric="[3, 4]"
        >
          <tr v-for="price in prices" :key="price.id">
            <td class="px-4 py-2.5">{{ price.product ?? '—' }}</td>
            <td class="text-content-muted px-4 py-2.5">{{ price.cycleLabel }}</td>
            <td class="text-content-muted px-4 py-2.5">{{ price.currency }}</td>
            <td class="numeric px-4 py-2.5">{{ price.recurring }}</td>
            <td class="numeric px-4 py-2.5">{{ price.setup }}</td>
            <td class="px-4 py-2.5 text-right">
              <AppButton size="sm" variant="ghost" @click="clearPrice(price)">Clear</AppButton>
            </td>
          </tr>
        </AppTable>

        <p v-else class="text-content-muted text-body">
          No exact prices. Every product is sold at the provider price plus its margin.
        </p>
      </AppCard>

      <AppCard
        title="The account"
        description="Append-only. Every amount is positive and the kind decides which way it moves."
      >
        <form class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="recordEntry">
          <AppSelect
            v-model="entryForm.kind"
            label="Movement"
            :options="kinds"
            :error="entryForm.errors.kind"
          />
          <AppInput
            v-model="entryForm.currency_code"
            label="Currency"
            :error="entryForm.errors.currency_code"
          />
          <AppInput
            v-model="entryForm.amount_minor"
            label="Amount (minor units)"
            type="number"
            :error="entryForm.errors.amount_minor"
          />
          <AppInput
            v-model="entryForm.occurred_at"
            label="When the money moved"
            type="date"
            :error="entryForm.errors.occurred_at"
            hint="Optional. Today, if you leave it."
          />
          <div class="flex items-end">
            <AppButton type="submit" variant="primary" :loading="entryForm.processing">
              Record
            </AppButton>
          </div>
          <AppInput
            v-model="entryForm.description"
            label="Description"
            class="sm:col-span-2 lg:col-span-5"
            :error="entryForm.errors.description"
            hint="Goes on the statement and into the audit record."
          />
        </form>

        <AppTable
          v-if="statement.length > 0"
          :headers="['When', 'Movement', 'Amount', 'Balance', 'Note']"
          :numeric="[2, 3]"
        >
          <tr v-for="row in statement" :key="row.id">
            <td class="text-content-muted px-4 py-2.5 whitespace-nowrap">
              {{ formatDateTime(row.occurredAt) }}
            </td>
            <td class="px-4 py-2.5">
              <AppStatus
                :tone="row.increases ? 'healthy' : 'warning'"
                :label="row.kindLabel"
                compact
              />
            </td>
            <!-- The sign is drawn here and stored nowhere: the amount in the
                 table is positive on purpose, and a column where the sign and
                 the kind could disagree is a ledger that lies twice. -->
            <td class="numeric px-4 py-2.5" :class="row.increases ? '' : 'text-danger'">
              {{ row.increases ? '+' : '−' }}{{ row.amount }}
            </td>
            <td class="numeric px-4 py-2.5">{{ row.balance }}</td>
            <td class="text-content-muted px-4 py-2.5">
              {{ row.description ?? '—' }}
              <span v-if="row.recordedBy" class="text-content-subtle block text-xs">
                {{ row.recordedBy }}
              </span>
            </td>
          </tr>
        </AppTable>

        <p v-else class="text-content-muted text-body">No movements yet.</p>
      </AppCard>

      <p class="text-content-subtle text-label">
        <Link href="/admin/organizations" class="underline-offset-4 hover:underline">
          See where this reseller sits in the organization tree
        </Link>
      </p>
    </div>
  </AdminLayout>
</template>
