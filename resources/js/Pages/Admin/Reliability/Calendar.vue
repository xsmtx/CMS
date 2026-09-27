<script setup lang="ts">
/**
 * A month of what happened and what is coming (§16).
 *
 * **A list of days, not a grid of boxes.** A grid is what a calendar looks
 * like; a list is what one is for. It also survives the ordinary month, in
 * which nothing happened at all — thirty empty boxes say less than one
 * sentence does, and the two days that matter are lost among them.
 *
 * The two questions this page is opened for are "was anything scheduled when
 * this broke" and "what is coming this week", which is why incidents and
 * maintenance sit on one page rather than two.
 */
import { Head, Link } from '@inertiajs/vue3'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppStatus from '../../../Components/AppStatus.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import { useTranslations } from '../../../composables/useTranslations'
import { asTone } from '../../../status'

interface CalendarIncident {
  id: string
  title: string
  reference: string
  state: string
  stateLabel: string
  stateTone: string
  startedAt: string
}

interface CalendarWindow {
  id: string
  title: string
  isPublic: boolean
  isCancelled: boolean
  startsAt: string
  endsAt: string
}

interface Day {
  date: string
  label: string
  incidents: CalendarIncident[]
  windows: CalendarWindow[]
}

defineProps<{
  month: string
  monthLabel: string
  previous: string
  next: string
  days: Day[]
}>()

const { t } = useTranslations()

function time(value: string): string {
  return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

/**
 * The time, or the word for "this started earlier".
 *
 * An entry that spans days is repeated on each of them, and printing its
 * start time on every one reads as though it began again each morning — a
 * six-day incident said "03:32" six times. The day it actually started says
 * when; the days after it say that it was already running.
 */
function startedOn(day: string, at: string): string {
  return new Date(at).toISOString().slice(0, 10) === day
    ? time(at)
    : t('reliability.calendar.ongoing')
}
</script>

<template>
  <Head :title="t('reliability.calendar.title')" />

  <AdminLayout :heading="t('ui.nav.calendar')" :description="t('reliability.calendar.intro')">
    <template #meta>
      <span>{{ monthLabel }}</span>
    </template>

    <template #actions>
      <!--
        The month is in the address bar, so a month can be linked and sent to
        somebody — which is most of why anybody opens this twice.
      -->
      <AppButton variant="secondary" :href="`/admin/reliability/calendar?month=${previous}`">
        {{ t('reliability.calendar.previous') }}
      </AppButton>
      <AppButton variant="secondary" :href="`/admin/reliability/calendar?month=${next}`">
        {{ t('reliability.calendar.next') }}
      </AppButton>
    </template>

    <EmptyState
      v-if="days.length === 0"
      icon="ok"
      :title="t('reliability.calendar.empty')"
      :description="t('reliability.calendar.empty_detail')"
      boxed
    />

    <div v-else class="flex flex-col gap-8">
      <DetailSection v-for="day in days" :key="day.date" :title="day.label">
        <ul class="divide-line-subtle divide-y">
          <li
            v-for="window in day.windows"
            :key="window.id"
            class="flex flex-wrap items-center gap-3 py-2 first:pt-0"
          >
            <span class="text-content-subtle text-chrome tabular-nums">
              {{ startedOn(day.date, window.startsAt) }}—{{ time(window.endsAt) }}
            </span>
            <AppBadge tone="neutral">{{ t('reliability.calendar.maintenance') }}</AppBadge>
            <Link
              href="/admin/reliability/maintenance"
              class="text-body text-brand font-medium hover:underline"
            >
              {{ window.title }}
            </Link>
            <AppBadge v-if="window.isCancelled" tone="neutral">
              {{ t('reliability.maintenance.states.cancelled') }}
            </AppBadge>
            <AppBadge v-else-if="window.isPublic" tone="brand">
              {{ t('reliability.incidents.published') }}
            </AppBadge>
          </li>

          <li
            v-for="incident in day.incidents"
            :key="incident.id"
            class="flex flex-wrap items-center gap-3 py-2 first:pt-0"
          >
            <span class="text-content-subtle text-chrome tabular-nums">
              {{ startedOn(day.date, incident.startedAt) }}
            </span>
            <AppStatus :tone="asTone(incident.stateTone)" :label="incident.stateLabel" />
            <Link
              :href="`/admin/reliability/incidents/${incident.id}`"
              class="text-body text-brand font-medium hover:underline"
            >
              {{ incident.title }}
            </Link>
            <span class="text-content-subtle text-chrome font-mono">{{ incident.reference }}</span>
          </li>
        </ul>
      </DetailSection>
    </div>
  </AdminLayout>
</template>
