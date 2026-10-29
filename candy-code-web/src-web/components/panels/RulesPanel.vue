<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { MethodResult, PermissionMode } from '../../protocol/generated'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * WEB-1c: the permission policy in the open (`permission.rules`) — the mode
 * in force and where it came from, the configured allow/ask/deny rules, and
 * the grants the session picked up as it ran. Read-only by design: the mode
 * moves through the status bar's select (`session.setMode`), and the rules
 * are the settings' doing; this panel is the audit view, not the dial.
 * With no session on screen it shows the server-wide picture.
 *
 * C-SEC-6.d: the view grew the other half of an audit — `permission.audit`,
 * the recent tool-call DECISIONS (`Recent decisions` below), read from the
 * server's own redacted audit trail. Its failure is deliberately silent in
 * the loudest sense: an older server without the method (or a log it will
 * not read) answers nothing here and never sinks the policy view above it.
 */
const props = defineProps<{ sessionId: string | null }>()
const connection = useConnectionStore()

const mode = ref<PermissionMode | null>(null)
const modeSource = ref<string | null>(null)
const rules = ref<MethodResult<'permission.rules'>['rules']>([])
const sessionGrants = ref<string[]>([])
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)

/** C-SEC-6.c: tier classification on the rows; an older server answers none, and every row rides the configured side. */
type RuleRow = MethodResult<'permission.rules'>['rules'][number] & { tier?: string; savedIndex?: number | null }
const configured = computed(() => (rules.value as RuleRow[]).filter((rule) => rule.tier !== 'user'))
const saved = computed(() => (rules.value as RuleRow[]).filter((rule) => rule.tier === 'user'))
const forgetting = ref<string | null>(null)
const forgetError = ref<string | null>(null)

/** C-SEC-6.d: one row of `permission.audit`. NULL rows means this server does not
 * answer the method (older build) — visibly different from an empty trail. */
type AuditRow = MethodResult<'permission.audit'>['rows'][number]
const auditRows = ref<AuditRow[] | null>(null)
const auditUnreadable = ref(false)

/** Take one saved rule back out, then re-read so the panel shows the truth. */
async function forget(row: RuleRow): Promise<void> {
  if (forgetting.value !== null) return
  forgetting.value = row.pattern
  forgetError.value = null
  try {
    await connection.request('permission.forget', { pattern: row.pattern })
    await load()
  } catch (failure) {
    forgetError.value = describe(failure)
  } finally {
    forgetting.value = null
  }
}

/**
 * WEB-1c review MINOR-1: the session-switch race, fixed with the same
 * generation latch AboutModal carries (:34-47). load() awaits; a sessionId
 * change starts a SECOND load while the first is still in flight, and both
 * settle on the same refs. Every visit stamps its own number, and a settle
 * that arrives after a newer load began belongs to a superseded session —
 * dropped, answers AND errors: the previous session's mode, rules or grants
 * must never repaint the panel the new session is about to fill.
 */
let generation = 0

async function load(): Promise<void> {
  const mine = ++generation
  loading.value = true
  error.value = null
  void loadAudit(mine)
  try {
    const result = await connection.request('permission.rules', props.sessionId === null ? {} : { sessionId: props.sessionId })
    if (mine !== generation) return
    mode.value = result.mode
    modeSource.value = result.modeSource ?? null
    rules.value = result.rules
    sessionGrants.value = result.sessionGrants
    loaded.value = true
  } catch (failure) {
    if (mine !== generation) return
    error.value = describe(failure)
  } finally {
    if (mine === generation) loading.value = false
  }
}

/**
 * C-SEC-6.d: the decisions trail rides its own settle on the SAME generation
 * stamp — a session switch invalidates both in-flight reads together, but a
 * failed `rules` read must not blind the audit one (and vice versa). An
 * answer failure here is NOT an error: it is an older server that has no
 * `permission.audit`, and the policy view above stays exactly as honest.
 */
async function loadAudit(mine: number): Promise<void> {
  try {
    const result = await connection.request('permission.audit', props.sessionId === null ? {} : { sessionId: props.sessionId })
    if (mine !== generation) return
    auditRows.value = result.rows
    auditUnreadable.value = result.unreadable
  } catch {
    if (mine !== generation) return
    auditRows.value = null
    auditUnreadable.value = false
  }
}

watch(() => props.sessionId, () => void load())
onMounted(() => void load())
</script>

<template>
  <div class="rules-panel" data-testid="rules-panel">
    <div class="head">
      <span class="mode" data-testid="rules-mode">mode: {{ mode ?? 'unknown' }}</span>
      <span v-if="modeSource" class="src" data-testid="rules-source" :title="modeSource">{{ modeSource }}</span>
      <button type="button" class="link" data-testid="rules-reload" :disabled="loading" @click="load">reload</button>
    </div>
    <p v-if="error" class="error" role="alert" data-testid="rules-error">{{ error }}</p>
    <p v-else-if="loading && !loaded" class="empty">Loading the policy…</p>
    <template v-else-if="loaded">
      <p v-if="rules.length === 0" class="empty" data-testid="rules-none">The server configured no allow/ask/deny rules.</p>
      <table v-else class="list" data-testid="rules-list">
        <thead>
          <tr><th>Pattern</th><th>Action</th></tr>
        </thead>
        <tbody>
          <tr v-for="rule in configured" :key="`${rule.pattern} ${rule.action}`" data-testid="rule-row">
            <td class="pattern">{{ rule.pattern }}</td>
            <td class="action" :data-action="rule.action">{{ rule.action }}</td>
          </tr>
        </tbody>
      </table>
      <h3>Saved in your config</h3>
      <p v-if="saved.length === 0" class="empty" data-testid="rules-saved-none">Nothing has been saved for future launches.</p>
      <template v-else>
        <ul data-testid="rules-saved">
          <li v-for="row in saved" :key="`saved-${row.savedIndex}-${row.pattern}`" data-testid="saved-row">
            <span class="idx">{{ row.savedIndex }}.</span>
            <span class="pattern">{{ row.pattern }}</span>
            <span class="action" :data-action="row.action">{{ row.action }}</span>
            <button type="button" class="link" :disabled="forgetting !== null" data-testid="saved-forget" @click="forget(row)">Forget</button>
          </li>
        </ul>
        <p class="note" data-testid="rules-saved-note">A saved rule takes effect at the next launch — this session keeps the policy it started with.</p>
      </template>
      <p v-if="forgetError" class="error" role="alert" data-testid="rules-forget-error">{{ forgetError }}</p>
      <h3>Grants made this session</h3>
      <p v-if="sessionGrants.length === 0" class="empty" data-testid="grants-none">Nothing has been granted while running.</p>
      <ul v-else data-testid="grants-list">
        <li v-for="grant in sessionGrants" :key="grant" data-testid="grant-row">{{ grant }}</li>
      </ul>
      <h3>Recent decisions</h3>
      <p v-if="auditUnreadable" class="error" data-testid="rules-audit-unreadable">The server could not read its audit trail just now.</p>
      <p v-else-if="auditRows === null" class="empty" data-testid="rules-audit-none">This server does not report audited decisions.</p>
      <p v-else-if="auditRows.length === 0" class="empty" data-testid="rules-audit-none">No tool calls have been audited yet.</p>
      <ul v-else data-testid="rules-audit">
        <li v-for="(row, index) in auditRows" :key="`${row.time}-${index}`" class="decision" :class="`decision-${row.verdict}`" data-testid="audit-row" :data-verdict="row.verdict">
          <span class="idx">{{ row.time }}</span>
          <span class="tool">{{ row.tool }}</span>
          <span class="action">{{ row.verdict }}</span>
          <span v-if="row.kind" class="kind">{{ row.kind }}</span>
          <span v-if="row.reason" class="reason">{{ row.reason }}</span>
        </li>
      </ul>
      <p class="note" data-testid="rules-audit-note">The trail is this user's own redacted audit log, read from a bounded tail on the server. A serve started under a different HOME or TMPDIR reads a different — usually empty — one.</p>
    </template>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.mode {
  font-weight: 600;
}
.src {
  color: var(--muted);
  font-size: 0.75rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.head button {
  margin-left: auto;
}
h3 {
  margin: 0.75rem 0 0.25rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
}
.list {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}
.list th {
  text-align: left;
  color: var(--muted);
  font-weight: 500;
  padding: 0.15rem 0.5rem 0.15rem 0;
  border-bottom: 1px solid var(--line);
}
.list td {
  padding: 0.2rem 0.5rem 0.2rem 0;
  border-bottom: 1px solid var(--line);
  word-break: break-all;
}
.action {
  white-space: nowrap;
}
.idx {
  color: var(--muted);
  margin-right: 0.35rem;
}
.note {
  color: var(--muted);
  font-size: 0.75rem;
  margin: 0.25rem 0 0;
}
.action[data-action='deny'] {
  color: var(--error);
}
.action[data-action='ask'] {
  color: var(--warn);
}
.decision-deny .action {
  color: var(--error);
}
.decision-withheld .action {
  color: var(--warn);
}
.kind {
  margin-left: 0.35rem;
  font-variant: small-caps;
}
.reason {
  color: var(--muted);
  margin-left: 0.35rem;
}
.reason::before {
  content: '— ';
}
ul {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.8125rem;
  word-break: break-all;
}
.empty {
  color: var(--muted);
  font-size: 0.875rem;
}
.error {
  color: var(--error);
  font-size: 0.875rem;
}
</style>
