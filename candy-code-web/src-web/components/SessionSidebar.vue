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
/** WEB-1a: the session whose row action menu is open, at most one at a time. */
const menuFor = ref<string | null>(null)

const EXPORT_EXTENSIONS: Record<'markdown' | 'json' | 'text', string> = { markdown: 'md', json: 'json', text: 'txt' }

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

/** Run a menu action, landing any refusal in the sidebar's one error line. */
async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await action()
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : String(failure)
  }
}

/** WEB-1a: fork on the server and follow the new session, as a creation does. */
async function fork(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  await act(async () => {
    const forked = await sessions.fork(summary.id)
    open(forked.id)
  })
}

async function closeRow(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  await act(() => sessions.close(summary.id))
}

/** WEB-1a: delete is the one destructive row action — it asks first. */
async function removeRow(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  if (!window.confirm(`Delete session "${title(summary)}"? Its transcript goes with it.`)) return
  await act(() => sessions.remove(summary.id))
}

async function exportRow(summary: SessionSummary, format: 'markdown' | 'json' | 'text'): Promise<void> {
  menuFor.value = null
  await act(async () => {
    const result = await sessions.exportTranscript(summary.id, format)
    download(`session-${summary.id}.${EXPORT_EXTENSIONS[format]}`, result.content)
  })
}

/** Save exported text through a blob link: the browser downloads it, no navigation. */
function download(filename: string, content: string): void {
  const url = URL.createObjectURL(new Blob([content], { type: 'text/plain' }))
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

/**
 * j / k move between sessions, as in a list view — but only for keyboard
 * navigation (ISS-053): what is typed into the filter box, the composer, or
 * any other editable target stays a keystroke there, and a modified key is
 * somebody else's shortcut. The same guard PermissionCard applies to y/a/n.
 */
function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && menuFor.value !== null) {
    menuFor.value = null
    return
  }
  if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return
  const typedInto = event.target
  if (
    typedInto instanceof HTMLElement &&
    (typedInto.isContentEditable || typedInto.tagName === 'INPUT' || typedInto.tagName === 'TEXTAREA' || typedInto.tagName === 'SELECT')
  ) {
    return
  }
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
      <li v-for="summary in shown" :key="summary.id" class="row" :class="{ open: menuFor === summary.id }">
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
        <button
          type="button"
          class="more"
          :aria-label="`Actions for ${title(summary)}`"
          aria-haspopup="menu"
          :aria-expanded="menuFor === summary.id"
          :data-testid="`row-menu-${summary.id}`"
          @click="menuFor = menuFor === summary.id ? null : summary.id"
        >&#8942;</button>
        <ul v-if="menuFor === summary.id" class="menu" role="menu" data-testid="row-actions">
          <li><button type="button" role="menuitem" :data-testid="`row-fork-${summary.id}`" @click="fork(summary)">Fork</button></li>
          <li><button type="button" role="menuitem" :data-testid="`row-export-markdown-${summary.id}`" @click="exportRow(summary, 'markdown')">Export · Markdown</button></li>
          <li><button type="button" role="menuitem" :data-testid="`row-export-json-${summary.id}`" @click="exportRow(summary, 'json')">Export · JSON</button></li>
          <li><button type="button" role="menuitem" :data-testid="`row-export-text-${summary.id}`" @click="exportRow(summary, 'text')">Export · Text</button></li>
          <li v-if="summary.status !== 'closed'"><button type="button" role="menuitem" :data-testid="`row-close-${summary.id}`" @click="closeRow(summary)">Close</button></li>
          <li><button type="button" role="menuitem" class="danger" :data-testid="`row-delete-${summary.id}`" @click="removeRow(summary)">Delete</button></li>
        </ul>
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
.session {
  padding-right: 1.6rem;
}
.row {
  position: relative;
}
.more {
  position: absolute;
  right: 0.15rem;
  bottom: 0.3rem;
  padding: 0 0.35rem;
  border: 0;
  background: none;
  color: var(--muted);
  font-size: 0.875rem;
  line-height: 1.2;
  cursor: pointer;
  opacity: 0;
}
.row:hover .more,
.row.open .more,
.more:focus-visible {
  opacity: 1;
}
.menu {
  position: absolute;
  right: 0.25rem;
  top: calc(100% - 0.5rem);
  z-index: 6;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  margin: 0;
  padding: 0.25rem;
  list-style: none;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
  box-shadow: 0 0.2rem 0.6rem rgb(0 0 0 / 0.25);
}
.menu button {
  width: 100%;
  text-align: left;
  font-size: 0.8125rem;
  padding: 0.2rem 0.5rem;
}
.menu .danger {
  color: var(--error);
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
