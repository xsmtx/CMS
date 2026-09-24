<script setup lang="ts">
/**
 * Writing down money that moved somewhere the platform could not see.
 *
 * A wire transfer that landed in the bank, a gateway payout reconciled by
 * hand, a chargeback that came back a fortnight later.
 *
 * **Two amount fields, not one signed amount.** That is how a statement
 * reads and how somebody copying one thinks: a line has a credit column
 * and a debit column, and asking for a minus sign is asking for it to be
 * forgotten. Exactly one of them is filled; the form says so before the
 * server has to.
 *
 * The client's open invoices are shown as things to tick rather than
 * numbers to retype. An operator copying an invoice number off another
 * screen will eventually mistype one, and a payment against the wrong
 * invoice is a payment somebody unpicks by hand.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface Candidate {
  id: string
  name: string
  email: string | null
  currency: string
}

interface OpenInvoice {
  id: string
  number: string
  status: string
  statusLabel: string
  total: string
  balance: string
  currency: string
  dueOn: string | null
}

const props = defineProps<{
  gateways: { value: string; label: string }[]
  currencies: { value: string; label: string }[]
  defaultCurrency: string
  today: string
  candidates: Candidate[]
  chosen: { id: string; name: string; currency: string } | null
  openInvoices: OpenInvoice[]
}>()

const { t } = useTranslations()

const form = useForm({
  customer_id: props.chosen?.id ?? '',
  occurred_at: props.today,
  currency_code: props.chosen?.currency ?? props.defaultCurrency,
  amount_in: '',
  amount_out: '',
  fees: '',
  description: '',
  reference: '',
  invoice_ids: '',
  to_credit: false,
  gateway: props.gateways[0]?.value ?? 'manual',
})

/**
 * The client search: a partial reload of this same screen.
 *
 * Not a JSON endpoint of its own — the authorization, the boundary and the
 * presenter are already here, and a second door into the same data is a
 * second place to get one of those three wrong.
 */
const term = ref('')

function search(): void {
  router.get(
    '/admin/transactions/add',
    { q: term.value, customer: form.customer_id },
    { preserveState: true, replace: true, only: ['candidates'] },
  )
}

function choose(candidate: Candidate): void {
  form.customer_id = candidate.id
  form.currency_code = candidate.currency

  router.get(
    '/admin/transactions/add',
    { customer: candidate.id },
    { preserveState: true, replace: true, only: ['chosen', 'openInvoices'] },
  )
}

function forget(): void {
  form.customer_id = ''
  form.invoice_ids = ''

  router.get(
    '/admin/transactions/add',
    {},
    { preserveState: true, replace: true, only: ['chosen', 'openInvoices'] },
  )
}

/** Ticking an invoice edits the comma-separated field, which stays the truth. */
const ticked = computed<string[]>({
  get: () =>
    form.invoice_ids
      .split(',')
      .map((one) => one.trim())
      .filter((one) => one !== ''),
  set: (value: string[]) => (form.invoice_ids = value.join(', ')),
})

function toggleInvoice(invoice: OpenInvoice): void {
  ticked.value = ticked.value.includes(invoice.number)
    ? ticked.value.filter((one) => one !== invoice.number)
    : [...ticked.value, invoice.number]
}

/**
 * Money moves one way. Saying so here means the server never has to say it
 * to somebody who has already filled in a long form.
 */
const bothFilled = computed(() => positive(form.amount_in) && positive(form.amount_out))
const neitherFilled = computed(() => !positive(form.amount_in) && !positive(form.amount_out))
const nowhereToPutIt = computed(
  () =>
    positive(form.amount_in) && ticked.value.length === 0 && !form.to_credit && form.customer_id,
)

function positive(value: string): boolean {
  const number = Number(String(value).replace(',', '.'))

  return Number.isFinite(number) && number > 0
}

const blocked = computed(
  () =>
    form.customer_id === '' ||
    bothFilled.value ||
    neitherFilled.value ||
    Boolean(nowhereToPutIt.value),
)

// The currency follows the client, because a client's money has one.
watch(
  () => props.chosen,
  (chosen) => {
    if (chosen) {
      form.customer_id = chosen.id
      form.currency_code = chosen.currency
    }
  },
)

function submit(): void {
  form.post('/admin/transactions', { preserveScroll: true })
}

/** A due date is a date, not an ISO string somebody has to parse by eye. */
function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="t('ui.transaction.title')" />

  <AdminLayout :heading="t('ui.transaction.title')">
    <template #header>
      <PageHeader :title="t('ui.transaction.title')" :description="t('ui.transaction.intro')" />
    </template>

    <form
      class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]"
      @submit.prevent="submit"
    >
      <div class="flex min-w-0 flex-col gap-8">
        <DetailSection
          :title="t('ui.transaction.client')"
          :description="t('ui.transaction.client_intro')"
        >
          <template v-if="chosen" #actions>
            <AppButton type="button" variant="ghost" size="sm" @click="forget">
              {{ t('ui.transaction.change') }}
            </AppButton>
          </template>

          <div v-if="chosen">
            <p class="text-body font-medium">{{ chosen.name }}</p>
            <p class="text-content-muted text-chrome">
              {{ t('ui.transaction.currency_is', { currency: chosen.currency }) }}
            </p>
          </div>

          <div v-else class="flex flex-col gap-3">
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <AppInput
                  v-model="term"
                  :label="t('ui.transaction.search_clients')"
                  @keyup.enter="search"
                />
              </div>
              <AppButton type="button" @click="search">{{ t('ui.transaction.search') }}</AppButton>
            </div>

            <ul v-if="candidates.length > 0" class="divide-line-subtle divide-y">
              <li v-for="candidate in candidates" :key="candidate.id">
                <button
                  type="button"
                  class="pressable hover:bg-surface-hover flex w-full items-center justify-between gap-3 rounded-sm px-2 py-2.5 text-left transition-colors duration-(--duration-fast)"
                  @click="choose(candidate)"
                >
                  <span>
                    <span class="text-body block">{{ candidate.name }}</span>
                    <span v-if="candidate.email" class="text-content-muted text-chrome block">
                      {{ candidate.email }}
                    </span>
                  </span>
                  <span class="text-content-subtle text-chrome">{{ candidate.currency }}</span>
                </button>
              </li>
            </ul>

            <p v-else-if="term !== ''" class="text-content-muted text-body">
              {{ t('ui.transaction.nobody') }}
            </p>
          </div>

          <p v-if="form.errors.customer_id" class="text-danger text-chrome mt-2" role="alert">
            {{ form.errors.customer_id }}
          </p>
        </DetailSection>

        <DetailSection
          :title="t('ui.transaction.movement')"
          :description="t('ui.transaction.movement_intro')"
        >
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.occurred_at"
              :label="t('ui.transaction.date')"
              type="date"
              :error="form.errors.occurred_at"
            />
            <AppSelect
              v-model="form.currency_code"
              :label="t('ui.transaction.currency')"
              :options="currencies"
              :disabled="chosen !== null"
              :hint="
                chosen
                  ? t('ui.transaction.currency_hint_client')
                  : t('ui.transaction.currency_hint')
              "
            />
            <AppInput
              v-model="form.amount_in"
              :label="t('ui.transaction.amount_in')"
              :error="form.errors.amount_in"
            />
            <AppInput
              v-model="form.amount_out"
              :label="t('ui.transaction.amount_out')"
              :error="form.errors.amount_out"
            />
            <AppInput
              v-model="form.fees"
              :label="t('ui.transaction.fees')"
              :hint="t('ui.transaction.fees_hint')"
              :error="form.errors.fees"
            />
            <AppInput
              v-model="form.reference"
              :label="t('ui.transaction.reference')"
              :hint="t('ui.transaction.reference_hint')"
              :error="form.errors.reference"
            />
            <AppSelect
              v-model="form.gateway"
              :label="t('ui.transaction.method')"
              :options="gateways"
            />
            <AppInput
              v-model="form.invoice_ids"
              :label="t('ui.transaction.invoice_ids')"
              :hint="t('ui.transaction.invoice_ids_hint')"
              :error="form.errors.invoice_ids"
            />
          </div>

          <div class="mt-4">
            <AppTextarea
              v-model="form.description"
              :label="t('ui.transaction.description')"
              :rows="2"
              :error="form.errors.description"
            />
          </div>

          <div class="mt-4">
            <AppCheckbox
              v-model="form.to_credit"
              :label="t('ui.transaction.credit')"
              :description="t('ui.transaction.credit_hint')"
            />
          </div>
        </DetailSection>

        <DetailSection
          v-if="openInvoices.length > 0"
          :title="t('ui.transaction.open_invoices')"
          :description="t('ui.transaction.open_invoices_intro')"
        >
          <ul class="divide-line-subtle divide-y">
            <li v-for="invoice in openInvoices" :key="invoice.id">
              <label
                class="hover:bg-surface-hover flex cursor-pointer items-center justify-between gap-3 px-2 py-2.5 transition-colors duration-(--duration-fast)"
              >
                <span class="flex min-w-0 items-center gap-3">
                  <input
                    type="checkbox"
                    class="border-line-strong accent-brand size-4 shrink-0 rounded-sm border"
                    :checked="ticked.includes(invoice.number)"
                    @change="toggleInvoice(invoice)"
                  />
                  <span class="min-w-0">
                    <span class="text-chrome block font-mono">{{ invoice.number }}</span>
                    <span class="text-chrome mt-0.5 flex flex-wrap items-center gap-x-2">
                      <AppStatus :tone="statusTone(invoice.status)" :label="invoice.statusLabel" />
                      <span v-if="invoice.dueOn" class="text-content-muted">
                        {{ t('ui.transaction.due', { date: formatDate(invoice.dueOn) }) }}
                      </span>
                    </span>
                  </span>
                </span>
                <span class="text-chrome shrink-0 text-right tabular-nums">
                  <span class="block">{{ invoice.balance }}</span>
                  <span class="text-content-muted block">
                    {{ t('ui.transaction.of_total', { total: invoice.total }) }}
                  </span>
                </span>
              </label>
            </li>
          </ul>
        </DetailSection>
      </div>

      <!--
        The one framed surface on the page. It reads back what is about to be
        written and carries the primary action, and it is sticky — so it has to
        look like a thing that stays put while the form scrolls under it.
      -->
      <aside class="flex min-w-0 flex-col gap-8 lg:sticky lg:top-6 lg:self-start">
        <AppCard :title="t('ui.transaction.before')">
          <dl class="text-body flex flex-col gap-2">
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">{{ t('ui.transaction.direction') }}</dt>
              <dd v-if="bothFilled" class="text-danger">{{ t('ui.transaction.both') }}</dd>
              <dd v-else-if="neitherFilled" class="text-content-muted">—</dd>
              <dd v-else>
                {{ positive(form.amount_in) ? t('ui.transaction.in') : t('ui.transaction.out') }}
              </dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">{{ t('ui.transaction.invoices') }}</dt>
              <dd class="tabular-nums">{{ ticked.length }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">{{ t('ui.transaction.leftover') }}</dt>
              <dd>
                {{ form.to_credit ? t('ui.transaction.to_credit') : t('ui.transaction.nowhere') }}
              </dd>
            </div>
          </dl>

          <p v-if="bothFilled" class="text-danger text-chrome mt-4" role="alert">
            {{ t('ui.transaction.both_message') }}
          </p>
          <p v-else-if="nowhereToPutIt" class="text-warning text-chrome mt-4" role="alert">
            {{ t('ui.transaction.nowhere_message') }}
          </p>

          <div class="mt-5 flex gap-2">
            <AppButton
              type="submit"
              variant="primary"
              :disabled="blocked"
              :loading="form.processing"
            >
              {{ t('ui.transaction.add') }}
            </AppButton>
            <AppButton type="button" variant="ghost" href="/admin/transactions">
              {{ t('ui.confirm.cancel') }}
            </AppButton>
          </div>
        </AppCard>
      </aside>
    </form>
  </AdminLayout>
</template>
