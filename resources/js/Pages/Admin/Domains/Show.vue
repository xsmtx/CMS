<script setup lang="ts">
/**
 * One domain: where it is registered, when it lapses, and what points at it.
 *
 * Laid out as a resource page (enterprise-cms-ux, "Detail pages"), the same
 * shape as a service:
 *
 * 1. **Identity** — the name, the status, and the everyday actions in the
 *    header band. Register, renew and sync are what an operator opens this
 *    screen to do.
 * 2. **The dates** — registered, expires, term and the registrar's own
 *    reference across the top. An expiry is the fact this page exists for, so
 *    it is above the fold and says how long is left.
 * 3. **Nameservers and activity** — what the domain points at, and every
 *    operation attempted against the registry with its outcome.
 * 4. **Settings** — auto-renew and the registrar lock are flags with a switch
 *    beside them rather than buttons in an action list. A button called "Turn
 *    auto-renew off" tells you what pressing it does; a row that says "On"
 *    with a control next to it tells you what is true now, which is the
 *    question somebody opened the section to answer.
 *
 * There is no danger zone. Nothing here destroys anything: a domain is
 * released by letting it lapse at the registry, which this platform cannot
 * and should not do on somebody's behalf.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppAlert from '../../../Components/AppAlert.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppCopy from '../../../Components/AppCopy.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DescriptionList, { type DescriptionItem } from '../../../Components/DescriptionList.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { useTranslations } from '../../../composables/useTranslations'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { statusTone } from '../../../status'

interface DomainEvent {
  id: string
  operation: string
  outcome: string
  outcomeLabel: string
  actor: string | null
  message: string | null
  occurredAt: string
}

const props = defineProps<{
  domain: {
    id: string
    name: string
    status: string
    statusLabel: string
    customer: string | null
    registrar: string | null
    externalId: string | null
    orderNumber: string | null
    registeredOn: string | null
    expiresOn: string | null
    daysUntilExpiry: number | null
    renewal: string
    years: number
    autoRenew: boolean
    registrarLock: boolean
    whoisPrivacy: boolean
    nameservers: string[]
    failureReason: string | null
    syncedAt: string | null
    events: DomainEvent[]
    transitions: { value: string; label: string }[]
  }
  can: {
    update: boolean
    register: boolean
    renew: boolean
    nameservers: boolean
    lock: boolean
    autoRenew: boolean
    sync: boolean
  }
}>()

const { t } = useTranslations()

const nameserverForm = useForm({
  operation: 'set_nameservers',
  nameservers: props.domain.nameservers.length > 0 ? [...props.domain.nameservers] : ['', ''],
})

const statusForm = useForm({
  status: props.domain.transitions[0]?.value ?? '',
  reason: '',
})

const renewing = ref(false)
const renewForm = useForm({ operation: 'renew', years: 1 })

/**
 * The term, in words. Turkish takes no plural after a number and English
 * does, so the choice is made here rather than with a `(s)` that is wrong in
 * both languages.
 */
function years(count: number): string {
  return count === 1 ? t('ui.domain.year_one', { count }) : t('ui.domain.year_many', { count })
}

/** Registered, expires, term, reference — read across, not down. */
const dates = computed<DescriptionItem[]>(() => [
  {
    key: 'registered',
    label: t('ui.domain.registered'),
    value: formatDate(props.domain.registeredOn),
  },
  { key: 'expires', label: t('ui.domain.expires') },
  { key: 'term', label: t('ui.domain.term'), value: years(props.domain.years) },
  {
    key: 'reference',
    label: t('ui.domain.reference'),
    value: props.domain.externalId,
    mono: true,
  },
])

const registration = computed<DescriptionItem[]>(() => [
  { key: 'renewal', label: t('ui.domain.renewal'), value: props.domain.renewal },
  { key: 'order', label: t('ui.domain.order'), value: props.domain.orderNumber },
  { key: 'synced', label: t('ui.domain.synced'), value: formatDateTime(props.domain.syncedAt) },
])

/**
 * An expiry close enough to act on is a warning, one that has passed is a
 * failure, and anything further out is neither. The thresholds match the
 * domain expiry sweep so the screen and the automation agree.
 */
const expiryTone = computed(() => {
  const days = props.domain.daysUntilExpiry

  if (days === null) return 'unknown'
  if (days < 0) return 'critical'
  if (days <= 30) return 'warning'

  return 'healthy'
})

function registerNow(): void {
  router.post(`/admin/domains/${props.domain.id}/register`, {}, { preserveScroll: true })
}

function act(operation: string, flag?: boolean): void {
  router.post(
    `/admin/domains/${props.domain.id}/actions`,
    flag === undefined ? { operation } : { operation, flag },
    { preserveScroll: true },
  )
}

function saveNameservers(): void {
  nameserverForm.post(`/admin/domains/${props.domain.id}/actions`, { preserveScroll: true })
}

function renew(): void {
  renewForm.post(`/admin/domains/${props.domain.id}/actions`, {
    preserveScroll: true,
    onSuccess: () => (renewing.value = false),
  })
}

function changeStatus(): void {
  statusForm.put(`/admin/domains/${props.domain.id}/status`, { preserveScroll: true })
}

function formatDateTime(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleString()
}

// A date-only column rendered raw was the one ISO string among localised ones.
function formatDate(value: string | null): string {
  return value === null ? '—' : new Date(value).toLocaleDateString()
}
</script>

<template>
  <Head :title="domain.name" />

  <AdminLayout :heading="domain.name">
    <template #header>
      <PageHeader :title="domain.name">
        <template #status>
          <AppStatus :tone="statusTone(domain.status)" :label="domain.statusLabel" />
        </template>

        <template #meta>
          <span v-if="domain.customer">{{ domain.customer }}</span>
          <template v-if="domain.registrar">
            <span v-if="domain.customer" aria-hidden="true">·</span>
            <span>{{ domain.registrar }}</span>
          </template>
          <span aria-hidden="true">·</span>
          <span class="tabular-nums">{{ domain.renewal }}</span>
        </template>

        <template #actions>
          <AppButton v-if="can.sync" variant="ghost" icon="sync" @click="act('sync')">
            {{ t('ui.domain.sync') }}
          </AppButton>
          <AppButton v-if="can.renew" @click="renewing = true">
            {{ t('ui.domain.renew') }}
          </AppButton>
          <AppButton v-if="can.register" variant="primary" icon="check" @click="registerNow">
            {{ t('ui.domain.register') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <AppAlert v-if="domain.failureReason" tone="danger">{{ domain.failureReason }}</AppAlert>

      <DescriptionList :items="dates" layout="grid" :columns="4">
        <template #expires>
          <span v-if="domain.expiresOn" class="flex flex-wrap items-center gap-x-2">
            <span>{{ formatDate(domain.expiresOn) }}</span>
            <!--
              The number of days is the fact somebody came for, so it carries
              a tone of its own rather than sitting in muted grey next to the
              date it is derived from.
            -->
            <AppStatus
              v-if="domain.daysUntilExpiry !== null"
              :tone="expiryTone"
              :label="
                domain.daysUntilExpiry < 0
                  ? t('ui.domain.expired_days', { count: Math.abs(domain.daysUntilExpiry) })
                  : t('ui.domain.expires_days', { count: domain.daysUntilExpiry })
              "
            />
          </span>
          <span v-else>—</span>
        </template>

        <template #reference>
          <AppCopy
            v-if="domain.externalId"
            :value="domain.externalId"
            :noun="t('ui.domain.reference')"
            mono
          />
          <span v-else>—</span>
        </template>
      </DescriptionList>

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div class="flex min-w-0 flex-col gap-8">
          <DetailSection
            v-if="can.nameservers"
            :title="t('ui.domain.nameservers')"
            :description="t('ui.domain.nameservers_intro')"
          >
            <template #actions>
              <AppButton
                size="sm"
                variant="ghost"
                icon="add"
                @click="nameserverForm.nameservers.push('')"
              >
                {{ t('ui.domain.add_nameserver') }}
              </AppButton>
            </template>

            <form class="flex flex-col gap-4" @submit.prevent="saveNameservers">
              <div class="grid gap-3 sm:grid-cols-2">
                <AppInput
                  v-for="(_, index) in nameserverForm.nameservers"
                  :key="index"
                  :model-value="nameserverForm.nameservers[index] ?? ''"
                  :label="t('ui.domain.nameserver_n', { number: index + 1 })"
                  :error="
                    nameserverForm.errors[
                      `nameservers.${index}` as keyof typeof nameserverForm.errors
                    ]
                  "
                  @update:model-value="
                    (value) => (nameserverForm.nameservers[index] = String(value))
                  "
                />
              </div>

              <div>
                <AppButton type="submit" variant="primary" :loading="nameserverForm.processing">
                  {{ t('ui.domain.save_nameservers') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>

          <DetailSection
            :title="t('ui.domain.activity')"
            :description="t('ui.domain.activity_intro')"
          >
            <ul v-if="domain.events.length > 0" class="divide-line-subtle divide-y">
              <li v-for="event in domain.events" :key="event.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-body flex flex-wrap items-center gap-x-2 font-medium">
                      <span>{{ event.operation }}</span>
                      <AppStatus :tone="statusTone(event.outcome)" :label="event.outcomeLabel" />
                    </p>
                    <p
                      v-if="event.message"
                      class="text-content-muted text-chrome mt-1 leading-relaxed break-words"
                    >
                      {{ event.message }}
                    </p>
                    <p v-if="event.actor" class="text-content-subtle text-chrome mt-1">
                      {{ event.actor }}
                    </p>
                  </div>
                  <p class="text-content-subtle text-chrome shrink-0 whitespace-nowrap">
                    {{ formatDateTime(event.occurredAt) }}
                  </p>
                </div>
              </li>
            </ul>
            <EmptyState
              v-else
              variant="plain"
              icon="history"
              :title="t('ui.domain.no_activity')"
              :description="t('ui.domain.no_activity_detail')"
            />
          </DetailSection>
        </div>

        <aside class="flex min-w-0 flex-col gap-8">
          <DetailSection :title="t('ui.domain.registration')">
            <DescriptionList :items="registration" />
          </DetailSection>

          <DetailSection
            :title="t('ui.domain.settings')"
            :description="t('ui.domain.settings_intro')"
          >
            <dl class="divide-line-subtle text-body divide-y">
              <div class="flex items-center justify-between gap-4 py-2 first:pt-0">
                <dt class="text-content-muted">{{ t('ui.domain.auto_renew') }}</dt>
                <dd class="flex items-center gap-3">
                  <span>{{ domain.autoRenew ? t('ui.common.on') : t('ui.common.off') }}</span>
                  <AppButton
                    v-if="can.autoRenew"
                    size="sm"
                    variant="ghost"
                    @click="act('set_auto_renew', !domain.autoRenew)"
                  >
                    {{ domain.autoRenew ? t('ui.domain.turn_off') : t('ui.domain.turn_on') }}
                  </AppButton>
                </dd>
              </div>
              <div class="flex items-center justify-between gap-4 py-2">
                <dt class="text-content-muted">{{ t('ui.domain.lock') }}</dt>
                <dd class="flex items-center gap-3">
                  <span>{{ domain.registrarLock ? t('ui.common.on') : t('ui.common.off') }}</span>
                  <AppButton
                    v-if="can.lock"
                    size="sm"
                    variant="ghost"
                    @click="act('set_lock', !domain.registrarLock)"
                  >
                    {{ domain.registrarLock ? t('ui.domain.turn_off') : t('ui.domain.turn_on') }}
                  </AppButton>
                </dd>
              </div>
              <div class="flex items-center justify-between gap-4 py-2 last:pb-0">
                <dt class="text-content-muted">{{ t('ui.domain.whois') }}</dt>
                <dd>{{ domain.whoisPrivacy ? t('ui.common.on') : t('ui.common.off') }}</dd>
              </div>
            </dl>
          </DetailSection>

          <DetailSection
            v-if="can.update && domain.transitions.length > 0"
            :title="t('ui.domain.change_status')"
            :description="t('ui.domain.change_status_intro')"
          >
            <form class="flex flex-col gap-3" @submit.prevent="changeStatus">
              <AppSelect
                v-model="statusForm.status"
                :label="t('ui.domain.new_status')"
                :options="domain.transitions"
                :error="statusForm.errors.status"
              />
              <AppInput
                v-model="statusForm.reason"
                :label="t('ui.domain.reason')"
                :error="statusForm.errors.reason"
                :hint="t('ui.domain.reason_hint')"
              />
              <div>
                <AppButton type="submit" variant="primary" :loading="statusForm.processing">
                  {{ t('ui.domain.apply') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>
        </aside>
      </div>
    </div>

    <!--
      The term is asked for inside the confirmation, because how many years is
      the decision — a renewal is money leaving on the customer's behalf and
      the number is the whole of what it costs.
    -->
    <AppConfirm
      v-model:open="renewing"
      level="consequential"
      :title="t('ui.domain.renew_title', { name: domain.name })"
      :description="t('ui.domain.renew_detail')"
      :confirm-label="t('ui.domain.renew_confirm')"
      :busy="renewForm.processing"
      @confirm="renew"
    >
      <AppInput
        v-model="renewForm.years"
        type="number"
        :label="t('ui.domain.years')"
        :error="renewForm.errors.years"
      />
    </AppConfirm>
  </AdminLayout>
</template>
