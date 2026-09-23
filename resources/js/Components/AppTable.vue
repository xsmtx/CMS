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
  <div
    class="border-line bg-surface-raised overflow-x-auto rounded-[var(--radius-lg)] border shadow-(--shadow-raised)"
  >
    <table class="data-table w-full text-left text-sm">
      <thead class="bg-surface-sunken text-content-muted">
        <tr>
          <th
            v-for="header in headers"
            :key="header"
            scope="col"
            class="px-5 py-3 text-[11px] font-semibold tracking-wide whitespace-nowrap uppercase"
          >
            {{ header }}
          </th>
        </tr>
      </thead>
      <tbody class="divide-line divide-y">
        <slot />
      </tbody>
    </table>
  </div>
</template>
