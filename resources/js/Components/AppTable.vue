<script setup lang="ts">
/**
 * Table chrome.
 *
 * Rows are plain markup rather than a column API: every list in this product
 * needs different cell content, and a generic column abstraction would be
 * configured around rather than used.
 */
defineProps<{ headers: string[] }>()
</script>

<template>
  <div class="border-line overflow-x-auto rounded-[var(--radius-lg)] border">
    <table class="w-full text-left text-sm">
      <thead class="bg-surface-sunken text-content-muted">
        <tr>
          <th
            v-for="header in headers"
            :key="header"
            scope="col"
            class="px-4 py-2.5 text-xs font-medium whitespace-nowrap"
          >
            {{ header }}
          </th>
        </tr>
      </thead>
      <tbody class="divide-line bg-surface-raised divide-y">
        <slot />
      </tbody>
    </table>
  </div>
</template>
