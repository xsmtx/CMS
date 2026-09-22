<script setup lang="ts">
import AppBadge from '../../Components/AppBadge.vue'
import AppCard from '../../Components/AppCard.vue'
import EmptyState from '../../Components/EmptyState.vue'
import AdminLayout from '../../Layouts/AdminLayout.vue'

/**
 * Phase 0 renders structure and real installation facts only. Inventing
 * metrics for contexts that do not exist yet would make this page lie, and
 * a dashboard that lies is worse than an empty one.
 */
defineProps<{
  environment: string
  version: string | null
}>()

const facts: { label: string; value: string }[] = []
</script>

<template>
  <AdminLayout
    heading="Dashboard"
    description="The foundation is in place. Business metrics appear here as the contexts that produce them are built."
  >
    <div class="grid gap-5 lg:grid-cols-3">
      <AppCard title="Installation" class="lg:col-span-1">
        <dl class="space-y-3 text-sm">
          <div class="flex items-center justify-between gap-4">
            <dt class="text-content-muted">Environment</dt>
            <dd>
              <AppBadge :tone="environment === 'production' ? 'success' : 'neutral'">
                {{ environment }}
              </AppBadge>
            </dd>
          </div>
          <div class="flex items-center justify-between gap-4">
            <dt class="text-content-muted">Version</dt>
            <dd class="font-mono text-[13px]">{{ version ?? 'dev' }}</dd>
          </div>
          <div
            v-for="fact in facts"
            :key="fact.label"
            class="flex items-center justify-between gap-4"
          >
            <dt class="text-content-muted">{{ fact.label }}</dt>
            <dd class="font-mono text-[13px]">{{ fact.value }}</dd>
          </div>
        </dl>
      </AppCard>

      <AppCard title="Recent activity" class="lg:col-span-2">
        <EmptyState
          title="Nothing has happened yet"
          description="Sensitive actions write an audit record as they happen. Suspensions, refunds, credential rotations and permission changes will appear here with the actor and the reason."
        />
      </AppCard>
    </div>
  </AdminLayout>
</template>
