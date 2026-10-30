import { defineStore, getActivePinia } from 'pinia'
import { computed, reactive, ref } from 'vue'
import { SeqCursor } from '../protocol/cursor'
import type { EventEnvelope, MethodResult, PermissionMode, SessionSnapshot, Subscription } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import type { PastedDocument, PastedImage } from '../lib/paste'
import { useConnectionStore } from './connection'
import { useLayoutStore } from './layout'
import { applyEvent, applyOlderPage, applyPendingAsks, applySnapshot, createState, type ToolItem } from './reducer'

export type Delivery = 'queue' | 'steer' | 'interrupt'
export type Phase = 'idle' | 'subscribing' | 'live'

export interface CommandInfo {
  name: string
  description?: string
  argumentHint?: string | null
  source: 'builtin' | 'file' | 'mcp' | 'skill'
  runsIn: 'server' | 'client'
}

/** How long a session keeps following after its view closes (Appendix O §7.5). */
export const RELEASE_GRACE_MS = 30_000

/** ISS-058: how many rows one loadOlder() page asks for. */
const OLDER_PAGE_LIMIT = 200

function setup(sessionId: string) {
  const connection = useConnectionStore()
  const state = reactive(createState(sessionId))
  const phase = ref<Phase>('idle')
  const error = ref<string | null>(null)
  const commands = ref<CommandInfo[]>([])
  /** ISS-058: paging state — busy flag, last page refusal, and the row offset
   *  that keeps fallback keys unique across pages. */
  const loadingOlder = ref(false)
  const olderError = ref<string | null>(null)
  let olderOrdinal = 0
  let cursor = new SeqCursor<EventEnvelope>(0)
  let early: EventEnvelope[] = []
  let unfollow: (() => void) | null = null
  let releaseTimer: ReturnType<typeof setTimeout> | null = null
  let holders = 0

  const busy = computed(() => state.status !== 'idle')
  const contextPct = computed(() =>
    state.contextTokens !== null && state.contextWindow ? Math.min(100, Math.round((state.contextTokens / state.contextWindow) * 100)) : null,
  )

  function handle(envelope: EventEnvelope): void {
    if (phase.value !== 'live') {
      early.push(envelope)
      return
    }
    if (typeof envelope.seq !== 'number') {
      applyEvent(state, envelope)
      return
    }
    const now = Date.now()
    for (const ready of cursor.offer(envelope.seq, envelope, now)) applyEvent(state, ready)
    if (cursor.needsResync(now)) void subscribe()
  }

  function applySubscription(subscription: Subscription): void {
    if ('reset' in subscription) {
      applySnapshot(state, subscription.snapshot)
      cursor = new SeqCursor(subscription.throughSeq)
    }
    // A replay ({fromSeq, throughSeq}) follows as events; the cursor drops
    // whatever of it this client already holds.
    applyPendingAsks(state, subscription.pendingAsks)
    phase.value = 'live'
    const held = early
    early = []
    for (const envelope of held) handle(envelope)
  }

  /** Follow the session: a snapshot the first time (or when $fresh), a replay from the cursor after. */
  async function subscribe(fresh = false): Promise<void> {
    unfollow ??= connection.follow(sessionId, {
      cursor: () => (phase.value === 'live' ? cursor.value : null),
      event: handle,
      resumed: (result) => {
        if (result !== undefined && !('error' in result)) {
          applySubscription(result)
        } else {
          void subscribe()
        }
      },
      // ISS-052: the server dropped this client's queue for the session
      // (dead host, overflow). Follow again from the held cursor; the
      // answer is a replay or a fresh snapshot, never a silent freeze.
      resubscribe: () => {
        if (phase.value === 'live') void subscribe()
      },
    })
    phase.value = 'subscribing'
    error.value = null
    const afterSeq = !fresh && cursor.value > 0 ? cursor.value : undefined
    try {
      const subscription = await connection.request('session.subscribe', afterSeq === undefined ? { sessionId } : { sessionId, afterSeq })
      applySubscription(subscription)
      void loadCommands()
    } catch (failure) {
      phase.value = 'idle'
      error.value = describe(failure)
    }
  }

  /** Keep following while at least one view holds the session. */
  function retain(): void {
    holders++
    if (releaseTimer !== null) {
      clearTimeout(releaseTimer)
      releaseTimer = null
    }
    if (phase.value === 'idle' && connection.connected) void subscribe()
  }

  function release(): void {
    holders = Math.max(0, holders - 1)
    if (holders > 0) return
    releaseTimer = setTimeout(() => {
      releaseTimer = null
      if (holders !== 0) return
      void unsubscribe()
      // ISS-112: nobody is looking and no tab holds the session — the store
      // was a full transcript cache per visited session, growing forever in
      // a long-lived tab. Unsubscribe was already released at the socket;
      // release the memory too. A later visit re-follows from the cursor the
      // server still keeps (overflow/resubscribe, ISS-052, is the precedent
      // that a fresh subscribe is cheap).
      if (!useLayoutStore().tabs.includes(sessionId)) disposeSessionStore(sessionId)
    }, RELEASE_GRACE_MS)
  }

  async function unsubscribe(): Promise<void> {
    unfollow?.()
    unfollow = null
    phase.value = 'idle'
    early = []
    try {
      await connection.request('session.unsubscribe', { sessionId })
    } catch {
      // the socket may already be gone; nothing to undo
    }
  }

  async function loadCommands(): Promise<void> {
    try {
      const result = await connection.request('command.list', { sessionId })
      commands.value = result.items as CommandInfo[]
    } catch {
      commands.value = []
    }
  }

  /**
   * Send what the composer holds. A `/name` that is a built-in running on
   * the server goes through `command.exec`; one that only the terminal UI
   * runs is refused here with the reason; a command file and plain prose
   * are a prompt (`session.send`), exactly as typing them in the TUI.
   */
  async function send(text: string, delivery: Delivery = 'queue', images: PastedImage[] = [], documents: PastedDocument[] = []): Promise<MethodResult<'session.send'> | MethodResult<'command.exec'>> {
    const slash = /^\/([A-Za-z0-9_:.-]+)(?:\s+([\s\S]*))?$/.exec(text.trim())
    if (slash) {
      const name = slash[1] ?? ''
      const command = commands.value.find((c) => c.name === name || c.name === `/${name}`)
      if (command && command.source === 'builtin') {
        if (command.runsIn === 'client') {
          throw new ProtocolError(-32030, `/${name} runs only in the terminal UI`, 'ui_only')
        }
        if (images.length > 0) {
          // WEB-5b, mirroring the server: a built-in's body carries no
          // attachments, so pastes ride prose only.
          throw new ProtocolError(-32602, 'images ride a prose prompt; a /command or !bang takes none', 'images_with_command')
        }
        if (documents.length > 0) {
          // C-WEB-4.e, same law one kind over: the refusal mirrors the
          // server's `documents_with_command`, checked after the image leg.
          throw new ProtocolError(-32602, 'documents ride a prose prompt; a /command or !bang takes none', 'documents_with_command')
        }
        const args = (slash[2] ?? '').trim()
        const result = await connection.request('command.exec', args === '' ? { sessionId, name } : { sessionId, name, args })
        // A command rewrites the transcript in place (`/clear`, `/rewind`, …)
        // and that is no event: take a fresh snapshot.
        if ('rows' in result) await subscribe(true)
        return result
      }
    }
    return connection.request('session.send', {
      ...(busy.value ? { sessionId, text, delivery } : { sessionId, text }),
      ...(images.length > 0 ? { images } : {}),
      ...(documents.length > 0 ? { documents } : {}),
    })
  }

  /**
   * C-TUI-3.b: a transcript row action rewinds the conversation to just
   * before a dispatched prompt. `stepsBack` counts checkpoints exactly as
   * the host's `Checkpoints::rewindConversation` does — its list is newest
   * first and the target is index `min(stepsBack, count) - 1`, so asking
   * for more than exist silently clamps to the oldest. The caller derives
   * the number from the transcript's `kind:'user'` items, which are 1:1
   * with checkpoints: command echo rows arrive user-role but `uiOnly`, and
   * the reducer maps those to notices, never to user items. Goes straight
   * to `command.exec` (not through `send()`) so it does not depend on the
   * command list having loaded, and takes a fresh snapshot the same way
   * `send()`'s built-in path does — `/rewind` rewrites rows in place.
   */
  async function rewindTo(stepsBack: number): Promise<MethodResult<'command.exec'>> {
    const result = await connection.request('command.exec', { sessionId, name: 'rewind', args: `${stepsBack} --chat` })
    if ('rows' in result) await subscribe(true)
    return result
  }

  /**
   * `fs.search` for the composer's @-mention popup (WEB-5b): workspace file
   * names ranked against the partial, jailed server-side to the project root.
   */
  async function searchFiles(query: string, limit = 12): Promise<string[]> {
    const answer = await connection.request('fs.search', query === '' ? { limit } : { query, limit })
    return answer.matches
  }

  function cancel(mode: 'hard' | 'soft' = 'hard', clearQueue = false): Promise<MethodResult<'session.cancel'>> {
    return connection.request('session.cancel', { sessionId, mode, clearQueue })
  }

  /** Answer a question; an `already_resolved` refusal means another client won, and closes the card. */
  async function respond(askId: string, reply: 'once' | 'always' | 'reject', options: { cascade?: boolean; note?: string; remember?: 'session' | 'project' | 'user' } = {}): Promise<void> {
    try {
      await connection.request('permission.respond', { sessionId, askId, reply, ...options })
    } catch (failure) {
      if (failure instanceof ProtocolError && (failure.kind === 'already_resolved' || failure.kind === 'ask_not_found')) {
        state.asks = state.asks.filter((ask) => ask.askId !== askId)
        return
      }
      throw failure
    }
  }

  async function dequeue(queueId: string): Promise<void> {
    await connection.request('session.dequeue', { sessionId, queueId })
  }

  /**
   * WEB-1a: re-read the queued prompts from the server (`session.queue`).
   * The snapshot and the live `turn.*` events keep `state.queue` current;
   * this is the manual reconcile when a viewer suspects it drifted.
   */
  async function refreshQueue(): Promise<void> {
    const result = await connection.request('session.queue', { sessionId })
    state.queue = result.items
  }

  async function setMode(permissionMode: PermissionMode): Promise<void> {
    const result = await connection.request('session.setMode', { sessionId, permissionMode })
    state.permissionMode = result.permissionMode
  }

  /**
   * ISS-058, client paging consumer. Pull the page of transcript rows just
   * older than what this client holds and prepend it. Nothing is ever asked
   * unless a snapshot (or an earlier page) named a cursor, so a server that
   * does not page yet sees byte-identical traffic. `session.history` is the
   * method the server lane is landing; until its schema generation reaches
   * this file the call goes through this deliberate loose seam, and a
   * refusal simply lands in `olderError` (the walk stops — it never retries
   * in a loop). A page that shifts its boundary (hidden or
   * compacted rows) cannot duplicate a card: rows whose id is already shown
   * are skipped.
   */
  async function loadOlder(): Promise<boolean> {
    if (!state.hasMoreOlder || state.olderCursor === null || loadingOlder.value) return false
    const cursor = state.olderCursor
    loadingOlder.value = true
    olderError.value = null
    try {
      const request = connection.request as unknown as (method: string, params: Record<string, unknown>) => Promise<unknown>
      const raw = await request('session.get', { sessionId, beforeIndex: cursor, limit: OLDER_PAGE_LIMIT })
      const page = ('snapshot' in (raw as object) ? (raw as { snapshot: unknown }).snapshot : raw) as SessionSnapshot
      if (!Array.isArray(page?.messages)) throw new ProtocolError(-32603, 'the older page carried no rows', 'bad_page')
      const fetched = applyOlderPage(state, page, olderOrdinal)
      olderOrdinal += page.messages.length
      // Stuck-page law: a page that added nothing and moved the cursor
      // nowhere is the server saying more than its flag means — believe the
      // data and end the walk, or the button would click into a loop.
      if (fetched === 0 && state.olderCursor === cursor) state.hasMoreOlder = false
      return true
    } catch (failure) {
      olderError.value = describe(failure)
      return false
    } finally {
      loadingOlder.value = false
    }
  }

  /** Read a truncated tool output in full (`tool.output`), into its card. */
  async function loadFullOutput(item: ToolItem): Promise<void> {
    let content = ''
    let offset = 0
    for (let page = 0; page < 64; page++) {
      const chunk = await connection.request('tool.output', { sessionId, toolCallId: item.toolCallId, offset, limit: 1_048_576 })
      content += chunk.content
      offset = chunk.offset + new TextEncoder().encode(chunk.content).length
      if (!chunk.more) break
    }
    const target = state.items.find((candidate) => candidate.key === item.key)
    if (target && target.kind === 'tool') {
      target.content = content
      target.truncated = false
    }
  }

  return {
    sessionId,
    state,
    phase,
    error,
    commands,
    busy,
    contextPct,
    subscribe,
    unsubscribe,
    retain,
    release,
    send,
    rewindTo,
    searchFiles,
    cancel,
    respond,
    dequeue,
    refreshQueue,
    setMode,
    loadOlder,
    loadingOlder,
    olderError,
    loadFullOutput,
    loadCommands,
    /** Test seam: feed one event as if the connection routed it. */
    handle,
  }
}

export function describe(failure: unknown): string {
  if (failure instanceof ProtocolError) return failure.message
  if (failure instanceof Error) return failure.message
  return String(failure)
}

function define(sessionId: string) {
  return defineStore(`session/${sessionId}`, () => setup(sessionId))
}

const definitions = new Map<string, ReturnType<typeof define>>()
const liveStores = new Map<string, SessionStore>()

/** The store of one session (created on first use, one per session id). */
export function useSessionStore(sessionId: string) {
  let definition = definitions.get(sessionId)
  if (definition === undefined) {
    definition = define(sessionId)
    definitions.set(sessionId, definition)
  }
  const store = definition()
  liveStores.set(sessionId, store)
  return store
}

/** Drop a released session entirely: instance, pinia state, and definition. */
function disposeSessionStore(sessionId: string): void {
  const store = liveStores.get(sessionId)
  liveStores.delete(sessionId)
  definitions.delete(sessionId)
  const pinia = getActivePinia()
  if (pinia !== undefined) {
    // $dispose stops the store's effects; the two maps are Pinia's own
    // instance and state registries — deleting keeps `pinia.state.value`
    // from caching the transcript after the store itself is gone.
    delete pinia.state.value[`session/${sessionId}`]
    ;(pinia as unknown as { _s: Map<string, { $dispose(): void }> })._s.delete(`session/${sessionId}`)
  }
  store?.$dispose()
}

/** Test seam: how many session stores are alive. */
export function liveSessionStoreCount(): number {
  return liveStores.size
}

export type SessionStore = ReturnType<typeof useSessionStore>
