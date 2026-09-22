<script setup lang="ts">
import AppCard from '../../Components/AppCard.vue'
import EmptyState from '../../Components/EmptyState.vue'
import ClientLayout from '../../Layouts/ClientLayout.vue'

defineProps<{
  services: unknown[]
  invoices: unknown[]
}>()
</script>

<template>
  <ClientLayout
    heading="Overview"
    description="Your services, invoices and open requests in one place."
  >
    <div class="grid gap-5 sm:grid-cols-2">
      <AppCard title="Services">
        <EmptyState
          v-if="services.length === 0"
          title="No active services"
          description="Hosting plans, servers and other products you order will be listed here with their renewal dates."
        />
      </AppCard>

      <AppCard title="Invoices">
        <EmptyState
          v-if="invoices.length === 0"
          title="No invoices yet"
          description="Invoices are issued when you place an order and again before each renewal. Paid and unpaid ones both stay here."
        />
      </AppCard>
    </div>
  </ClientLayout>
</template>
