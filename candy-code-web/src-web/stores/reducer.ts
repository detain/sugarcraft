import type { EventEnvelope, Events, MediaRef, PendingAsk, PermissionMode, QueueEntry, SessionSnapshot, Usage } from '../protocol/generated'
import { utf8Length } from '../protocol/jsonrpc'
import { keepStarted } from './agents/tree'

/**
 * The session reducer (Appendix O §7.5): what one session's events do to its
 * client-side state. Pure functions over a plain object, so the rules are
 * tested without a store, a socket or a DOM; the session store wraps the
 * object in `reactive()` and calls {@see applyEvent} for every event the
 * cursor lets through.
 *
 * The rules that make a replay harmless:
 * - rows are upserted by identity — a `message.created` by its `messageId`,
 *   a tool by its turn and `toolCallId` — so an event seen twice changes
 *   nothing the second time;
 * - a delta is appended only when its byte `offset` is exactly what this part
 *   already holds; a duplicate is dropped, and a gap is marked (the durable
 *   `assistant.completed` that follows carries the whole text);
 * - `assistant.completed` lands the reply as a row and clears the live text.
 */

export type ToolStatus = 'running' | 'done' | 'error' | 'denied' | 'interrupted'

export interface UserItem {
  kind: 'user'
  key: string
  messageId?: string
  content: string
}

export interface AssistantItem {
  kind: 'assistant'
  key: string
  messageId?: string
  turnId?: string
  content: string
  reasoning?: string
  lengthStopped?: boolean
  stepsTruncated?: boolean
  /** Text streamed before a tool call: a step's words, not the settled reply. */
  step?: boolean
}

export interface NoticeItem {
  kind: 'notice'
  key: string
  level: 'info' | 'warn' | 'error'
  content: string
}

export interface ToolItem {
  kind: 'tool'
  key: string
  turnId?: string
  toolCallId: string
  name: string
  arguments: Record<string, unknown>
  description?: string
  status: ToolStatus
  content?: string
  truncated?: boolean
  diff?: string
  durationMs?: number
  denial?: { kind: string; reason?: string }
  /** Artifact refs from the session media store — paths only, never bytes (Appendix C-7). Absent stays absent. */
  media?: MediaRef[]
  /** The server capped the ref list at MAX_EVENT_MEDIA_REFS; more artifacts exist. */
  mediaTruncated?: boolean
  reasoning?: string
  /** WEB-4 live tail: the newest tool.progress frame's tail, replaced wholesale per frame. Ephemeral paint — cleared by tool.finished. */
  progressTail?: string
  /** Highest tool.progress seq applied to this card; a lower or equal seq is a duplicate or out-of-order and is ignored. */
  progressSeq?: number
  /** True when the ring dropped bytes beyond the tail budget. */
  progressTruncated?: boolean
}

export type TranscriptItem = UserItem | AssistantItem | NoticeItem | ToolItem

export type SessionStatus = 'idle' | 'busy' | 'waiting_permission'

export interface LivePart {
  partId: string
  /** Bytes of the part received so far (offsets count UTF-8 bytes). */
  bytes: number
  text: string
  gap: boolean
}

export interface SubAgent {
  id: string
  op: string
  name?: string | null
  task?: string | null
  parentCallId?: string | null
  tail?: unknown
  outcome?: unknown
}

export interface SessionState {
  sessionId: string
  items: TranscriptItem[]
  status: SessionStatus
  turnId: string | null
  step: number
  maxSteps: number
  contextTokens: number | null
  contextWindow: number | null
  turnUsage: Usage | null
  spentUsd: number
  /**
   * WEB-5 billing parity: the server billed this session's model with no
   * price on file, so `spentUsd` is a LOWER BOUND, not an exact bill. The
   * snapshot carries the bit as `spendUnpriced`, present only when true
   * (SES-1: `SessionSnapshot::shape()` emits it exactly that way). Wire-
   * truth key flip 2026-10-23 — the reader used to chase an `unpricedModel`
   * key no server lane ever emitted; absent stays priced.
   */
  unpriced: boolean
  permissionMode: PermissionMode | null
  queue: QueueEntry[]
  asks: PendingAsk[]
  live: LivePart | null
  reasoning: LivePart | null
  subagents: Record<string, SubAgent>
  /** The last turn's end when it was not a plain end_turn. */
  lastStop: { stopReason: string; error?: string } | null
  /** ISS-058: true while the server says older rows exist beyond what this client holds. */
  hasMoreOlder: boolean
  /** Exclusive bound to ask the next older page with; null when the server never named one. */
  olderCursor: number | null
}

export function createState(sessionId: string): SessionState {
  return {
    sessionId,
    items: [],
    status: 'idle',
    turnId: null,
    step: 0,
    maxSteps: 0,
    contextTokens: null,
    contextWindow: null,
    turnUsage: null,
    spentUsd: 0,
    unpriced: false,
    permissionMode: null,
    queue: [],
    asks: [],
    live: null,
    reasoning: null,
    subagents: {},
    lastStop: null,
    hasMoreOlder: false,
    olderCursor: null,
  }
}

// ── snapshot ──────────────────────────────────────────────────────────

type Row = Record<string, unknown>

function str(value: unknown): string | undefined {
  return typeof value === 'string' ? value : undefined
}

function obj(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : {}
}

/**
 * The `media` list a snapshot row's finished-call entry carries (W7 fold,
 * R16(a) — the same refs `tool.finished` stamped, derived server-side by one
 * builder). Absent or malformed stays absent: never a fabricated empty list,
 * so a pre-media row hydrates exactly as it did before the field existed.
 */
function refsFromRow(value: unknown): MediaRef[] | undefined {
  if (!Array.isArray(value)) return undefined
  const refs = value.filter(
    (entry): entry is MediaRef => typeof entry === 'object' && entry !== null
      && typeof (entry as Record<string, unknown>).artifactId === 'string',
  )
  return refs.length > 0 ? refs : undefined
}

/** The transcript items one saved row shows (none for a hidden harness row). */
export function itemsFromRow(row: Row, index: number): TranscriptItem[] {
  if (row.userVisible === false) return []
  const role = str(row.role) ?? 'user'
  const content = str(row.content) ?? ''
  const key = str(row.id) ?? `row:${index}`
  const reasoning = str(row.reasoning) || undefined

  // A call still running is saved as a placeholder row (role `system`).
  const pending = str(row.pendingToolCallId)
  if (pending !== undefined) {
    return [{
      kind: 'tool',
      key: `${key}:${pending}`,
      toolCallId: pending,
      name: str(row.pendingToolName) ?? 'tool',
      arguments: obj(row.pendingToolArguments),
      description: content || undefined,
      status: 'running',
      reasoning,
    }]
  }

  if (role === 'user') {
    return row.uiOnly === true
      ? [{ kind: 'notice', key, level: 'info', content }]
      : [{ kind: 'user', key, messageId: str(row.id), content }]
  }
  if (role === 'system') {
    return [{ kind: 'notice', key, level: 'info', content }]
  }
  if (role !== 'assistant') return []

  const results = Array.isArray(row.toolResults) ? row.toolResults : []
  if (results.length > 0) {
    return results.map((raw, n): ToolItem => {
      const result = obj(raw)
      const error = str(result.error)
      const denial = str(result.denial)
      const id = str(result.id) ?? `${n}`
      return {
        kind: 'tool',
        key: `${key}:${id}:${n}`,
        toolCallId: id,
        name: str(result.name) ?? 'tool',
        arguments: obj(result.arguments),
        description: str(result.description),
        status: denial ? 'denied' : error !== undefined ? 'error' : 'done',
        content: error ?? str(result.result) ?? '',
        diff: str(result.diff) || undefined,
        durationMs: typeof result.durationMs === 'number' ? result.durationMs : undefined,
        denial: denial ? { kind: denial, reason: error } : undefined,
        // W7 snapshot fold (R16(a)): a reload takes media from the row's refs,
        // the same list the live `tool.finished` frame carried. Absent stays
        // absent — pre-media rows hydrate exactly as they did before.
        media: refsFromRow(result.media),
        mediaTruncated: result.mediaTruncated === true ? true : undefined,
        reasoning: n === 0 ? reasoning : undefined,
      }
    })
  }

  return [{
    kind: 'assistant',
    key,
    messageId: str(row.id),
    content,
    reasoning,
    lengthStopped: row.lengthStopped === true,
    stepsTruncated: row.stepsTruncated === true,
  }]
}

/** Replace the state with a snapshot (a reset subscribe). */
export function applySnapshot(state: SessionState, snapshot: SessionSnapshot): void {
  state.items = snapshot.messages.flatMap((row, i) => itemsFromRow(row, i))
  const paging = readPaging(snapshot)
  state.hasMoreOlder = paging.hasMoreOlder
  state.olderCursor = paging.cursor
  state.status = snapshot.status
  state.turnId = snapshot.turnId ?? null
  state.spentUsd = snapshot.spentUsd ?? 0
  // The wire key is `spendUnpriced` and the server sends it only when set
  // (generated.ts does not type it yet — the schema predates SES-1; seam
  // filed docs-fix-1023 N1). Any other payload value means priced.
  state.unpriced = (snapshot as unknown as { spendUnpriced?: unknown }).spendUnpriced === true
  state.permissionMode = snapshot.permissionMode ?? null
  state.queue = [...snapshot.queue]
  state.asks = [...snapshot.pendingAsks]
  state.live = null
  state.reasoning = null
  state.subagents = {}
  for (const raw of snapshot.subagents ?? []) {
    const agent = raw as unknown as SubAgent
    if (typeof agent.id === 'string') state.subagents[agent.id] = agent
  }
}

/** Open questions the server reported on (re)subscribe: authoritative, whatever the cursor. */
export function applyPendingAsks(state: SessionState, asks: PendingAsk[]): void {
  state.asks = [...asks]
  if (asks.length > 0 && state.status === 'idle') state.status = 'waiting_permission'
}

// ── paging (ISS-058, client consumer) ─────────────────────────────────

/**
 * The optional paging fields a snapshot may carry (ISS-058's fix shape: the
 * server pages the snapshot by `beforeIndex`/`limit` and says what is left).
 * A server that does not page yet carries NEITHER field — that is the normal
 * shape and means "this snapshot is everything", so no paging request is
 * ever sent at it. Recognised spellings are read defensively; an unknown
 * future field simply stays invisible until this widens.
 */
export function readPaging(snapshot: SessionSnapshot): { hasMoreOlder: boolean; cursor: number | null } {
  const raw = snapshot as SessionSnapshot & { hasMoreOlder?: unknown; hasMore?: unknown; oldestIndex?: unknown; olderCursor?: unknown; beforeIndex?: unknown }
  const hasMoreOlder = raw.hasMoreOlder === true || raw.hasMore === true
  const bound = raw.oldestIndex ?? raw.olderCursor ?? raw.beforeIndex
  return {
    hasMoreOlder,
    cursor: typeof bound === 'number' && Number.isInteger(bound) ? bound : null,
  }
}

/**
 * Prepend one older page below what the client holds (ISS-058). Rows are
 * taken in transcript order like any snapshot; a row whose id the client
 * already shows is skipped, so a server whose page boundary shifts (hidden
 * rows, compaction) cannot duplicate a card. Rows without an id get negative
 * fallback keys, `startOrdinal` steps across pages, and the page's own
 * paging fields replace the held ones — a page that names no further cursor
 * ends the walk even if the flag still promises more, because there is
 * nothing left to ask with.
 */
export function applyOlderPage(state: SessionState, page: SessionSnapshot, startOrdinal: number): number {
  const present = new Set(state.items.map((item) => item.key.split(':')[0] ?? ''))
  const older: TranscriptItem[] = []
  let skipped = 0
  page.messages.forEach((row, i) => {
    const id = str(row.id)
    if (id !== undefined && present.has(id)) {
      skipped++
      return
    }
    for (const item of itemsFromRow(row, -(startOrdinal + i) - 1)) older.push(item)
  })
  state.items = [...older, ...state.items]
  const paging = readPaging(page)
  state.hasMoreOlder = paging.hasMoreOlder
  state.olderCursor = paging.cursor
  return page.messages.length - skipped
}

// ── events ────────────────────────────────────────────────────────────

function upsert(state: SessionState, item: TranscriptItem): void {
  const at = state.items.findIndex((existing) => existing.key === item.key)
  if (at >= 0) state.items[at] = item
  else state.items.push(item)
}

function appendDelta(part: LivePart | null, partId: string, offset: number, text: string): LivePart {
  const current = part !== null && part.partId === partId ? part : { partId, bytes: 0, text: '', gap: false }
  if (offset < current.bytes) return current
  if (offset > current.bytes) {
    current.gap = true
  }
  current.text += text
  current.bytes = offset + utf8Length(text)
  return current
}

/** Freeze the text streamed so far into a step row (a tool call is about to show). */
function freezeLive(state: SessionState, turnId: string | undefined): string | undefined {
  const reasoning = state.reasoning?.text.trim() ? state.reasoning.text : undefined
  if (state.reasoning) state.reasoning.text = ''
  const live = state.live
  if (live && live.text.trim() !== '') {
    upsert(state, {
      kind: 'assistant',
      key: `step:${live.partId}:${live.bytes}`,
      turnId,
      content: live.text,
      step: true,
    })
  }
  if (live) {
    live.text = ''
    live.gap = false
  }
  return reasoning
}

function toolKey(turnId: string | undefined, toolCallId: string): string {
  return `tool:${turnId ?? '-'}:${toolCallId}`
}

/** The newest still-running tool row for $toolCallId (call ids repeat across turns). */
function runningTool(state: SessionState, turnId: string | undefined, toolCallId: string): ToolItem | undefined {
  const exact = state.items.find((item): item is ToolItem => item.kind === 'tool' && item.key === toolKey(turnId, toolCallId))
  if (exact) return exact
  for (let i = state.items.length - 1; i >= 0; i--) {
    const item = state.items[i]
    if (item?.kind === 'tool' && item.toolCallId === toolCallId && item.status === 'running') return item
  }
  return undefined
}

type Ev<E extends keyof Events> = { data: Events[E]; turnId?: string }

/**
 * Apply one event to $state. Unknown event types are ignored (protocol rule:
 * within a major, changes are additive).
 */
export function applyEvent(state: SessionState, envelope: EventEnvelope): void {
  const turnId = envelope.turnId
  switch (envelope.type) {
    case 'message.created': {
      const { data } = envelope as unknown as Ev<'message.created'>
      const kind = data.kind ?? data.role
      if (data.role === 'user' && kind === 'user') {
        upsert(state, { kind: 'user', key: data.messageId, messageId: data.messageId, content: data.content })
      } else if (data.role === 'assistant') {
        upsert(state, { kind: 'assistant', key: data.messageId, messageId: data.messageId, content: data.content })
      } else {
        upsert(state, { kind: 'notice', key: data.messageId, level: kind === 'error' ? 'error' : kind === 'warn' ? 'warn' : 'info', content: data.content })
      }
      break
    }

    case 'turn.started': {
      const { data } = envelope as unknown as Ev<'turn.started'>
      state.turnId = data.turnId
      state.status = 'busy'
      state.step = 0
      state.turnUsage = null
      state.live = null
      state.reasoning = null
      state.lastStop = null
      break
    }

    case 'turn.step': {
      const { data } = envelope as unknown as Ev<'turn.step'>
      state.step = data.step
      state.maxSteps = data.maxSteps
      const context = obj(data.context)
      if (typeof context.tokens === 'number') state.contextTokens = context.tokens
      if (typeof context.window === 'number') state.contextWindow = context.window
      break
    }

    case 'assistant.delta': {
      const { data } = envelope as unknown as Ev<'assistant.delta'>
      state.live = appendDelta(state.live, data.partId, data.offset, data.text)
      break
    }

    case 'reasoning.delta': {
      const { data } = envelope as unknown as Ev<'reasoning.delta'>
      state.reasoning = appendDelta(state.reasoning, data.partId, data.offset, data.text)
      break
    }

    case 'assistant.narration': {
      // A narrated pane's tail (O-6a): it replaces the live text. With its
      // byte `offset`, the deltas that follow once the pane is in front
      // continue it exactly; a tail no newer than what is held is stale.
      const { data } = envelope as unknown as Ev<'assistant.narration'>
      if (typeof data.offset !== 'number') {
        state.live = { partId: data.partId, bytes: 0, text: data.tail, gap: true }
        break
      }
      const current = state.live !== null && state.live.partId === data.partId ? state.live : null
      const end = data.offset + utf8Length(data.tail)
      if (current !== null && end <= current.bytes) break
      const start = current !== null ? current.bytes - utf8Length(current.text) : null
      const continues = current !== null && !current.gap && start !== null && data.offset <= start
      state.live = { partId: data.partId, bytes: end, text: data.tail, gap: data.offset > 0 && !continues }
      break
    }

    case 'assistant.completed': {
      const { data } = envelope as unknown as Ev<'assistant.completed'>
      const key = data.messageId ?? `reply:${turnId ?? '-'}`
      // Step text already shown is part of the reply when the reply repeats it.
      state.items = state.items.filter((item) => !(item.kind === 'assistant' && item.step === true && item.turnId === turnId && data.content.includes(item.content.trim())))
      upsert(state, {
        kind: 'assistant',
        key,
        messageId: data.messageId,
        turnId,
        content: data.content,
        reasoning: data.reasoning || state.reasoning?.text || undefined,
        lengthStopped: data.lengthStopped,
        stepsTruncated: data.stepsTruncated,
      })
      if (state.live) {
        state.live.text = ''
        state.live.gap = false
      }
      if (state.reasoning) state.reasoning.text = ''
      if (data.usage) state.turnUsage = data.usage
      break
    }

    case 'tool.started': {
      const { data } = envelope as unknown as Ev<'tool.started'>
      const reasoning = freezeLive(state, turnId)
      if (runningTool(state, turnId, data.toolCallId)?.key === toolKey(turnId, data.toolCallId)) break
      upsert(state, {
        kind: 'tool',
        key: toolKey(turnId, data.toolCallId),
        turnId,
        toolCallId: data.toolCallId,
        name: data.name,
        arguments: data.arguments ?? {},
        status: 'running',
        reasoning,
      })
      break
    }

    case 'tool.progress': {
      // WEB-4: ephemeral paint only. Never durable, never replayed, so a
      // frame that belongs to no running card (late, lost, or already
      // finished) is dropped rather than resurrected — tool.finished is
      // the contract, and it replaces the item wholesale, clearing these.
      const { data } = envelope as unknown as Ev<'tool.progress'>
      const item = runningTool(state, turnId, data.toolCallId)
      if (!item) break
      if (data.seq <= (item.progressSeq ?? 0)) break
      upsert(state, {
        ...item,
        progressTail: data.tail,
        progressSeq: data.seq,
        progressTruncated: data.truncated,
      })
      break
    }

    case 'tool.finished': {
      const { data } = envelope as unknown as Ev<'tool.finished'>
      const existing = runningTool(state, turnId, data.toolCallId)
      const item: ToolItem = {
        kind: 'tool',
        key: existing?.key ?? toolKey(turnId, data.toolCallId),
        turnId,
        toolCallId: data.toolCallId,
        name: data.name,
        arguments: existing?.arguments ?? {},
        description: existing?.description,
        status: data.denial ? 'denied' : data.isError ? 'error' : 'done',
        content: data.content,
        truncated: data.truncated === true,
        diff: data.diff || undefined,
        durationMs: data.durationMs,
        denial: data.denial,
        // Absent stays absent (old-server compat): the ref list lands only when
        // the event carries one — never an invented empty array (W6.3 compat law).
        media: data.media,
        mediaTruncated: data.mediaTruncated,
        reasoning: existing?.reasoning,
      }
      upsert(state, item)
      break
    }

    case 'permission.requested': {
      const { data } = envelope as unknown as Ev<'permission.requested'>
      if (!state.asks.some((ask) => ask.askId === data.askId)) state.asks.push(data)
      break
    }

    case 'permission.resolved': {
      const { data } = envelope as unknown as Ev<'permission.resolved'>
      state.asks = state.asks.filter((ask) => ask.askId !== data.askId)
      break
    }

    case 'session.status': {
      const { data } = envelope as unknown as Ev<'session.status'>
      state.status = data.status
      break
    }

    case 'turn.queued': {
      const { data } = envelope as unknown as Ev<'turn.queued'>
      if (!state.queue.some((entry) => entry.queueId === data.queueId)) {
        state.queue.push({ queueId: data.queueId, text: data.text, position: data.position })
      }
      break
    }

    case 'turn.dequeued': {
      const { data } = envelope as unknown as Ev<'turn.dequeued'>
      state.queue = state.queue.filter((entry) => entry.queueId !== data.queueId)
      break
    }

    case 'usage.updated': {
      const { data } = envelope as unknown as Ev<'usage.updated'>
      if (data.turnUsage) state.turnUsage = data.turnUsage
      break
    }

    case 'turn.completed': {
      const { data } = envelope as unknown as Ev<'turn.completed'>
      // Whatever streamed and never settled (a cancel) stays visible as it was.
      if (state.live && state.live.text.trim() !== '') freezeLive(state, turnId)
      for (const item of state.items) {
        if (item.kind === 'tool' && item.status === 'running' && (item.turnId === undefined || item.turnId === turnId)) {
          item.status = 'interrupted'
        }
      }
      state.live = null
      state.reasoning = null
      state.lastStop = data.stopReason === 'end_turn' ? null : { stopReason: data.stopReason, error: data.error }
      if (data.stopReason !== 'end_turn') {
        upsert(state, {
          kind: 'notice',
          key: `stop:${turnId ?? envelope.seq ?? '-'}`,
          level: data.stopReason === 'error' ? 'error' : 'warn',
          content: stopText(data.stopReason, data.error),
        })
      }
      if (state.turnUsage?.costUsd) state.spentUsd += state.turnUsage.costUsd
      state.turnId = null
      break
    }

    case 'compaction.completed': {
      const { data } = envelope as unknown as Ev<'compaction.completed'>
      upsert(state, {
        kind: 'notice',
        key: `compaction:${envelope.seq ?? envelope.ts}`,
        level: 'info',
        content: `History compacted (${data.kind}): ${data.before} → ${data.after} tokens`,
      })
      break
    }

    case 'spend_cap.breached': {
      const { data } = envelope as unknown as Ev<'spend_cap.breached'>
      upsert(state, {
        kind: 'notice',
        key: `spend:${envelope.seq ?? envelope.ts}`,
        level: 'warn',
        content: `Spend cap reached: $${data.spent.toFixed(2)} of $${data.cap.toFixed(2)}`,
      })
      break
    }

    case 'subagent.started':
    case 'subagent.progress':
    case 'subagent.finished': {
      const { data } = envelope as unknown as Ev<'subagent.started'>
      // Only `started` names the task: a later beat must not blank it.
      state.subagents[data.id] = keepStarted(state.subagents[data.id], data as SubAgent)
      break
    }

    case 'session.updated': {
      const { data } = envelope as unknown as Ev<'session.updated'>
      if (data.permissionMode !== undefined) state.permissionMode = data.permissionMode ?? null
      break
    }
  }
}

export function stopText(stopReason: string, error?: string): string {
  switch (stopReason) {
    case 'cancelled':
      return 'Turn cancelled.'
    case 'max_steps':
      return 'Turn stopped: it reached its step limit.'
    case 'length':
      return 'Turn stopped: the reply hit the output limit.'
    case 'spend_cap':
      return 'Turn stopped: the spend cap was reached.'
    case 'loop_guard':
      // ISS-113: was falling through to the generic `Turn ended (loop_guard).`
      // row; the TUI names the guard (chat.turn.loop_guard), so the web
      // transcript must not read it as an unexplained end.
      return 'Turn stopped: the loop guard cut a repeating tool call.'
    case 'error':
      return error ? `Turn failed: ${error}` : 'Turn failed.'
    default:
      return `Turn ended (${stopReason}).`
  }
}
