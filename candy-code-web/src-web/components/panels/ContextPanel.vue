<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { t } from '../../lib/i18n'
import { useDialog } from '../../lib/useDialog'
import { useConnectionStore } from '../../stores/connection'
import { useContextSourcesStore, type ContextSourcesResult } from '../../stores/contextSources'
import { ProtocolError } from '../../protocol/jsonrpc'

/**
 * WEB-3a: the session's context panel over `context.sources` — what the next
 * request is built from, as structure instead of `/context`'s text rows.
 *
 * SECURITY POSTURE: every server path, reason and detail renders through a
 * plain `{{ }}` span — no MessageMarkdown anywhere near it (the ChangesPanel
 * rule from WEB-1b: server-supplied strings are data, never markup). MCP
 * rows carry name and transport only; the server never sends the URL.
 *
 * WEB-3b: the trust prompts ride the same answer (`sources.trusts`). Their
 * verdict buttons call `context.trust` on the Approve scope; the root and
 * reason stay plain spans under the same rule. C-SEC-5.b: the answer is a
 * LIST — one row per pending grant door of the launch census (mcp, mcp-pin,
 * hooks, commands, settings) — and each row is answered on its own `askId`;
 * settling one door never settles another.
 *
 * WEB-3b review MINOR-3: the session-tier wording speaks the truth of the
 * frozen-trust-roster law (`trustedRootsForThisProcess`): a session grant
 * only silences further prompts for this process — nothing it does can
 * start servers in the current run, only a saved (user-tier) trust does,
 * at next launch. "Trust (this run)" read as activation; it is not.
 */
const props = defineProps<{ sessionId: string }>()

const context = useContextSourcesStore()
const connection = useConnectionStore()

const canAnswer = computed(() => connection.scopes.includes('approve'))
const busy = ref(false)
const trustError = ref<string | null>(null)

async function answer(askId: string, decision: 'grant' | 'reject', remember: 'session' | 'user'): Promise<void> {
  if (busy.value) return
  busy.value = true
  trustError.value = null
  try {
    // C-SEC-5.b: the verdict names the row it answers — settling one door
    // never settles the others of the same measurement.
    const settled = await context.respondTrust(askId, decision, remember)
    if (!settled) trustError.value = t('web.panels.ctx_trust_settled_elsewhere')
  } catch (failure) {
    // C-SEC-4: a refused write is not a lost prompt — the server kept the
    // record `asked`, so say what failed and leave the buttons usable.
    if (failure instanceof ProtocolError && failure.kind === 'trust_write_failed') {
      trustError.value = t('web.panels.ctx_trust_write_error')
    } else {
      trustError.value = failure instanceof Error ? failure.message : String(failure)
    }
  } finally {
    busy.value = false
  }
}

/**
 * C-CTX-3.c: the write side of the panel. Each listed row answers to Edit;
 * the save spells `context.instructionWrite` with the byte count THIS listing
 * measured, so a file someone else changed since is refused
 * (`instruction_changed`) instead of clobbered. The textarea starts empty on
 * purpose: the protocol never sends file content to the browser, and the
 * modal says so — saving replaces the file with what you type. A landed write
 * announces `context.changed` (reason "instructions") and the store's
 * debounced reload re-measures; on a conflict the panel reloads at once so
 * the next Edit carries fresh bytes. The personal row writes through the
 * `personal` sentinel, which the server resolves even before the file exists.
 */
const canWrite = computed(() => connection.scopes.includes('write'))
type InstructionRow = NonNullable<ContextSourcesResult['instructionFiles']>[number]
const editing = ref<InstructionRow | null>(null)
const editText = ref('')
const editError = ref<string | null>(null)
const saving = ref(false)
const editModal = ref<HTMLElement | null>(null)

function openEdit(file: InstructionRow): void {
  editing.value = file
  editText.value = ''
  editError.value = null
}

function cancelEdit(): void {
  editing.value = null
  editError.value = null
}

// CW-UX-07: the edit modal is inline in the panel, so it has no backdrop
// element; the pointerdown-outside dismissal stands in for one, and Escape —
// which this modal never answered at all — closes it.
const dialog = useDialog({
  panel: editModal,
  open: () => editing.value !== null,
  onClose: cancelEdit,
  outsidePointerClose: true,
})

async function saveEdit(): Promise<void> {
  const file = editing.value
  if (file === null || saving.value) return
  saving.value = true
  editError.value = null
  try {
    await connection.request('context.instructionWrite', {
      sessionId: props.sessionId,
      path: file.tier === 'personal' ? 'personal' : file.path,
      content: editText.value,
      expectBytes: file.bytes,
    })
    editing.value = null
  } catch (failure) {
    if (failure instanceof ProtocolError && failure.kind === 'instruction_changed') {
      // Re-measure now: the fresh listing is the latch the retry needs, and
      // no server announcement follows a refusal the session itself caused.
      void context.load(props.sessionId)
      editError.value = 'The file on disk no longer holds the bytes this listing measured — reopen Edit to save against the current file.'
    } else {
      editError.value = failure instanceof Error ? failure.message : String(failure)
    }
  } finally {
    saving.value = false
  }
}

const bytes = ref(0)
watch(
  () => context.sources,
  (sources) => {
    bytes.value = sources?.instructionFiles?.reduce((total, file) => total + file.bytes, 0) ?? 0
  },
)

const share = computed(() => {
  const breakdown = context.sources?.breakdown
  if (!breakdown || breakdown.window <= 0) return null
  return Math.min(100, Math.round((breakdown.historyTokens / breakdown.window) * 100))
})

/**
 * P-CTX.g: the `By category` model — the web twin of the TUI's ContextPane
 * box (P-CTX.a/c, over the `context.sources` categories welded by P-CTX.f).
 * The bar apportions the track over the MEASURED rows only (an unmeasured
 * row holds no cell, never a fabricated zero); a row with sub-rows drills in
 * place, and its sub-figures follow the server's precedence — note, else
 * bytes, else tokens, else "not measured". The not-sent MCP-instructions
 * row is just such a note-carrying sub: every string stays a plain span.
 */
type CategoryRow = NonNullable<NonNullable<ContextSourcesResult['breakdown']>['categories']>[number]
type CategorySub = CategoryRow['sub'][number]

/** The TUI's eleven glyphs become eleven swatch hues; unknown keys stay
 *  neutral. CW-UX-17: hues are theme tokens (--ctx-*) so the light themes
 *  get legible variants instead of the dark palette pasted on white. */
const CATEGORY_COLORS: Record<string, string> = {
  'system-prompt': 'var(--ctx-system-prompt)',
  'system-tools': 'var(--ctx-system-tools)',
  'mcp-tools': 'var(--ctx-mcp-tools)',
  'custom-agents': 'var(--ctx-custom-agents)',
  'memory-files': 'var(--ctx-memory-files)',
  'project-memory': 'var(--ctx-project-memory)',
  'repo-map': 'var(--ctx-repo-map)',
  skills: 'var(--ctx-skills)',
  messages: 'var(--ctx-messages)',
  'autocompact-buffer': 'var(--ctx-autocompact-buffer)',
  'free-space': 'var(--ctx-free-space)',
}

const categories = computed<CategoryRow[] | null>(() => context.sources?.breakdown?.categories ?? null)

// C-MCP-4.a: the MCP census now carries both tiers. Project rows keep the
// existing list untouched; user-scope rows ride their own heading with a
// liveness dot — filled when this process's memo says the server is up,
// struck when it is down, hollow when the client cannot answer, and grey
// when the memo holds nothing for it (a cold read never launches).
// P-CTX-BREAKDOWN restyles this whole section later; keep it minimal here.
type McpServerRow = ContextSourcesResult['mcp']['servers'][number]
const mcpProjectRows = computed<McpServerRow[]>(
  () => (context.sources?.mcp.servers ?? []).filter((row) => row.tier === 'project'),
)
const mcpUserRows = computed<McpServerRow[]>(
  () => (context.sources?.mcp.servers ?? []).filter((row) => row.tier === 'user'),
)
function livenessDot(row: McpServerRow): { cls: string; label: string } {
  if (row.liveness === null) return { cls: 'cold', label: t('web.panels.ctx_liveness_none') }
  if (row.liveness.up === true) return { cls: 'up', label: t('web.panels.ctx_liveness_up') }
  if (row.liveness.up === false) return { cls: 'down', label: t('web.panels.ctx_liveness_down') }
  return { cls: 'unknown', label: t('web.panels.ctx_liveness_unknown') }
}
const drill = ref<string | null>(null)

function drillCategory(key: string): void {
  drill.value = drill.value === key ? null : key
}

function colorFor(key: string): string {
  return CATEGORY_COLORS[key] ?? 'var(--line)'
}

const barSegments = computed(() => {
  const rows = categories.value ?? []
  const total = rows.reduce((sum, row) => sum + (row.tokens ?? 0), 0)
  if (total <= 0) return []
  return rows
    .map((row) => {
      const weight = row.tokens ?? 0
      return { key: row.key, label: row.label, weight, pct: (weight / total) * 100, color: colorFor(row.key) }
    })
    .filter((segment) => segment.weight > 0)
})

/** Byte figure spelled like the TUI's: whole bytes under 1 KiB, else one-decimal KB. */
function bytesFigure(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${rtrimZero((bytes / 1024).toFixed(1))} KB`
}

function rtrimZero(text: string): string {
  return text.replace(/\.?0+$/, '')
}

function catFigure(cat: CategoryRow): string {
  return cat.tokens === null ? 'not measured' : `~${cat.tokens.toLocaleString()}`
}

function subFigure(row: CategorySub): string {
  if (row.note !== null) return row.note
  if (row.bytes !== null) return bytesFigure(row.bytes)
  return row.tokens === null ? 'not measured' : `~${row.tokens.toLocaleString()}`
}

const loading = computed(() => context.loading && context.sessionId !== props.sessionId)

onMounted(() => void context.load(props.sessionId))
</script>

<template>
  <div class="context-panel" data-testid="context-panel">
    <div class="head">
      <span v-if="context.loaded && context.sessionId === sessionId" class="count numbers" data-testid="context-count">{{ t('web.panels.ctx_head_badge') }}</span>
      <button type="button" class="link" data-testid="context-reload" :disabled="context.loading" @click="context.load(sessionId)">{{ t('web.panels.reload') }}</button>
    </div>
    <p v-if="context.error" class="error" role="alert" data-testid="context-error">{{ context.error }}</p>
    <p v-else-if="loading" class="empty">{{ t('web.panels.ctx_measuring') }}</p>
    <template v-else-if="context.loaded && context.sessionId === sessionId && context.sources">
      <section v-if="context.sources.breakdown" data-testid="context-breakdown">
        <p class="share">
          ~{{ context.sources.breakdown.historyTokens.toLocaleString() }} / {{ context.sources.breakdown.window.toLocaleString() }} {{ t('web.panels.ctx_share_suffix') }}
          <template v-if="share !== null"> ({{ share }}%)</template>
        </p>
        <ul v-if="context.sources.breakdown.sections" class="layers" data-testid="context-layers">
          <li v-for="layer in context.sources.breakdown.sections" :key="layer.label">
            <span class="label">{{ layer.label }}</span>
            <span class="tokens numbers">~{{ layer.tokens.toLocaleString() }}</span>
          </li>
        </ul>
        <p class="meta">
          {{ t('web.panels.ctx_tools_label') }} <template v-if="context.sources.breakdown.toolCount !== null">{{ t('web.panels.ctx_tools_count', {count: context.sources.breakdown.toolCount, tokens: context.sources.breakdown.toolTokens?.toLocaleString() ?? ''}) }}</template>
          <template v-else>{{ t('web.panels.ctx_not_measured') }}</template>
          {{ t('web.panels.ctx_history_stats', {sent: context.sources.breakdown.historyMessages, uiOnly: context.sources.breakdown.uiOnlyRows}) }}
          <template v-if="context.sources.breakdown.pruning"> {{ t('web.panels.ctx_pruned', {tokens: context.sources.breakdown.pruning.outputTokens.toLocaleString(), mode: context.sources.breakdown.pruning.mode}) }}</template>
        </p>
      </section>
      <p v-else class="empty" data-testid="context-no-breakdown">{{ t('web.panels.ctx_no_breakdown') }}</p>

      <section v-if="categories !== null" data-testid="context-categories">
        <h3>{{ t('web.panels.ctx_by_category') }}</h3>
        <div class="cat-bar" data-testid="context-cat-bar" aria-hidden="true">
          <span v-for="seg in barSegments" :key="seg.key" class="cat-seg" :data-testid="`context-cat-seg-${seg.key}`" :style="{ width: `${seg.pct}%`, background: seg.color }" :title="`${seg.label} · ${Math.round(seg.pct)}%`"></span>
        </div>
        <ul class="cats">
          <li v-for="cat in categories" :key="cat.key" class="cat" :data-testid="`context-cat-${cat.key}`">
            <button type="button" class="cat-head" :disabled="cat.sub.length === 0" :data-testid="`context-cat-toggle-${cat.key}`" @click="drillCategory(cat.key)">
              <span class="swatch" :style="{ background: colorFor(cat.key) }" aria-hidden="true"></span>
              <span class="label">{{ cat.label }}</span>
              <span class="tokens numbers">{{ catFigure(cat) }}</span>
              <span v-if="cat.sub.length > 0" class="drill-mark">{{ drill === cat.key ? '▾' : '▸' }}</span>
            </button>
            <ul v-if="drill === cat.key && cat.sub.length > 0" class="subs" :data-testid="`context-subs-${cat.key}`">
              <li v-for="(row, index) in cat.sub" :key="index" class="sub" :data-testid="`context-sub-${cat.key}-${index}`">
                <span class="label sub-label">{{ row.label }}</span>
                <span v-if="row.personal" class="badge">{{ t('web.panels.ctx_personal') }}</span>
                <span class="tokens numbers">{{ subFigure(row) }}</span>
              </li>
            </ul>
          </li>
        </ul>
        <p class="meta" data-testid="context-raw-hint">{{ t('web.panels.ctx_run_pre') }} <code>/context --raw</code> {{ t('web.panels.ctx_run_post') }}</p>
      </section>

      <section data-testid="context-instruction-files">
        <h3>{{ t('web.panels.ctx_instruction_files') }}</h3>
        <p v-if="context.sources.instructionFiles === null" class="empty">{{ t('web.panels.ctx_not_measured') }}</p>
        <p v-else-if="context.sources.instructionFiles.length === 0" class="empty">{{ t('web.panels.ctx_none') }}</p>
        <ul v-else>
          <li v-for="file in context.sources.instructionFiles" :key="file.path">
            <span class="path" :data-testid="`context-file-${file.path}`">{{ file.path }}</span>
            <span class="bytes">{{ file.bytes.toLocaleString() }} B</span>
            <span v-if="file.tier === 'personal'" class="badge">{{ t('web.panels.ctx_personal') }}</span>
            <span class="badge">{{ file.tier }}</span>
            <button v-if="canWrite" type="button" class="link edit" :data-testid="`context-edit-${file.path}`" @click="openEdit(file)">{{ t('web.panels.edit') }}</button>
          </li>
        </ul>
        <p v-if="context.sources.instructionFiles && bytes > 0" class="meta">{{ t('web.panels.ctx_spliced_total', {bytes: bytes.toLocaleString()}) }}</p>
      </section>

      <section v-if="context.sources.untrusted.length > 0" data-testid="context-untrusted">
        <h3>{{ t('web.panels.ctx_not_read') }}</h3>
        <ul>
          <li v-for="entry in context.sources.untrusted" :key="entry.path">
            <span class="path">{{ entry.path }}</span> — <span class="reason">{{ entry.reason }}</span>
          </li>
        </ul>
      </section>

      <section data-testid="context-memory">
        <h3>{{ t('web.panels.ctx_memory_heading', {n: context.sources.memorySections.length}) }}</h3>
        <p v-if="context.sources.memorySections.length === 0" class="empty">{{ t('web.panels.ctx_none') }}</p>
        <ul v-else>
          <li v-for="note in context.sources.memorySections" :key="`${note.source}-${note.id}`">
            <span class="content">{{ note.content }}</span>
            <span class="badge">{{ note.scope }}</span>
            <span class="badge" :data-testid="`context-memory-source-${note.source}`">{{ note.source }}</span>
          </li>
        </ul>
      </section>

      <section data-testid="context-skills">
        <h3>{{ t('web.panels.ctx_skills_heading', {n: context.sources.skills.length}) }}</h3>
        <p v-if="context.sources.skills.length === 0" class="empty">{{ t('web.panels.ctx_none') }}</p>
        <ul v-else>
          <li v-for="skill in context.sources.skills" :key="skill.name">
            <span class="name">{{ skill.name }}</span>
            <span class="badge">{{ skill.tier }}</span>
            <span v-if="!skill.enabled" class="badge off">{{ t('web.panels.ctx_disabled') }}</span>
          </li>
        </ul>
      </section>

      <section data-testid="context-mcp">
        <h3>MCP</h3>
        <p class="meta">
          <span data-testid="context-mcp-status">{{ context.sources.mcp.status }}</span>
          <span v-if="context.sources.mcp.error"> — {{ context.sources.mcp.error }}</span>
        </p>
        <ul v-if="mcpProjectRows.length > 0">
          <li v-for="server in mcpProjectRows" :key="server.name">
            <span class="name">{{ server.name }}</span>
            <span class="badge">{{ server.transport }}</span>
            <span class="badge">{{ server.trust }}</span>
          </li>
        </ul>
        <p v-if="context.sources.mcp.skipped.length > 0" class="meta">{{ t('web.panels.ctx_skipped') }} <span class="reason">{{ context.sources.mcp.skipped.join('; ') }}</span></p>
        <template v-if="mcpUserRows.length > 0">
          <h4 data-testid="context-mcp-user-heading">{{ t('web.panels.ctx_user_scope') }}</h4>
          <ul data-testid="context-mcp-user">
            <li v-for="server in mcpUserRows" :key="server.name" data-testid="context-mcp-user-row">
              <span
                class="liveness"
                :class="livenessDot(server).cls"
                :aria-label="livenessDot(server).label"
                data-testid="context-mcp-liveness"
              ></span>
              <span class="name">{{ server.name }}</span>
              <span class="badge">{{ server.transport }}</span>
              <span class="badge">{{ server.trust }}</span>
            </li>
          </ul>
        </template>
      </section>

      <section v-if="(context.sources.trusts?.length ?? 0) > 0 || trustError" data-testid="context-trust">
        <h3>{{ t('web.panels.ctx_trust') }}</h3>
        <p v-if="trustError" class="error" role="alert" data-testid="context-trust-error">{{ trustError }}</p>
        <div v-for="prompt in context.sources.trusts" :key="prompt.askId" class="trust-row" data-testid="context-trust-row" :data-target="prompt.target">
          <p class="reason" data-testid="context-trust-reason">{{ prompt.reason }}</p>
          <p class="meta">{{ t('web.panels.ctx_root_prefix') }} <span class="path" data-testid="context-trust-root">{{ prompt.root }}</span></p>
          <div v-if="prompt.status === 'asked'" class="actions">
            <button type="button" data-testid="context-trust-session" :disabled="busy || !canAnswer" @click="answer(prompt.askId, 'grant', 'session')">{{ t('web.panels.ctx_trust_silence') }}</button>
            <button type="button" data-testid="context-trust-user" :disabled="busy || !canAnswer" @click="answer(prompt.askId, 'grant', 'user')">{{ t('web.panels.ctx_trust_always') }}</button>
            <button type="button" data-testid="context-trust-reject" :disabled="busy || !canAnswer" @click="answer(prompt.askId, 'reject', 'session')">{{ t('web.panels.ctx_trust_leave') }}</button>
          </div>
          <p v-else class="meta" data-testid="context-trust-settled">
            <template v-if="prompt.status === 'granted'">
              {{ prompt.persisted ? t('web.panels.ctx_trust_grant_saved') : t('web.panels.ctx_trust_grant_run') }}
            </template>
            <template v-else>{{ t('web.panels.ctx_trust_settled_reject') }}</template>
          </p>
        </div>
      </section>
    </template>

    <div v-if="editing !== null" ref="editModal" class="edit-modal" role="dialog" aria-modal="true" :aria-label="t('web.panels.ctx_edit_aria')" data-testid="instruction-edit-modal" @keydown="dialog.onKeydown">
      <h3>{{ editing.tier === 'personal' ? t('web.panels.ctx_personal_file') : t('web.panels.ctx_edit_modal_title') }}</h3>
      <p class="meta path">{{ editing.path }}</p>
      <p class="meta">{{ t('web.panels.ctx_edit_note', {bytes: editing.bytes.toLocaleString()}) }}</p>
      <textarea v-model="editText" rows="10" :aria-label="t('web.panels.ctx_new_content_aria')" data-testid="instruction-edit-text"></textarea>
      <p v-if="editError" class="error" role="alert" data-testid="instruction-edit-error">{{ editError }}</p>
      <div class="actions">
        <button type="button" class="link" data-testid="instruction-edit-save" :disabled="saving" @click="saveEdit">{{ t('web.panels.save') }}</button>
        <button type="button" class="link" data-testid="instruction-edit-cancel" @click="cancelEdit">{{ t('web.panels.cancel') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.context-panel {
  display: grid;
  gap: 0.75rem;
  min-width: 0;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
h3 {
  margin: 0 0 0.25rem;
  font-size: 0.8125rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
section {
  min-width: 0;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.3rem;
}
li {
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
  min-width: 0;
}
.path,
.content,
.reason,
.name {
  overflow-wrap: anywhere;
  font-size: 0.8125rem;
}
.content {
  flex: 1;
}
.tokens,
.bytes {
  color: var(--muted);
  font-size: 0.75rem;
  white-space: nowrap;
}
.share {
  margin: 0 0 0.25rem;
  font-weight: 600;
}
.badge {
  display: inline-block;
  padding: 0 0.35rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  font-size: 0.75rem;
  color: var(--muted);
}
.badge.off {
  color: var(--error);
}
h4 {
  margin: 0.4rem 0 0.25rem;
  font-size: 0.75rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
}
.liveness {
  flex: none;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--muted);
}
.liveness.up {
  /* CW-UX-17: the mcp-tools hue — the liveness dot marks reachable MCP
     servers, so it shares that swatch token rather than pasting a hex. */
  background: var(--ctx-mcp-tools);
}
.liveness.down {
  background: var(--error);
}
.liveness.unknown {
  background: transparent;
  border: 1px solid var(--muted);
  box-sizing: border-box;
}
.meta,
.empty {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
}
.cat-bar {
  display: flex;
  height: 0.55rem;
  border-radius: 3px;
  overflow: hidden;
  background: var(--line);
  margin-bottom: 0.4rem;
}
.cat-seg {
  height: 100%;
}
.cats,
.subs {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.25rem;
}
li.cat {
  display: block;
}
.cat-head {
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
  width: 100%;
  min-width: 0;
  font: inherit;
  color: inherit;
  background: transparent;
  border: 0;
  padding: 0;
  text-align: left;
  cursor: pointer;
}
.cat-head:disabled {
  cursor: default;
}
.swatch {
  display: inline-block;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: var(--radius-1);
  flex: none;
}
.drill-mark {
  color: var(--muted);
  font-size: 0.75rem;
}
.subs {
  padding-left: 1.1rem;
  gap: 0.2rem;
}
.sub {
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
  min-width: 0;
}
.sub-label {
  flex: 1;
  color: var(--muted);
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
.actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.edit-modal {
  border: 1px solid var(--line);
  border-radius: var(--radius-2);
  padding: 0.6rem;
  display: grid;
  gap: 0.4rem;
}
.edit-modal textarea {
  width: 100%;
  font: inherit;
  background: var(--bg);
  color: inherit;
  border: 1px solid var(--line);
  border-radius: 3px;
  padding: 0.4rem;
  box-sizing: border-box;
}
.numbers {
  font-variant-numeric: tabular-nums;
}
</style>
