<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ago, oneLine } from '../lib/format'
import { useApprovalsStore } from '../stores/approvals'
import { useConnectionStore } from '../stores/connection'
import { useNewSessionStore } from '../stores/newSession'
import { useSessionsStore } from '../stores/sessions'

const version = __APP_VERSION__
const connection = useConnectionStore()
const sessions = useSessionsStore()
const approvals = useApprovalsStore()
const newSession = useNewSessionStore()
const router = useRouter()
const creating = ref(false)
const error = ref<string | null>(null)

const recent = computed(() => sessions.items.slice(0, 8))

async function create(): Promise<void> {
  creating.value = true
  error.value = null
  try {
    const summary = await newSession.start()
    if (summary) void router.push({ name: 'session', params: { id: summary.id } })
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : String(failure)
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <section class="dashboard" data-testid="dashboard">
    <h1>SugarCrush</h1>
    <p v-if="connection.hello?.server.root" class="root">
      Serving <code>{{ connection.hello.server.root }}</code>
    </p>
    <p v-if="approvals.total > 0" class="asks">{{ approvals.total }} question{{ approvals.total === 1 ? '' : 's' }} waiting for an answer.</p>
    <p>
      <button type="button" class="primary" :disabled="creating || !connection.connected" data-testid="dashboard-new" @click="create">Start a new session</button>
    </p>
    <p v-if="error" class="error">{{ error }}</p>
    <template v-if="recent.length > 0">
      <h2>Recent</h2>
      <ul>
        <li v-for="summary in recent" :key="summary.id">
          <RouterLink :to="{ name: 'session', params: { id: summary.id } }">{{ summary.name || oneLine(summary.preview ?? summary.id, 80) }}</RouterLink>
          <span class="meta"> · {{ summary.status.replace('_', ' ') }}<template v-if="summary.updatedAt"> · {{ ago(summary.updatedAt) }}</template></span>
        </li>
      </ul>
    </template>
    <p class="version">sugar-crush-web {{ version }}<template v-if="connection.hello"> · server {{ connection.hello.server.version }}</template></p>
  </section>
</template>

<style scoped>
.dashboard {
  padding: 1.5rem 1rem;
  max-width: 48rem;
  margin: 0 auto;
}
h1 {
  margin: 0 0 0.75rem;
  font-size: 1.5rem;
}
h2 {
  font-size: 1rem;
  margin: 1.5rem 0 0.5rem;
}
p {
  margin: 0 0 0.75rem;
  line-height: 1.5;
}
ul {
  padding-left: 1.2rem;
}
li {
  margin: 0.25rem 0;
}
a {
  color: var(--accent);
}
.meta,
.version,
.root {
  color: var(--muted);
  font-size: 0.875rem;
}
.asks {
  color: var(--warn);
}
.error {
  color: var(--error);
}
code {
  font-family: var(--mono);
  background: var(--panel);
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
}
</style>
