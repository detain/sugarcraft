<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ago, oneLine } from '../lib/format'
import type { SessionSummary } from '../protocol/generated'
import { useApprovalsStore } from '../stores/approvals'
import { useConnectionStore } from '../stores/connection'
import { useLayoutStore } from '../stores/layout'
import { useNewSessionStore } from '../stores/newSession'
import { useSessionsStore } from '../stores/sessions'

const props = defineProps<{ activeId: string | null }>()

const sessions = useSessionsStore()
const approvals = useApprovalsStore()
const connection = useConnectionStore()
const layout = useLayoutStore()
const newSession = useNewSessionStore()
const router = useRouter()
const filter = ref('')
const creating = ref(false)
const error = ref<string | null>(null)

const shown = computed(() => {
  const query = filter.value.trim().toLowerCase()
  if (query === '') return sessions.items
  return sessions.items.filter((s) => `${s.name ?? ''} ${s.preview ?? ''} ${s.id}`.toLowerCase().includes(query))
})

function title(summary: SessionSummary): string {
  return summary.name || (summary.preview ? oneLine(summary.preview, 48) : summary.id)
}

/** The directory a session runs in, when it is not the server's own root. */
function elsewhere(summary: SessionSummary): string | null {
  if (!summary.root || summary.root === connection.hello?.server.root) return null
  return summary.root.split('/').filter((part) => part !== '').pop() ?? summary.root
}

function open(id: string): void {
  layout.toggleSidebar(false)
  void router.push({ name: 'session', params: { id } })
}

async function create(): Promise<void> {
  creating.value = true
  error.value = null
  try {
    const summary = await newSession.start()
    if (summary) open(summary.id)
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : String(failure)
  } finally {
    creating.value = false
  }
}

/** j / k move between sessions, as in a list view. */
function onKey(event: KeyboardEvent): void {
  if (event.key !== 'j' && event.key !== 'k') return
  const list = shown.value
  if (list.length === 0) return
  const at = list.findIndex((s) => s.id === props.activeId)
  const next = event.key === 'j' ? Math.min(list.length - 1, at + 1) : Math.max(0, at - 1)
  const target = list[next]
  if (target) open(target.id)
}
</script>

<template>
  <nav class="sidebar" aria-label="Sessions" data-testid="sidebar" @keydown="onKey">
    <div class="top">
      <h2>Sessions</h2>
      <button type="button" class="primary" :disabled="creating || !connection.connected" data-testid="new-session" @click="create">+ New</button>
    </div>
    <input v-model="filter" type="search" placeholder="Filter…" aria-label="Filter sessions">
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="sessions.loaded && sessions.items.length === 0" class="empty">No sessions yet.</p>
    <ul>
      <li v-for="summary in shown" :key="summary.id">
        <a
          :href="`#/s/${summary.id}`"
          class="session"
          :class="{ active: summary.id === activeId }"
          :aria-current="summary.id === activeId ? 'page' : undefined"
          data-testid="session-link"
          :data-session-id="summary.id"
          @click.prevent="open(summary.id)"
        >
          <span class="dot" :class="summary.status" aria-hidden="true" />
          <span class="name">{{ title(summary) }}</span>
          <span v-if="approvals.countFor(summary.id) > 0" class="badge" :title="`${approvals.countFor(summary.id)} waiting for an answer`" data-testid="ask-badge">{{ approvals.countFor(summary.id) }}</span>
          <span class="meta">{{ summary.status === 'closed' ? '' : summary.status.replace('_', ' ') }}<template v-if="summary.updatedAt"> · {{ ago(summary.updatedAt) }}</template><template v-if="elsewhere(summary)"> · <span :title="summary.root" data-testid="session-root">{{ elsewhere(summary) }}/</span></template></span>
        </a>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem;
  min-height: 0;
  overflow-y: auto;
}
.top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
h2 {
  margin: 0;
  font-size: 0.8125rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
}
input {
  font: inherit;
  padding: 0.3rem 0.5rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.session {
  display: grid;
  grid-template-columns: auto 1fr auto;
  grid-template-areas: 'dot name badge' '. meta meta';
  column-gap: 0.4rem;
  padding: 0.35rem 0.5rem;
  border-radius: 6px;
  color: var(--text);
  text-decoration: none;
  min-width: 0;
}
.session:hover {
  background: var(--line);
}
.session.active {
  background: var(--line);
  outline: 1px solid var(--accent);
}
.dot {
  grid-area: dot;
  align-self: center;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  border: 1px solid var(--muted);
}
.dot.idle {
  background: var(--muted);
}
.dot.busy {
  background: var(--accent);
  border-color: var(--accent);
}
.dot.waiting_permission {
  background: var(--warn);
  border-color: var(--warn);
}
.name {
  grid-area: name;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.badge {
  grid-area: badge;
  background: var(--warn);
  color: var(--bg);
  border-radius: 999px;
  padding: 0 0.4rem;
  font-size: 0.75rem;
  font-weight: 700;
}
.meta {
  grid-area: meta;
  font-size: 0.75rem;
  color: var(--muted);
}
.empty,
.error {
  margin: 0;
  font-size: 0.875rem;
  color: var(--muted);
}
.error {
  color: var(--error);
}
</style>
