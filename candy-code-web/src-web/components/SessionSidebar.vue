<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ago, oneLine, spendLabel } from '../lib/format'
import { t } from '../lib/i18n'
import type { SessionSummary } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import { useSlowPending } from '../lib/slowPending'
import { useDialog } from '../lib/useDialog'
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
/** MA-36: which button was pressed, so only that one takes the busy verb. */
const createKind = ref<'plain' | 'incognito'>('plain')
const slowCreating = useSlowPending(() => creating.value)
const error = ref<string | null>(null)
/** WEB-1a: the session whose row action menu is open, at most one at a time. */
const menuFor = ref<string | null>(null)
/**
 * CW-EU-01 (web seat for session.import): the file picker is driven by the
 * Import button through this hidden input — the input itself is never in
 * the tab order, and its value is cleared after every attempt so re-picking
 * the same file still fires change.
 */
const fileInput = ref<HTMLInputElement | null>(null)
const importing = ref(false)

/**
 * MA-32: the menu had no dismissal beyond its own toggle — a click anywhere else left it
 * open (and open menus swallow the row underneath them visually). The document listens
 * while a menu is up; clicks inside the open row (trigger or items) belong to that row's
 * own handlers. Test specs dispatch `click` without pointerdown, so the pinned menu
 * flows in the specs are untouched.
 */
function onDocPointer(event: PointerEvent): void {
  if (menuFor.value === null) return
  const target = event.target
  if (target instanceof Element && target.closest('.row.open') !== null) return
  menuFor.value = null
}

/**
 * MA-32: opening a menu by keyboard or click lands the focus on its first item, and the
 * arrows/Tab walk it (Escape already closes, from the sidebar's own onKey, which stands
 * down from j/k while a menu is open).
 */
async function revealMenu(id: string): Promise<void> {
  await nextTick()
  const menu = document.querySelector<HTMLElement>(`[data-menu-owner="${id}"]`)
  menu?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
}

watch(menuFor, (id) => {
  if (id !== null) void revealMenu(id)
})

function onMenuKey(event: KeyboardEvent): void {
  const menu = event.currentTarget
  if (!(menu instanceof HTMLElement)) return
  const items = [...menu.querySelectorAll<HTMLElement>('[role="menuitem"]')]
  if (items.length === 0) return
  const at = items.indexOf(document.activeElement as HTMLElement)
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    items[(at + 1) % items.length]?.focus()
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    items[(at - 1 + items.length) % items.length]?.focus()
  } else if (event.key === 'Home') {
    event.preventDefault()
    items[0]?.focus()
  } else if (event.key === 'End') {
    event.preventDefault()
    items[items.length - 1]?.focus()
  } else if (event.key === 'Tab') {
    // Tabbing out of the menu closes it, as WAI-ARIA menu pattern expects; focus moves on.
    menuFor.value = null
  }
}
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
/** MA-33: the dialog itself and its copy button, for the reveal after publishing. */
const sharePanel = ref<HTMLElement | null>(null)
const shareCopyButton = ref<HTMLElement | null>(null)
// CW-UX-07: the share dialog carries the modal spine now — Tab trap, opener
// restore on close, focus-on-open matching MA-33's copy-button landing.
// Escape stays owned by the sidebar's own onKey (the nav-level handler the
// pinned menu/share flows already answer).
const dialog = useDialog({
  panel: sharePanel,
  open: () => shareDialog.value !== null,
  handleEscape: false,
  focusOn: () => shareCopyButton.value ?? undefined,
})

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
  if (creating.value) return
  creating.value = true
  createKind.value = incognito ? 'incognito' : 'plain'
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

/**
 * CW-EU-01/CWPM-3 (web seat for session.import): upload a transcript file
 * and follow the minted session, exactly as a creation does. The server
 * detects the source format from the content and idempotency rides the
 * protocol lane's keying; a refusal lands in the sidebar's one error line.
 */
function pickTranscript(): void {
  fileInput.value?.click()
}

async function importTranscript(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || importing.value) return
  importing.value = true
  error.value = null
  try {
    const content = await file.text()
    const summary = await connection.request('session.import', { content, name: file.name })
    if (summary) open(summary.id)
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : String(failure)
  } finally {
    importing.value = false
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
  if (!window.confirm(t('web.chrome.sidebar.close_host_confirm', { root }))) return
  await act(async () => {
    try {
      await sessions.closeRoot(root)
    } catch (failure) {
      if (!(failure instanceof ProtocolError) || failure.kind !== 'turn_running') throw failure
      if (!window.confirm(t('web.chrome.sidebar.close_host_turn_confirm'))) return
      await sessions.closeRoot(root, true)
    }
  })
}

/** WEB-1a: delete is the one destructive row action — it asks first. */
async function removeRow(summary: SessionSummary): Promise<void> {
  menuFor.value = null
  if (!window.confirm(t('web.chrome.sidebar.delete_confirm', { name: title(summary) }))) return
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
    // MA-33: the token appears at the bottom of a scrollable list and the sidebar never
    // moves — a share that scrolled off-screen silently fails to exist. Reveal it and
    // take focus with it (role=dialog + aria-label the block already carries).
    await nextTick()
    // Optional-call: a missing scrollIntoView (some DOM shims) must not break the share
    // itself — the reveal is a progressive enhancement on top of the emitted dialog.
    sharePanel.value?.scrollIntoView?.({ block: 'nearest' })
    shareCopyButton.value?.focus()
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

onMounted(() => document.addEventListener('pointerdown', onDocPointer))
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocPointer)
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
  if (menuFor.value !== null) return // The menu owns the keys now; see onMenuKey.
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
  <nav class="sidebar" :aria-label="t('web.chrome.sidebar.heading')" data-testid="sidebar" @keydown="onKey">
    <div class="top">
      <h2>{{ t('web.chrome.sidebar.heading') }}</h2>
      <!-- MA-36: the pressed button takes the busy verb after a short grace, and a
           disconnected server says why on the button instead of silently greying it. -->
      <button
        type="button"
        class="primary"
        :disabled="creating || !connection.connected"
        :aria-busy="creating"
        :title="connection.connected ? undefined : t('web.chrome.banner.no_answer')"
        data-testid="new-session"
        @click="create()"
      >
        {{ createKind === 'plain' && slowCreating ? t('web.chrome.about.loading') : t('web.chrome.sidebar.new') }}
      </button>
      <button type="button" :disabled="creating || !connection.connected" :aria-busy="creating" :title="!connection.connected ? t('web.chrome.banner.no_answer') : t('web.chrome.sidebar.incognito_hint')" data-testid="new-incognito" @click="create(true)">{{ createKind === 'incognito' && slowCreating ? t('web.chrome.about.loading') : t('web.chrome.sidebar.incognito_button') }}</button>
      <!-- CW-EU-01: the import seat, mirroring the create pair's busy/disabled
           discipline; session.import is write-scoped, so a read-only
           connection is not shown a door it cannot open. -->
      <input ref="fileInput" type="file" accept=".json,.md,.txt" class="pick-file" data-testid="import-file" @change="importTranscript">
      <button v-if="canWrite" type="button" :disabled="importing || !connection.connected" :aria-busy="importing" :title="!connection.connected ? t('web.chrome.banner.no_answer') : t('web.chrome.sidebar.import_hint')" data-testid="import-session" @click="pickTranscript">{{ t('web.chrome.sidebar.import') }}</button>
    </div>
    <input v-model="filter" type="search" :placeholder="t('web.chrome.sidebar.filter_placeholder')" :aria-label="t('web.chrome.sidebar.filter_aria')">
    <!-- C-SES-7.e: the tray toggle drives the store's re-read of the server. -->
    <label class="tray"><input v-model="trayShown" type="checkbox" data-testid="show-archived"> {{ t('web.chrome.sidebar.show_archived') }}</label>
    <p v-if="error" class="error" role="alert" data-testid="sidebar-error">{{ error }}</p>
    <p v-if="sessions.loaded && sessions.items.length === 0" class="empty">{{ t('web.chrome.sidebar.empty') }}</p>
    <p v-else-if="filter.trim() !== '' && !sessions.searching && shown.length === 0" class="empty" data-testid="search-empty">{{ t('web.chrome.sidebar.search_empty', { query: filter.trim() }) }}</p>
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
          <span v-if="approvals.countFor(summary.id) > 0" class="badge" :title="t('web.chrome.sidebar.badge_title', { n: approvals.countFor(summary.id) })" data-testid="ask-badge">{{ approvals.countFor(summary.id) }}</span>
          <span v-if="summary.snippet" class="snippet" data-testid="session-snippet">{{ oneLine(summary.snippet, 90) }}</span>
          <span class="meta">{{ summary.status === 'closed' ? '' : summary.status.replace('_', ' ') }}<template v-if="summary.updatedAt"> · {{ ago(summary.updatedAt) }}</template><template v-if="spendFor(summary)"> · <span data-testid="session-spend">{{ spendFor(summary) }}</span></template><template v-if="summary.branch"> · <span :title="t('web.chrome.sidebar.branch_title', { branch: summary.branch })" data-testid="session-branch">⎇ {{ summary.branch }}</span></template><template v-if="summary.pr"> · <span :title="t('web.chrome.sidebar.pr_title', { number: summary.pr.number, state: summary.pr.state, checks: summary.pr.checks })" data-testid="session-pr">#{{ summary.pr.number }}<span class="pr-dot" :class="'is-' + summary.pr.checks" aria-hidden="true" /></span></template><template v-if="elsewhere(summary)"> · <span :title="summary.root" data-testid="session-root">{{ elsewhere(summary) }}/</span></template><template v-if="summary.ephemeral === true"> · <span data-testid="session-incognito">{{ t('web.chrome.sidebar.incognito_label') }}</span></template><template v-if="summary.archived === true"> · <span data-testid="session-archived">{{ t('web.chrome.sidebar.archived_label') }}</span></template></span>
        </a>
        <button
          type="button"
          class="more"
          :aria-label="t('web.chrome.sidebar.actions_aria', { name: title(summary) })"
          aria-haspopup="menu"
          :aria-expanded="menuFor === summary.id"
          :data-testid="`row-menu-${summary.id}`"
          @click="menuFor = menuFor === summary.id ? null : summary.id"
        >&#8942;</button>
        <ul v-if="menuFor === summary.id" class="menu" role="menu" data-testid="row-actions" :data-menu-owner="summary.id" @keydown="onMenuKey">
          <li><button type="button" role="menuitem" :data-testid="`row-fork-${summary.id}`" @click="fork(summary)">{{ t('web.chrome.sidebar.fork') }}</button></li>
          <li><button type="button" role="menuitem" :data-testid="`row-export-markdown-${summary.id}`" @click="exportRow(summary, 'markdown')">{{ t('web.chrome.sidebar.export_markdown') }}</button></li>
          <li><button type="button" role="menuitem" :data-testid="`row-export-json-${summary.id}`" @click="exportRow(summary, 'json')">{{ t('web.chrome.sidebar.export_json') }}</button></li>
          <li><button type="button" role="menuitem" :data-testid="`row-export-text-${summary.id}`" @click="exportRow(summary, 'text')">{{ t('web.chrome.sidebar.export_text') }}</button></li>
          <li v-if="canWrite"><button type="button" role="menuitem" :data-testid="`row-share-${summary.id}`" @click="shareRow(summary)">{{ t('web.chrome.sidebar.share') }}</button></li>
          <li v-for="row in shareRows(summary)" :key="row.token">
            <button type="button" role="menuitem" :data-testid="`row-unshare-${summary.id}-${row.tokenPrefix}`" @click="unshareRow(row)">{{ t('web.chrome.sidebar.revoke_share', { prefix: row.tokenPrefix, when: expiryLabel(row.expiresAt) }) }}</button>
          </li>
          <li v-if="canWrite && summary.ephemeral !== true && summary.archived !== true"><button type="button" role="menuitem" :data-testid="`row-archive-${summary.id}`" @click="archiveRow(summary)">{{ t('web.chrome.sidebar.archive') }}</button></li>
          <li v-if="canWrite && summary.archived === true"><button type="button" role="menuitem" :data-testid="`row-restore-${summary.id}`" @click="restoreRow(summary)">{{ t('web.chrome.sidebar.restore') }}</button></li>
          <li v-if="summary.status !== 'closed'"><button type="button" role="menuitem" :data-testid="`row-close-${summary.id}`" @click="closeRow(summary)">{{ t('web.chrome.sidebar.close') }}</button></li>
          <li v-if="summary.ephemeral !== true"><button type="button" role="menuitem" class="danger" :data-testid="`row-delete-${summary.id}`" @click="removeRow(summary)">{{ t('web.chrome.sidebar.delete') }}</button></li>
          <li v-if="elsewhere(summary)"><button type="button" role="menuitem" :data-testid="`row-close-host-${summary.id}`" @click="closeHost(summary)">{{ t('web.chrome.sidebar.close_host', { dir: elsewhere(summary) ?? '' }) }}</button></li>
        </ul>
      </li>
    </ul>
    <!-- M-20: the listing walk caps at sessions.listCap rows; say so loudly
         instead of letting the next-oldest sessions be invisibly nonexistent. -->
    <p v-if="sessions.capped" class="capped" role="status" data-testid="sessions-capped">{{ t('web.chrome.sidebar.capped', { n: sessions.listCap }) }}</p>
    <div v-if="shareDialog" ref="sharePanel" class="share" role="dialog" aria-modal="true" :aria-label="t('web.chrome.sidebar.share_dialog_aria')" data-testid="share-dialog" @keydown="dialog.onKeydown">
      <p>{{ t('web.chrome.sidebar.share_published') }}</p>
      <code class="share-url" data-testid="share-url">{{ shareDialog.url }}</code>
      <p class="share-expiry" data-testid="share-expiry">{{ t('web.chrome.sidebar.share_expiry', { when: expiryLabel(shareDialog.expiresAt) }) }}</p>
      <div class="share-actions">
        <button ref="shareCopyButton" type="button" class="primary" data-testid="share-copy" @click="copyShareUrl">{{ shareCopied ? t('web.chrome.sidebar.revoked') : t('web.chrome.sidebar.share_copy') }}</button>
        <button type="button" data-testid="share-close" @click="closeShareDialog">{{ t('web.chrome.sidebar.close') }}</button>
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
/* CW-EU-01: the file input is a programmatic trigger for the OS picker —
   the Import button carries the label and focus, the input carries neither. */
.pick-file {
  display: none;
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
.pr-dot {
  display: inline-block;
  width: 0.5em;
  height: 0.5em;
  border-radius: 50%;
  margin-left: 0.2em;
}
.pr-dot.is-pass {
  background: var(--ok);
}
.pr-dot.is-fail {
  background: var(--error);
}
.pr-dot.is-pending {
  background: var(--warn);
}
.empty,
.capped,
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
