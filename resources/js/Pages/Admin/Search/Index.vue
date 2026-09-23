<script setup lang="ts">
/**
 * Search results, grouped by what the record is.
 *
 * Eight of each and no paging on purpose: this is a way to reach a record,
 * not a report. Each group carries a link to the list that does page, with
 * the same term, so "there are more than this" has somewhere to go.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface ResultRow {
  title: string
  subtitle: string
  href: string
}

const props = defineProps<{
  term: string
  groups: { key: string; label: string; more: string; rows: ResultRow[] }[]
}>()

const term = ref(props.term)

function submit(): void {
  router.get('/admin/search', term.value === '' ? {} : { q: term.value }, {
    preserveState: true,
    replace: true,
  })
}
</script>

<template>
  <Head title="Search" />

  <AdminLayout
    heading="Search"
    description="A client, a domain, a hostname, an invoice number — whatever the call gave you."
  >
    <form class="mb-7 max-w-xl" @submit.prevent="submit">
      <div class="flex items-end gap-2">
        <div class="flex-1">
          <AppInput v-model="term" label="Search everything" />
        </div>
        <AppButton type="submit" variant="primary">Search</AppButton>
      </div>
      <p class="text-content-muted mt-2 text-xs">
        % anchors a term: Zeyn% or %nep. A whole name works.
      </p>
    </form>

    <div v-if="groups.length > 0" class="flex flex-col gap-5">
      <AppCard v-for="group in groups" :key="group.key" :title="group.label">
        <template #actions>
          <Link
            :href="group.more"
            class="text-content-muted text-xs underline-offset-4 hover:underline"
          >
            See all
          </Link>
        </template>

        <ul class="divide-line divide-y">
          <li v-for="row in group.rows" :key="row.href">
            <Link
              :href="row.href"
              class="pressable hover:bg-surface-sunken -mx-2 block rounded-[var(--radius-sm)] px-2 py-2.5 transition-colors duration-(--duration-fast)"
            >
              <span class="block text-sm font-medium">{{ row.title }}</span>
              <span v-if="row.subtitle" class="text-content-muted block text-xs">
                {{ row.subtitle }}
              </span>
            </Link>
          </li>
        </ul>
      </AppCard>
    </div>

    <EmptyState
      v-else-if="term !== ''"
      title="Nothing matches"
      description="Clients, services, domains, invoices, orders and tickets were all asked."
    />

    <EmptyState
      v-else
      title="Type something"
      description="Clients, services, domains, invoices, orders and tickets are all searched at once."
    />
  </AdminLayout>
</template>
