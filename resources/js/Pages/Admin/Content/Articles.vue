<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

import AppButton from '../../../Components/AppButton.vue'
import AppCard from '../../../Components/AppCard.vue'
import AppConfirm from '../../../Components/AppConfirm.vue'
import AppInput from '../../../Components/AppInput.vue'
import AppSelect from '../../../Components/AppSelect.vue'
import AppTable from '../../../Components/AppTable.vue'
import AppTableRow from '../../../Components/AppTableRow.vue'
import AppTextarea from '../../../Components/AppTextarea.vue'
import DetailSection from '../../../Components/DetailSection.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import PageHeader from '../../../Components/PageHeader.vue'
import { type TableColumn } from '../../../Components/tableContext'
import { useTranslations } from '../../../composables/useTranslations'
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

const { t } = useTranslations()

const editing = ref<string | null>(null)
const composing = ref(false)

/** The row an operator has asked to delete, if any. */
const removing = ref<ArticleRow | null>(null)

const categoryOptions = computed(() => [
  { value: '', label: t('ui.articles.no_category') },
  ...props.categories.map((category) => ({ value: category.id, label: category.name })),
])

const COLUMNS: TableColumn[] = [
  { key: 'article', label: t('ui.articles.article') },
  { key: 'category', label: t('ui.articles.category') },
  { key: 'visibility', label: t('ui.articles.visibility') },
  { key: 'views', label: t('ui.articles.views'), numeric: true },
  { key: 'useful', label: t('ui.articles.useful'), numeric: true },
  { key: 'actions', label: '' },
]

/**
 * The word for a visibility, not the value behind it.
 *
 * The column printed `public` and `staff` straight from the record, which is
 * the enum the database stores rather than the thing an operator reads.
 */
function visibilityLabel(value: string): string {
  return props.visibilities.find((one) => one.value === value)?.label ?? value
}

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

/**
 * Deleting used to happen on the first click.
 *
 * Level 3, not 4: an article is a help page, not a customer's data, and
 * asking somebody to type a twenty-nine character title to remove a draft is
 * friction they will learn to resent. The reason is asked for because it is
 * **written to the audit record** — a dialog that collected one and threw it
 * away would be worse than not asking.
 *
 * An article somebody spent an afternoon writing sat behind a ghost button in
 * a row of ghost buttons, next to Edit. That is the one-click destructive
 * action §8 exists to stop; it asks now.
 */
function remove(reason: string | null): void {
  const article = removing.value

  if (article === null) return

  router.delete(`/admin/content/articles/${article.id}`, {
    data: { reason },
    preserveScroll: true,
    onFinish: () => {
      removing.value = null
    },
  })
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
  <Head :title="t('ui.articles.title')" />

  <AdminLayout :heading="t('ui.articles.title')">
    <template #header>
      <PageHeader :title="t('ui.articles.title')" :description="t('ui.articles.intro')">
        <template #meta>
          <span>
            {{
              articles.length === 1
                ? t('ui.articles.count_one', { count: articles.length })
                : t('ui.articles.count_many', { count: articles.length })
            }}
          </span>
        </template>

        <template #actions>
          <AppButton variant="primary" icon="add" @click="compose">
            {{ t('ui.articles.write') }}
          </AppButton>
        </template>
      </PageHeader>
    </template>

    <div class="flex flex-col gap-8">
      <!--
        The editor is a card because it is a surface that appears over the
        list rather than a region of it: a hairline would leave it looking
        like part of the table below.
      -->
      <AppCard
        v-if="composing || editing !== null"
        class="max-w-3xl"
        :title="editing === null ? t('ui.articles.write') : t('ui.articles.edit_article')"
      >
        <div class="flex flex-col gap-4">
          <AppInput
            v-model="form.title"
            :label="t('ui.articles.article_title')"
            :error="form.errors.title"
          />

          <AppInput
            v-model="form.excerpt"
            :label="t('ui.articles.excerpt')"
            :hint="t('ui.articles.excerpt_hint')"
            :error="form.errors.excerpt"
          />

          <AppTextarea
            v-model="form.body"
            :label="t('ui.articles.body')"
            :hint="t('ui.articles.body_hint')"
            :rows="14"
            :error="form.errors.body"
          />

          <div class="grid gap-4 sm:grid-cols-3">
            <AppSelect
              v-model="form.category_id"
              :label="t('ui.articles.category')"
              :options="categoryOptions"
              :error="form.errors.category_id"
            />
            <AppSelect
              v-model="form.visibility"
              :label="t('ui.articles.who_reads')"
              :options="visibilities"
              :error="form.errors.visibility"
            />
            <AppInput
              v-model="form.published_at"
              :label="t('ui.articles.publish_at')"
              type="datetime-local"
              :hint="t('ui.articles.publish_at_hint')"
              :error="form.errors.published_at"
            />
          </div>
        </div>

        <div class="mt-6 flex gap-2">
          <AppButton variant="primary" :loading="form.processing" @click="save">
            {{ t('ui.articles.save') }}
          </AppButton>
          <AppButton variant="ghost" @click="cancel">{{ t('ui.confirm.cancel') }}</AppButton>
        </div>
      </AppCard>

      <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
        <div class="min-w-0">
          <AppTable v-if="articles.length > 0" name="kb-articles" :columns="COLUMNS">
            <AppTableRow v-for="article in articles" :key="article.id">
              <td data-col="article" class="font-medium">{{ article.title }}</td>
              <td data-col="category" class="text-content-muted">{{ article.category ?? '—' }}</td>
              <td data-col="visibility" class="text-content-muted">
                {{ visibilityLabel(article.visibility) }}
              </td>
              <td data-col="views" class="numeric text-content-muted">{{ article.views }}</td>
              <td data-col="useful" class="numeric text-content-muted">{{ verdict(article) }}</td>
              <td data-col="actions" class="text-right whitespace-nowrap">
                <!-- Hidden until the row is hovered or focused: six rows of
                     two buttons is a wall, and the identity column is what an
                     operator is reading. -->
                <span class="row-actions inline-flex gap-1">
                  <AppButton size="sm" variant="ghost" @click="edit(article)">
                    {{ t('ui.articles.edit') }}
                  </AppButton>
                  <AppButton size="sm" variant="danger-subtle" @click="removing = article">
                    {{ t('ui.articles.delete') }}
                  </AppButton>
                </span>
              </td>
            </AppTableRow>
          </AppTable>

          <EmptyState
            v-else
            icon="document"
            :title="t('ui.articles.none')"
            :description="t('ui.articles.none_detail')"
          />
        </div>

        <aside class="min-w-0">
          <DetailSection
            :title="t('ui.articles.categories')"
            :description="t('ui.articles.categories_intro')"
          >
            <ul v-if="categories.length > 0" class="divide-line-subtle text-body mb-4 divide-y">
              <li
                v-for="category in categories"
                :key="category.id"
                class="flex justify-between gap-4 py-2 first:pt-0"
              >
                <span>{{ category.name }}</span>
                <span class="text-content-muted tabular-nums">{{ category.articles }}</span>
              </li>
            </ul>

            <form class="flex flex-col gap-3" @submit.prevent="addCategory">
              <AppInput v-model="categoryForm.name" :label="t('ui.articles.new_category')" />
              <div>
                <AppButton type="submit" size="sm" :loading="categoryForm.processing">
                  {{ t('ui.articles.add') }}
                </AppButton>
              </div>
            </form>
          </DetailSection>
        </aside>
      </div>
    </div>

    <AppConfirm
      :open="removing !== null"
      level="high-risk"
      :title="t('ui.articles.delete_title')"
      :description="t('ui.articles.delete_detail')"
      :confirm-label="t('ui.articles.delete_confirm')"
      @update:open="(value: boolean) => (removing = value ? removing : null)"
      @confirm="remove"
    />
  </AdminLayout>
</template>
