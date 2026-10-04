<script setup lang="ts">
import { RouterView } from 'vue-router'
import { useConnectionStore } from './stores/connection'

const connection = useConnectionStore()
</script>

<template>
  <header class="topbar">
    <span class="brand">&#9670; SugarCrush</span>
    <span class="status" :data-status="connection.status">{{ connection.statusLabel }}</span>
  </header>
  <main class="content">
    <RouterView />
  </main>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--line);
  background: var(--panel);
}
.brand {
  font-weight: 700;
  color: var(--accent);
}
.status {
  font-size: 0.875rem;
  color: var(--muted);
}
.status[data-status='connected'] {
  color: var(--ok);
}
.content {
  padding: 1.5rem 1rem;
  max-width: 64rem;
  margin: 0 auto;
}
</style>
