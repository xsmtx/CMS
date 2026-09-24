<script setup lang="ts">
/**
 * The list of things somebody meant to come back to.
 *
 * Three states, an optional date and an optional name. Anything more would
 * be a worse ticket system competing with the real one — and what this
 * replaces is a sticky note on a monitor.
 */
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

import AppBadge from '../../../Components/AppBadge.vue'
import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppStatus, { type StatusTone } from '../../../Components/AppStatus.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface TodoRow {
  id: string
  title: string
  body: string | null
  status: string
  statusLabel: string
  dueOn: string | null
  overdue: boolean
  assignedTo: string | null
  assignee: string | null
}

const props = defineProps<{
  items: TodoRow[]
  filters: { done: boolean }
  statuses: { value: string; label: string }[]
  staff: { value: string; label: string }[]
}>()

const editing = ref<string | null>(null)

const form = useForm({
  title: '',
  body: '',
  due_on: '',
  assigned_to: '',
  status: 'pending',
})

function startNew(): void {
  editing.value = 'new'
  form.reset()
  form.clearErrors()
}

function startEdit(item: TodoRow): void {
  editing.value = item.id
  form.title = item.title
  form.body = item.body ?? ''
  form.due_on = item.dueOn ?? ''
  form.assigned_to = item.assignedTo ?? ''
  form.status = item.status
  form.clearErrors()
}

function submit(): void {
  const done = { onSuccess: () => (editing.value = null), preserveScroll: true }

  if (editing.value === 'new') {
    form.post('/admin/todo', done)
    return
  }

  form.put(`/admin/todo/${editing.value}`, done)
}

/**
 * The one-click path, because a list whose commonest action costs a form is
 * a list nobody keeps up to date.
 */
function markDone(item: TodoRow): void {
  router.put(
    `/admin/todo/${item.id}`,
    {
      title: item.title,
      body: item.body ?? '',
      due_on: item.dueOn ?? '',
      assigned_to: item.assignedTo ?? '',
      status: item.status === 'done' ? 'pending' : 'done',
    },
    { preserveScroll: true },
  )
}

function remove(item: TodoRow): void {
  router.delete(`/admin/todo/${item.id}`, { preserveScroll: true })
}

function toggleDone(): void {
  router.get('/admin/todo', props.filters.done ? {} : { done: '1' }, {
    preserveState: true,
    replace: true,
  })
}

function formatDate(value: string | null): string {
  return value === null ? 'No date' : new Date(value).toLocaleDateString()
}

/**
 * Kept local rather than `statusTone(item.status)`: an overdue item is
 * critical whatever its status word says, and a pending item with no date
 * is simply waiting, not something to look at.
 */
function tone(item: TodoRow): StatusTone {
  if (item.status === 'done') return 'healthy'
  if (item.overdue) return 'critical'
  if (item.status === 'in_progress') return 'info'
  return 'neutral'
}
</script>

<template>
  <Head title="Todo list" />

  <AdminLayout
    heading="Todo List"
    description="Things somebody meant to come back to. Not a ticket system: three states, a date if it has one, a name if it needs one."
  >
    <div class="mb-6 flex flex-wrap items-center gap-2.5">
      <AppButton v-if="editing === null" variant="primary" @click="startNew">Add item</AppButton>
      <AppButton variant="ghost" @click="toggleDone">
        {{ filters.done ? 'Hide done' : 'Show done' }}
      </AppButton>
    </div>

    <AppCard
      v-if="editing !== null"
      class="mb-6"
      :title="editing === 'new' ? 'New item' : 'Edit item'"
    >
      <form class="grid max-w-xl gap-5" @submit.prevent="submit">
        <AppInput v-model="form.title" label="What" :error="form.errors.title" required />
        <AppTextarea v-model="form.body" label="Notes" :rows="4" :error="form.errors.body" />
        <div class="grid gap-5 sm:grid-cols-2">
          <AppInput v-model="form.due_on" type="date" label="Due" :error="form.errors.due_on" />
          <AppSelect
            v-model="form.assigned_to"
            label="Assigned to"
            :options="[{ value: '', label: 'Nobody in particular' }, ...staff]"
          />
        </div>
        <AppSelect
          v-if="editing !== 'new'"
          v-model="form.status"
          label="Status"
          :options="statuses"
        />

        <div class="flex gap-2">
          <AppButton type="submit" variant="primary" :loading="form.processing">Save</AppButton>
          <AppButton type="button" variant="ghost" @click="editing = null">Cancel</AppButton>
        </div>
      </form>
    </AppCard>

    <div v-if="items.length > 0" class="flex flex-col gap-3">
      <div
        v-for="item in items"
        :key="item.id"
        class="border-line bg-surface-primary flex flex-wrap items-start justify-between gap-4 rounded-md border px-5 py-4 shadow-(--shadow-raised)"
      >
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <span
              class="text-body font-medium"
              :class="item.status === 'done' ? 'line-through' : ''"
            >
              {{ item.title }}
            </span>
            <AppStatus :tone="tone(item)" :label="item.statusLabel" />
            <AppBadge v-if="item.assignee" tone="neutral">{{ item.assignee }}</AppBadge>
          </div>
          <p v-if="item.body" class="text-content-muted text-body mt-1.5 max-w-[70ch]">
            {{ item.body }}
          </p>
          <p class="text-content-muted text-chrome mt-1" :class="item.overdue ? 'text-danger' : ''">
            {{ formatDate(item.dueOn) }}
          </p>
        </div>

        <div class="flex gap-2">
          <AppButton size="sm" @click="markDone(item)">
            {{ item.status === 'done' ? 'Reopen' : 'Done' }}
          </AppButton>
          <AppButton size="sm" variant="ghost" @click="startEdit(item)">Edit</AppButton>
          <AppButton size="sm" variant="ghost" @click="remove(item)">Delete</AppButton>
        </div>
      </div>
    </div>

    <EmptyState
      v-else
      title="Nothing on the list"
      description="Write down the thing you will otherwise remember at two in the morning."
    />
  </AdminLayout>
</template>
