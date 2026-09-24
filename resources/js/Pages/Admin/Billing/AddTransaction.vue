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
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppCheckbox from '../../../Components/AppCheckbox.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
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
</script>

<template>
  <Head title="Add transaction" />

  <AdminLayout
    heading="Add Transaction"
    description="Money that moved outside the platform. Naming an invoice settles it the same way a gateway would; nothing here rewrites a row that already exists."
  >
    <form class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]" @submit.prevent="submit">
      <div class="flex flex-col gap-6">
        <AppCard title="Client" description="Whose money this is. Every ledger row belongs to one.">
          <div v-if="chosen" class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-body font-medium">{{ chosen.name }}</p>
              <p class="text-content-muted text-chrome">Currency {{ chosen.currency }}</p>
            </div>
            <AppButton type="button" variant="ghost" size="sm" @click="forget">Change</AppButton>
          </div>

          <div v-else class="flex flex-col gap-3">
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <AppInput v-model="term" label="Search clients" @keyup.enter="search" />
              </div>
              <AppButton type="button" @click="search">Search</AppButton>
            </div>

            <ul v-if="candidates.length > 0" class="divide-line divide-y">
              <li v-for="candidate in candidates" :key="candidate.id">
                <button
                  type="button"
                  class="hover:bg-surface-secondary flex w-full items-center justify-between gap-3 rounded-sm px-2 py-2.5 text-left transition-colors duration-(--duration-fast)"
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
              Nobody matches that. A closed account still appears here — money arrives for those
              too.
            </p>
          </div>

          <p v-if="form.errors.customer_id" class="text-danger text-chrome mt-2">
            {{ form.errors.customer_id }}
          </p>
        </AppCard>

        <AppCard title="The movement" description="Fill in one direction. Not both.">
          <div class="grid gap-4 sm:grid-cols-2">
            <AppInput
              v-model="form.occurred_at"
              label="Date"
              type="date"
              :error="form.errors.occurred_at"
            />
            <AppSelect
              v-model="form.currency_code"
              label="Currency"
              :options="currencies"
              :disabled="chosen !== null"
              :hint="chosen ? 'Taken from the client.' : 'Chosen only when no client has one.'"
            />
            <AppInput v-model="form.amount_in" label="Amount in" :error="form.errors.amount_in" />
            <AppInput
              v-model="form.amount_out"
              label="Amount out"
              :error="form.errors.amount_out"
            />
            <AppInput
              v-model="form.fees"
              label="Fees"
              hint="What the gateway kept. Not subtracted from the amount."
              :error="form.errors.fees"
            />
            <AppInput
              v-model="form.reference"
              label="Transaction ID"
              hint="What the bank or the gateway calls it."
              :error="form.errors.reference"
            />
            <AppSelect v-model="form.gateway" label="Payment method" :options="gateways" />
            <AppInput
              v-model="form.invoice_ids"
              label="Invoice ID(s)"
              hint="Comma separated. Applied in the order given."
              :error="form.errors.invoice_ids"
            />
          </div>

          <div class="mt-4">
            <AppTextarea
              v-model="form.description"
              label="Description"
              :rows="2"
              :error="form.errors.description"
            />
          </div>

          <div class="mt-4">
            <AppCheckbox
              v-model="form.to_credit"
              label="Credit"
              description="Anything left over goes on the client's credit balance."
            />
          </div>
        </AppCard>

        <AppCard
          v-if="openInvoices.length > 0"
          title="Open invoices"
          description="Tick what this money is for rather than retyping a number."
        >
          <ul class="divide-line divide-y">
            <li
              v-for="invoice in openInvoices"
              :key="invoice.id"
              class="flex items-center justify-between gap-3 py-2.5"
            >
              <label class="flex items-center gap-3">
                <input
                  type="checkbox"
                  class="border-line-strong accent-brand size-4 rounded-[4px] border"
                  :checked="ticked.includes(invoice.number)"
                  @change="toggleInvoice(invoice)"
                />
                <span>
                  <span class="text-chrome block font-mono">{{ invoice.number }}</span>
                  <span class="text-content-muted text-chrome block">
                    <AppStatus
                      :tone="statusTone(invoice.status)"
                      class="mr-1.5"
                      :label="invoice.statusLabel"
                    />
                    <span v-if="invoice.dueOn">due {{ invoice.dueOn }}</span>
                  </span>
                </span>
              </label>
              <span class="text-chrome text-right tabular-nums">
                <span class="block">{{ invoice.balance }}</span>
                <span class="text-content-muted block">of {{ invoice.total }}</span>
              </span>
            </li>
          </ul>
        </AppCard>
      </div>

      <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
        <AppCard title="Before it is written">
          <dl class="text-body flex flex-col gap-2">
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">Direction</dt>
              <dd v-if="bothFilled" class="text-danger">Both filled</dd>
              <dd v-else-if="neitherFilled" class="text-content-muted">—</dd>
              <dd v-else>{{ positive(form.amount_in) ? 'In' : 'Out' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">Invoices</dt>
              <dd class="tabular-nums">{{ ticked.length }}</dd>
            </div>
            <div class="flex justify-between gap-4">
              <dt class="text-content-muted">Leftover</dt>
              <dd>{{ form.to_credit ? 'Credit balance' : 'Nowhere yet' }}</dd>
            </div>
          </dl>

          <p v-if="bothFilled" class="text-danger text-chrome mt-4">
            A transaction moves money one way. Fill in an amount in or an amount out, not both.
          </p>
          <p v-else-if="nowhereToPutIt" class="text-warning text-chrome mt-4">
            Money that arrived has to go somewhere: tick an invoice, or add it to the client's
            credit balance.
          </p>

          <div class="mt-5 flex gap-2">
            <AppButton
              type="submit"
              variant="primary"
              :disabled="blocked"
              :loading="form.processing"
            >
              Add Transaction
            </AppButton>
            <Link href="/admin/transactions">
              <AppButton type="button" variant="ghost">Cancel</AppButton>
            </Link>
          </div>
        </AppCard>
      </aside>
    </form>
  </AdminLayout>
</template>
