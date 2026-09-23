<script setup lang="ts">
/**
 * Open a ticket on a customer's behalf.
 *
 * The call that starts "I rang about this last week" ends with an operator
 * typing it into the queue. It goes through the same use case the portal
 * uses, so the clock, the notification and the department's rules are
 * identical — a second path that opened tickets differently would drift,
 * and the drift would be invisible until somebody measured response times.
 *
 * The client is chosen by searching, not from a select: an installation
 * with four thousand customers cannot put them in a dropdown.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

defineProps<{
  departments: { value: string; label: string }[]
  priorities: { value: string; label: string }[]
}>()

interface Candidate {
  title: string
  subtitle: string
  href: string
}

const form = useForm({
  customer_id: '',
  department_id: '',
  subject: '',
  body: '',
  priority: 'normal',
})

const query = ref('')
const chosen = ref<Candidate | null>(null)
const candidates = ref<Candidate[]>([])
const searching = ref(false)

let timer: ReturnType<typeof setTimeout> | undefined

/**
 * Debounced, because a keystroke is not a question. The same endpoint the
 * header search uses, asked for its clients group only.
 */
watch(query, (value) => {
  if (timer) clearTimeout(timer)

  if (value.trim().length < 2) {
    candidates.value = []
    return
  }

  timer = setTimeout(() => {
    searching.value = true

    router.get(
      '/admin/search',
      { q: value },
      {
        only: ['groups'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: (page) => {
          const groups = (page.props.groups ?? []) as { key: string; rows: Candidate[] }[]

          candidates.value = groups.find((group) => group.key === 'clients')?.rows ?? []
        },
        onFinish: () => (searching.value = false),
      },
    )
  }, 250)
})

function choose(candidate: Candidate): void {
  chosen.value = candidate
  // The search result carries the link, and the id is the last segment of
  // it — no second endpoint needed to turn a row into an id.
  form.customer_id = candidate.href.split('/').pop() ?? ''
  candidates.value = []
  query.value = candidate.title
}

function submit(): void {
  form.post('/admin/support')
}
</script>

<template>
  <Head title="Open new ticket" />

  <AdminLayout
    heading="Open New Ticket"
    description="Opened on the account rather than on one person, so the reply reaches whoever the customer asked it to."
  >
    <form class="flex flex-col gap-5" @submit.prevent="submit">
      <AppCard title="Client">
        <div class="grid max-w-xl gap-4">
          <AppInput
            v-model="query"
            label="Find a client"
            hint="Name, company or email. % anchors a term."
            :error="form.errors.customer_id"
          />

          <ul
            v-if="candidates.length > 0"
            class="border-line divide-line divide-y rounded-[var(--radius-md)] border"
          >
            <li v-for="candidate in candidates" :key="candidate.href">
              <button
                type="button"
                class="pressable hover:bg-surface-sunken block w-full px-3 py-2 text-left"
                @click="choose(candidate)"
              >
                <span class="block text-sm font-medium">{{ candidate.title }}</span>
                <span v-if="candidate.subtitle" class="text-content-muted block text-xs">
                  {{ candidate.subtitle }}
                </span>
              </button>
            </li>
          </ul>

          <p v-else-if="searching" class="text-content-muted text-xs">Searching…</p>

          <p v-if="chosen" class="text-content-muted text-xs">
            Opening for <span class="text-content font-medium">{{ chosen.title }}</span>
          </p>
        </div>
      </AppCard>

      <AppCard title="The ticket">
        <div class="grid max-w-xl gap-5">
          <div class="grid gap-5 sm:grid-cols-2">
            <AppSelect
              v-model="form.department_id"
              label="Department"
              :options="[{ value: '', label: 'Unassigned' }, ...departments]"
              :error="form.errors.department_id"
            />
            <AppSelect
              v-model="form.priority"
              label="Priority"
              :options="priorities"
              hint="Priority scales the department's promise; it does not replace it."
            />
          </div>

          <AppInput v-model="form.subject" label="Subject" :error="form.errors.subject" required />
          <AppTextarea v-model="form.body" label="Message" :rows="10" :error="form.errors.body" />
        </div>
      </AppCard>

      <div class="flex gap-2">
        <AppButton
          type="submit"
          variant="primary"
          :loading="form.processing"
          :disabled="form.customer_id === ''"
        >
          Open ticket
        </AppButton>
        <AppButton href="/admin/support" variant="ghost">Cancel</AppButton>
      </div>
    </form>
  </AdminLayout>
</template>
