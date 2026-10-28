<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ago, oneLine, spendLabel } from '../lib/format'
import type { SessionSummary } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import { useApprovalsStore } from '../stores/approvals'
import { useConnectionStore } from '../stores/connection'
import { useLayoutStore } from '../stores/layout'
import { useNewSessionStore } from '../stores/newSession'
import { useSessionsStore, type SessionShareRow } from '../stores/sessions'

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
/**
 * C-SES-7.e: the tray toggle drives the store (which re-reads the server);
 * the v-model write is the async reload itself, never a local sweep.
 */
const trayShown = computed({
  get: () => sessions.showArchived,
  set: (on: boolean) => void sessions.setShowArchived(on),
})

/** C-WEB-3.e: `session.share` / `session.unshare` are write-scoped; a read-only connection sees neither item. */
const canWrite = computed(() => connection.scopes.includes('write'))

/**
 * C-WEB-3.e: the just-published link, held in this dialog state only — the
 * token is never written to localStorage or sessionStorage; the server's
 * `session.shares` listing is the record.
 */
const shareDialog = ref<{ url: string; expiresAt: number } | null>(null)
const shareCopied = ref(false)

const EXPORT_EXTENSIONS: Record<'markdown' | 'json' | 'text', string> = { markdown: 'md', json: 'json', text: 'txt' }

/**
 * C-SES-2.c: a non-empty filter lists what the SERVER ranked — typing routes
 * through `session.list {query}` (FTS body search with the LIKE degrade) via
 * the store's debounced `search()`, so matches in sessions that never made
 * the cached page are findable and body-only matches are not silently lost
 * the way the old client-side str filter dropped them.
 */
const shown = computed(() => (filter.value.trim() === '' ? sessions.items : sessions.searchResults ?? []))
watch(filter, (term) => sessions.search(term))

/** The row's spend, in the TUI's lower-bound form; empty when none is recorded. */
function spendFor(summary: SessionSummary): string {
  return summary.spentUsd === null || summary.spentUsd === undefined
    ? ''
    : spendLabel(summary.spentUsd, summary.spendUnpriced === true)
}

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

async function create(incognito = false): Promise<void> {
  creating.value = true
  error.value = null
  try {
    const summary = await newSession.start(incognito)
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

/**
 * C-SES-7.e: the archive tray's two row actions. `session.archive` /
 * `session.unarchive` are write-scoped and idempotent, and the store applies
 * the tray law to the answer — an archived row disappears while the tray is
 * hidden, exactly as the server's default listing will hide it.
 */
async function archiveRow(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  await act(() => sessions.archive(summary.id))
}

async function restoreRow(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  await act(() => sessions.unarchive(summary.id))
}

/**
 * C-WEB-3.d: stop the workspace host of a root that is not the server's own.
 * The first confirm is the ask. When the server refuses because one of the
 * host's turns is still running (`turn_running`), a second confirm retries
 * with force; anything else lands in the sidebar's one error line.
 */
async function closeHost(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  const root = summary.root
  if (!root) return
  if (!window.confirm(`Close the host for ${root}? Its sessions stay on disk; the rows leave the list.`)) return
  await act(async () => {
    try {
      await sessions.closeRoot(root)
    } catch (failure) {
      if (!(failure instanceof ProtocolError) || failure.kind !== 'turn_running') throw failure
      if (!window.confirm('A turn is running in that workspace. Stop the host anyway?')) return
      await sessions.closeRoot(root, true)
    }
  })
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

/**
 * The live share pages of one row, straight from the store's server
 * listing. Gated on the write scope like the menu item itself: revoking is
 * a write call, so a read-only connection is not shown a door it cannot open.
 */
function shareRows(summary: SessionSummary): SessionShareRow[] {
  return canWrite.value ? sessions.sharesBySession[summary.id] ?? [] : []
}

/**
 * `expiresAt` is unix seconds; a UTC ISO render keeps the stamp identical
 * wherever the page is opened (no locale- or timezone-dependent text).
 */
function expiryLabel(expiresAt: number): string {
  return new Date(expiresAt * 1000).toISOString().slice(0, 16).replace('T', ' ')
}

/**
 * C-WEB-3.e: publish a share page and show its URL. The full token arrives
 * only in this response and renders as plain interpolation below — never
 * v-html, never a stored value.
 */
async function shareRow(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  shareCopied.value = false
  await act(async () => {
    const result = await sessions.share(summary.id)
    shareDialog.value = { url: `${window.location.origin}${result.path}`, expiresAt: result.expiresAt }
  })
}

/** C-WEB-3.e: revoke one listed share by its full token; the store re-reads the listing after. */
async function unshareRow(row: SessionShareRow): Promise<void> {
  menuFor.value = null
  await act(() => sessions.unshare(row.token))
}

function closeShareDialog(): void {
  shareDialog.value = null
  shareCopied.value = false
}

/**
 * Copy the link when the browser offers a clipboard; without one (or on a
 * refusal) the URL stays on screen selectable — no claimed success.
 */
async function copyShareUrl(): Promise<void> {
  const url = shareDialog.value?.url
  if (!url || !navigator.clipboard) return
  try {
    await navigator.clipboard.writeText(url)
    shareCopied.value = true
  } catch {
    // A clipboard refusal keeps the dialog's text, selectable by hand.
  }
}

/**
 * Save exported text through a blob link: the browser downloads it, no
 * navigation. w2h M4: the URL is revoked when the next export replaces it
 * or the sidebar unmounts — revoking synchronously right after click() can
 * race the browser, which may not have read the blob yet.
 */
let downloadUrl: string | null = null

function download(filename: string, content: string): void {
  if (downloadUrl !== null) URL.revokeObjectURL(downloadUrl)
  downloadUrl = URL.createObjectURL(new Blob([content], { type: 'text/plain' }))
  const link = document.createElement('a')
  link.href = downloadUrl
  link.download = filename
  link.click()
}

onBeforeUnmount(() => {
  if (downloadUrl !== null) URL.revokeObjectURL(downloadUrl)
})

/**
 * j / k move between sessions, as in a list view — but only for keyboard
 * navigation (ISS-053): what is typed into the filter box, the composer, or
 * any other editable target stays a keystroke there, and a modified key is
 * somebody else's shortcut. The same guard PermissionCard applies to y/a/n.
 */
function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && shareDialog.value !== null) {
    closeShareDialog()
    return
  }
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
      <button type="button" class="primary" :disabled="creating || !connection.connected" data-testid="new-session" @click="create()">+ New</button>
      <!-- C-SES-7.e: the incognito entry — the same start path, ephemeral. -->
      <button type="button" :disabled="creating || !connection.connected" data-testid="new-incognito" title="Incognito: nothing is persisted once it closes" @click="create(true)">Incognito</button>
    </div>
    <input v-model="filter" type="search" placeholder="Filter…" aria-label="Filter sessions">
    <!-- C-SES-7.e: the tray toggle drives the store's re-read of the server. -->
    <label class="tray"><input v-model="trayShown" type="checkbox" data-testid="show-archived"> Show archived</label>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="sessions.loaded && sessions.items.length === 0" class="empty">No sessions yet.</p>
    <p v-else-if="filter.trim() !== '' && !sessions.searching && shown.length === 0" class="empty" data-testid="search-empty">No sessions match “{{ filter.trim() }}”.</p>
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
          <span v-if="summary.snippet" class="snippet" data-testid="session-snippet">{{ oneLine(summary.snippet, 90) }}</span>
          <span class="meta">{{ summary.status === 'closed' ? '' : summary.status.replace('_', ' ') }}<template v-if="summary.updatedAt"> · {{ ago(summary.updatedAt) }}</template><template v-if="spendFor(summary)"> · <span data-testid="session-spend">{{ spendFor(summary) }}</span></template><template v-if="elsewhere(summary)"> · <span :title="summary.root" data-testid="session-root">{{ elsewhere(summary) }}/</span></template><template v-if="summary.ephemeral === true"> · <span data-testid="session-incognito">incognito</span></template><template v-if="summary.archived === true"> · <span data-testid="session-archived">archived</span></template></span>
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
          <li v-if="canWrite"><button type="button" role="menuitem" :data-testid="`row-share-${summary.id}`" @click="shareRow(summary)">Share page…</button></li>
          <li v-for="row in shareRows(summary)" :key="row.token">
            <button type="button" role="menuitem" :data-testid="`row-unshare-${summary.id}-${row.tokenPrefix}`" @click="unshareRow(row)">Revoke share {{ row.tokenPrefix }}… · {{ expiryLabel(row.expiresAt) }}</button>
          </li>
          <li v-if="canWrite && summary.ephemeral !== true && summary.archived !== true"><button type="button" role="menuitem" :data-testid="`row-archive-${summary.id}`" @click="archiveRow(summary)">Archive</button></li>
          <li v-if="canWrite && summary.archived === true"><button type="button" role="menuitem" :data-testid="`row-restore-${summary.id}`" @click="restoreRow(summary)">Restore</button></li>
          <li v-if="summary.status !== 'closed'"><button type="button" role="menuitem" :data-testid="`row-close-${summary.id}`" @click="closeRow(summary)">Close</button></li>
          <li v-if="summary.ephemeral !== true"><button type="button" role="menuitem" class="danger" :data-testid="`row-delete-${summary.id}`" @click="removeRow(summary)">Delete</button></li>
          <li v-if="elsewhere(summary)"><button type="button" role="menuitem" :data-testid="`row-close-host-${summary.id}`" @click="closeHost(summary)">Close {{ elsewhere(summary) }}/ host</button></li>
        </ul>
      </li>
    </ul>
    <div v-if="shareDialog" class="share" role="dialog" aria-label="Share page" data-testid="share-dialog">
      <p>Share page published. Anyone with the link can read this snapshot until it expires.</p>
      <code class="share-url" data-testid="share-url">{{ shareDialog.url }}</code>
      <p class="share-expiry" data-testid="share-expiry">Expires {{ expiryLabel(shareDialog.expiresAt) }} UTC</p>
      <div class="share-actions">
        <button type="button" class="primary" data-testid="share-copy" @click="copyShareUrl">{{ shareCopied ? 'Copied' : 'Copy link' }}</button>
        <button type="button" data-testid="share-close" @click="closeShareDialog">Close</button>
      </div>
    </div>
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
.tray {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  margin: 0.25rem 0 0;
  color: var(--muted, #888);
  font-size: 0.75rem;
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
  grid-template-areas: 'dot name badge' '. snippet snippet' '. meta meta';
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
.snippet {
  grid-area: snippet;
  font-size: 0.75rem;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
.share {
  margin-top: 0.5rem;
  padding: 0.5rem;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 0.8125rem;
}
.share p {
  margin: 0 0 0.4rem;
}
.share-url {
  display: block;
  font-family: ui-monospace, monospace;
  font-size: 0.75rem;
  line-height: 1.4;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 0.3rem;
  overflow-wrap: anywhere;
}
.share-expiry {
  color: var(--muted);
}
.share-actions {
  display: flex;
  gap: 0.4rem;
}
</style>
