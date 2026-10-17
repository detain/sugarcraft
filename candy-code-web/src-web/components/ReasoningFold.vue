<script setup lang="ts">
defineProps<{ text: string; live?: boolean }>()
</script>

<template>
  <details class="reasoning" :open="live" data-testid="reasoning">
    <summary>{{ live ? 'Thinking…' : 'Thought' }}</summary>
    <pre>{{ text }}</pre>
  </details>
</template>

<style scoped>
.reasoning {
  margin: 0 0 0.4rem;
  color: var(--muted);
  font-size: 0.875rem;
}
summary {
  cursor: pointer;
  user-select: none;
}
pre {
  margin: 0.25rem 0 0;
  padding-left: 0.75rem;
  border-left: 2px solid var(--line);
  white-space: pre-wrap;
  font-family: var(--sans);
  max-height: 20rem;
  overflow-y: auto;
}
</style>
