# Page recipes

Skeletons for the four screen types, built only from existing primitives.
Copy the one that matches, keep the page's existing `<script>` logic (props,
forms, routes), and replace the template. The three reference screens are
the worked examples:

- list → `resources/js/Pages/Admin/Customers/Index.vue`
- detail → `resources/js/Pages/Admin/Customers/Show.vue`
- overview → `resources/js/Pages/Admin/Dashboard.vue`

Imports below use `@/` for brevity. The alias works (laravel-vite-plugin,
`tsconfig` paths), but every existing page uses **relative** paths
(`'../../../Components/AppTable.vue'`) — write them relative to match.

Every user-facing string goes through `t('…')` with keys in `lang/en/*.php`
and `lang/tr/*.php` (both, same keys — `tests/Unit/TranslationTest.php`
enforces it). A new group must be added to
`app/Support/View/FrontEndTranslations.php`. Generic words live in
`ui.common.*`.

## 1. List page

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, nextTick, reactive } from 'vue'

import AppButton from '@/Components/AppButton.vue'
import AppPagination from '@/Components/AppPagination.vue'
import AppStatus from '@/Components/AppStatus.vue'
import AppTable from '@/Components/AppTable.vue'
import EmptyState from '@/Components/EmptyState.vue'
import FilterBar from '@/Components/FilterBar.vue'
import FilterSelect from '@/Components/FilterSelect.vue'
import SearchInput from '@/Components/SearchInput.vue'
import { type TableColumn } from '@/Components/tableContext'
import { usePermissions } from '@/composables/usePermissions'
import { useTranslations } from '@/composables/useTranslations'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { statusTone } from '@/status'

const props = defineProps<{
  servers: { data: ServerRow[]; total: number; links: { url: string | null; label: string; active: boolean }[] }
  filters: { search?: string; status?: string }
  statuses: { value: string; label: string }[]
}>()

const { t } = useTranslations()
const { can } = usePermissions()

const form = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' })
const filtered = computed(() => form.search !== '' || form.status !== '')

function submit(): void {
  const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
  router.get('/admin/servers', query, { preserveState: true, replace: true })
}

function clear(): void {
  form.search = ''
  form.status = ''
  submit()
}

const columns = computed<TableColumn[]>(() => [
  { key: 'name', label: t('servers.list.name'), sticky: true },          // identity, never optional
  { key: 'status', label: t('servers.list.status') },                    // status second
  { key: 'ip', label: t('servers.list.ip') },
  { key: 'cpu', label: t('servers.list.cpu'), numeric: true },
  { key: 'region', label: t('servers.list.region'), optional: true, hideBelow: 'xl' },
  { key: 'created', label: t('servers.list.created'), optional: true, offByDefault: true },
  { key: 'actions', label: '' },
])
</script>

<template>
  <Head :title="t('servers.title')" />

  <AdminLayout :heading="t('servers.title')">
    <template #meta>
      <span class="tabular-nums">{{ t('servers.count', { count: servers.total }) }}</span>
      <!-- · 124 healthy · 3 warning … when the server sends the counts -->
    </template>

    <template v-if="can('infrastructure.manage')" #actions>
      <AppButton href="/admin/servers/create" variant="primary" icon="add">
        {{ t('servers.add') }}
      </AppButton>
    </template>

    <form class="mb-4" role="search" :aria-label="t('servers.filter_label')" @submit.prevent="submit">
      <FilterBar>
        <SearchInput v-model="form.search" :label="t('servers.search')" />
        <FilterSelect
          v-model="form.status"
          :label="t('servers.list.status')"
          :options="[{ value: '', label: t('ui.common.any') }, ...statuses]"
          @update:model-value="() => nextTick(submit)"
        />
        <AppButton type="submit">{{ t('ui.common.search') }}</AppButton>
        <AppButton v-if="filtered" variant="ghost" @click="clear">{{ t('ui.common.clear') }}</AppButton>
      </FilterBar>
    </form>

    <AppTable v-if="servers.data.length > 0" name="admin.servers" :columns="columns" noun="server">
      <tr v-for="server in servers.data" :key="server.id">
        <!-- No px/py classes on cells: padding comes from the density token. -->
        <td data-col="name">
          <Link :href="`/admin/servers/${server.id}`" class="font-medium hover:underline">{{ server.name }}</Link>
        </td>
        <td data-col="status"><AppStatus :tone="statusTone(server.status)" :label="server.statusLabel" /></td>
        <td data-col="ip" class="text-chrome font-mono">{{ server.ip }}</td>
        <td data-col="cpu" class="numeric">{{ server.cpu }}%</td>
        <td data-col="region" class="text-content-muted">{{ server.region }}</td>
        <td data-col="created" class="text-content-muted tabular-nums">{{ server.created }}</td>
        <td data-col="actions" class="w-0 text-right whitespace-nowrap">
          <span class="row-actions inline-flex gap-1"><!-- Edit + AppMenu ⋯ --></span>
        </td>
      </tr>
    </AppTable>

    <EmptyState
      v-else
      icon="servers"
      :title="filtered ? t('servers.empty_filtered') : t('servers.empty')"
      :description="filtered ? t('servers.empty_filtered_detail') : t('servers.empty_detail')"
    />

    <AppPagination :links="servers.links" :total="servers.total" />
  </AdminLayout>
</template>
```

Bulk actions: add `selectable :row-ids="…" v-model:selected="selected"` to
`AppTable`, wrap rows in `<AppTableRow :id :label>`, and put
`<AppSelectionBar>` in the table's `#bulk` slot (see `Admin/Invoices/Index.vue`).

## 2. Detail page

```vue
<template>
  <Head :title="server.name" />

  <AdminLayout :heading="server.name">
    <template #header>
      <PageHeader :title="server.name">
        <template #status>
          <AppStatus :tone="statusTone(server.status)" :label="server.statusLabel" />
        </template>
        <template #meta>
          <AppCopy :value="server.ip" mono />
          <span aria-hidden="true">·</span><span>{{ server.provider }}</span>
          <span aria-hidden="true">·</span><span>{{ server.location }}</span>
        </template>
        <template #actions>
          <AppButton icon="refresh">{{ t('servers.restart') }}</AppButton>   <!-- consequential → AppConfirm -->
          <AppButton :href="`/admin/servers/${server.id}/edit`" variant="primary" icon="edit">
            {{ t('ui.common.edit') }}
          </AppButton>
          <AppMenu :label="t('servers.more_actions')" icon="more"><!-- rarer actions --></AppMenu>
        </template>
      </PageHeader>
    </template>

    <AppTabs v-model="tab" :tabs="tabs" :label="t('servers.tabs_label')" query="tab">
      <template #default="{ active }">
        <div
          v-if="active === 'overview'"
          class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]"
        >
          <div class="flex min-w-0 flex-col gap-8">
            <DetailSection :title="t('servers.details')">
              <DescriptionList :items="facts">
                <template #status><AppStatus … /></template>
              </DescriptionList>
            </DetailSection>
          </div>
          <aside class="flex min-w-0 flex-col gap-8">
            <DetailSection :title="t('servers.related')"><!-- links to customer, services --></DetailSection>
            <DetailSection :title="t('servers.recent_events')"><!-- 5 latest, link to Events tab --></DetailSection>
          </aside>
        </div>
        <DetailSection v-else-if="active === 'services'" :title="…" :divided="false">
          <AppTable …/>
        </DetailSection>
      </template>
    </AppTabs>

    <!-- Always last. Entry buttons are danger-subtle; AppConfirm does the rest. -->
    <DangerZone v-if="can.terminate">
      <DangerZoneRow :title="t('servers.terminate_title')" :description="t('servers.terminate_detail')">
        <AppButton variant="danger-subtle" @click="terminating = true">{{ t('servers.terminate') }}…</AppButton>
      </DangerZoneRow>
    </DangerZone>

    <AppConfirm
      v-model:open="terminating"
      level="destructive"
      :title="t('servers.terminate_confirm_title')"
      :description="t('servers.terminate_confirm_detail')"
      :confirm-label="t('servers.terminate')"
      :phrase="server.name"
      :busy="form.processing"
      @confirm="terminate"
    />
  </AdminLayout>
</template>
```

No tabs when the resource has only one or two short facets — use stacked
`DetailSection`s instead.

## 3. Overview / dashboard

```vue
<AdminLayout :heading="t('noc.title')">
  <div class="flex flex-col gap-8">
    <DetailSection :title="t('noc.attention')"><!-- list rows with AppStatus compact + count --></DetailSection>
    <MetricStrip :items="figures" />   <!-- 3–6 figures, each with href when it filters a list -->
    <div class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
      <DetailSection :title="…"><AppBarChart hide-title … /></DetailSection>
      <DetailSection :title="…"><DescriptionList :items="…" /></DetailSection>
    </div>
    <DetailSection :title="…" :divided="false"><AppTable …/></DetailSection>
  </div>
</AdminLayout>
```

## 4. Form / settings page

```vue
<AdminLayout :heading="t('settings.title')">
  <form class="flex max-w-3xl flex-col gap-8" @submit.prevent="save">
    <DetailSection :title="t('settings.general')" :description="t('settings.general_detail')">
      <div class="grid gap-4 sm:grid-cols-2">
        <AppInput v-model="form.name" :label="t('settings.name')" :error="form.errors.name" required />
        <AppSelect v-model="form.currency" :label="t('settings.currency')" :options="currencies" />
      </div>
    </DetailSection>

    <DetailSection :title="t('settings.notifications')">
      <fieldset class="flex flex-col gap-3">
        <legend class="sr-only">{{ t('settings.notifications') }}</legend>
        <AppCheckbox v-model="form.notify" :label="t('settings.notify')" />
      </fieldset>
    </DetailSection>

    <div class="flex gap-2">
      <AppButton type="submit" variant="primary" :loading="form.processing">{{ t('crm.save') }}</AppButton>
    </div>
  </form>

  <DangerZone><!-- destructive settings only --></DangerZone>
</AdminLayout>
```

## Converting an existing page — checklist

1. Keep the `<script>`: props, `useForm`, `router` calls, permissions.
2. Header → `heading` + `#meta` (+ `#header` with `PageHeader` on detail pages).
3. `AppCard` used as grouping → `DetailSection`. Keep `AppCard` only for a
   genuinely separate surface.
4. Search/filter forms of labelled fields → `FilterBar` + `SearchInput` +
   `FilterSelect`; the long tail into `#more`.
5. Table cells: drop `px-4 py-2.5`; declare `columns` with keys, mark
   `optional`/`offByDefault`/`hideBelow`/`sticky`, add `data-col` to cells.
6. Status → `AppStatus` + `statusTone()`; missing word → `status.ts`.
7. Solid `danger` buttons in the page body → `DangerZone` row with
   `danger-subtle` + `AppConfirm` at the right level.
8. Arbitrary sizes (`text-[1.5rem]`) → `text-page` / `MetricStrip`.
9. Hard-coded strings → `t()` + both lang files.
10. Gates, then `visual-quality-review` in the browser. Tick the page in
    `docs/design/propagation.md`.
