<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import AdminLayout from '../../../Layouts/AdminLayout.vue'

interface CategoryRow {
  id: string
  name: string
  slug: string
  articles: number
}

interface ArticleRow {
  id: string
  title: string
  body: string
  excerpt: string | null
  categoryId: string | null
  category: string | null
  visibility: string
  publishedAt: string | null
  views: number
  helpful: number
  unhelpful: number
}

const props = defineProps<{
  categories: CategoryRow[]
  articles: ArticleRow[]
  visibilities: { value: string; label: string }[]
}>()

const editing = ref<string | null>(null)
const composing = ref(false)

const categoryOptions = computed(() => [
  { value: '', label: 'No category' },
  ...props.categories.map((category) => ({ value: category.id, label: category.name })),
])

const form = useForm({
  title: '',
  excerpt: '',
  body: '',
  category_id: '',
  visibility: 'public',
  published_at: '',
  position: '0',
})

const categoryForm = useForm({ name: '', description: '' })

function forInput(value: string | null): string {
  return value === null ? '' : value.slice(0, 16)
}

function compose(): void {
  form.reset()
  form.clearErrors()
  editing.value = null
  composing.value = true
}

function edit(article: ArticleRow): void {
  form.clearErrors()
  form.title = article.title
  form.excerpt = article.excerpt ?? ''
  form.body = article.body
  form.category_id = article.categoryId ?? ''
  form.visibility = article.visibility
  form.published_at = forInput(article.publishedAt)
  composing.value = false
  editing.value = article.id
}

function cancel(): void {
  composing.value = false
  editing.value = null
}

function save(): void {
  const done = { onSuccess: cancel }

  if (editing.value === null) {
    form.post('/admin/content/articles', done)

    return
  }

  form.put(`/admin/content/articles/${editing.value}`, done)
}

function remove(article: ArticleRow): void {
  router.delete(`/admin/content/articles/${article.id}`, { preserveScroll: true })
}

function addCategory(): void {
  categoryForm.post('/admin/content/categories', {
    preserveScroll: true,
    onSuccess: () => categoryForm.reset(),
  })
}

// Two counts on their own are noise; the ratio is the thing an operator
// acts on. Shown only once enough people have answered to mean anything.
function verdict(article: ArticleRow): string {
  const answers = article.helpful + article.unhelpful

  if (answers < 5) return '—'

  return `${Math.round((article.helpful / answers) * 100)}% of ${answers}`
}
</script>

<template>
  <Head title="Knowledge base" />

  <AdminLayout
    heading="Knowledge base"
    description="The articles a customer reads instead of opening a ticket."
  >
    <div class="mb-6">
      <AppButton variant="primary" @click="compose">Write an article</AppButton>
    </div>

    <AppCard v-if="composing || editing !== null" class="mb-6 max-w-3xl">
      <div class="flex flex-col gap-4">
        <AppInput v-model="form.title" label="Title" :error="form.errors.title" />

        <AppInput
          v-model="form.excerpt"
          label="Excerpt"
          hint="Shown in search results. Taken from the opening lines if left empty."
          :error="form.errors.excerpt"
        />

        <AppTextarea
          v-model="form.body"
          label="Body"
          hint="Markdown. Everything else is escaped before it is rendered."
          :rows="14"
          :error="form.errors.body"
        />

        <div class="grid gap-4 sm:grid-cols-3">
          <AppSelect
            v-model="form.category_id"
            label="Category"
            :options="categoryOptions"
            :error="form.errors.category_id"
          />
          <AppSelect
            v-model="form.visibility"
            label="Who can read it"
            :options="visibilities"
            :error="form.errors.visibility"
          />
          <AppInput
            v-model="form.published_at"
            label="Publish at"
            type="datetime-local"
            hint="Leave empty to publish now."
            :error="form.errors.published_at"
          />
        </div>
      </div>

      <div class="mt-6 flex gap-2">
        <AppButton variant="primary" :loading="form.processing" @click="save">Save</AppButton>
        <AppButton variant="ghost" @click="cancel">Cancel</AppButton>
      </div>
    </AppCard>

    <div class="grid gap-6 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <AppTable
          v-if="articles.length > 0"
          :headers="['Article', 'Category', 'Visibility', 'Views', 'Found it useful', '']"
        >
          <tr v-for="article in articles" :key="article.id">
            <td class="px-4 py-3">
              <span class="font-medium">{{ article.title }}</span>
            </td>
            <td class="text-content-muted px-4 py-3">{{ article.category ?? '—' }}</td>
            <td class="text-content-muted px-4 py-3">{{ article.visibility }}</td>
            <td class="text-content-muted px-4 py-3 tabular-nums">{{ article.views }}</td>
            <td class="text-content-muted px-4 py-3 tabular-nums">{{ verdict(article) }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <AppButton size="sm" variant="ghost" @click="edit(article)">Edit</AppButton>
              <AppButton size="sm" variant="ghost" @click="remove(article)">Delete</AppButton>
            </td>
          </tr>
        </AppTable>

        <EmptyState
          v-else
          title="No articles yet"
          description="The questions your team answers twice belong here."
        />
      </div>

      <div>
        <AppCard title="Categories">
          <ul v-if="categories.length > 0" class="divide-line mb-4 divide-y text-sm">
            <li v-for="category in categories" :key="category.id" class="flex justify-between py-2">
              <span>{{ category.name }}</span>
              <span class="text-content-muted tabular-nums">{{ category.articles }}</span>
            </li>
          </ul>

          <div class="flex flex-col gap-3">
            <AppInput v-model="categoryForm.name" label="New category" />
            <div>
              <AppButton size="sm" :loading="categoryForm.processing" @click="addCategory">
                Add
              </AppButton>
            </div>
          </div>
        </AppCard>
      </div>
    </div>
  </AdminLayout>
</template>
