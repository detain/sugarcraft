import { defineStore } from 'pinia'
import { computed, reactive, ref } from 'vue'
import { SeqCursor } from '../protocol/cursor'
import type { EventEnvelope, MethodResult, PermissionMode, Subscription } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import { useConnectionStore } from './connection'
import { applyEvent, applyPendingAsks, applySnapshot, createState, type ToolItem } from './reducer'

export type Delivery = 'queue' | 'steer' | 'interrupt'
export type Phase = 'idle' | 'subscribing' | 'live'

export interface CommandInfo {
  name: string
  description?: string
  argumentHint?: string | null
  source: 'builtin' | 'file'
  runsIn: 'server' | 'client'
}

/** How long a session keeps following after its view closes (Appendix O §7.5). */
export const RELEASE_GRACE_MS = 30_000

function setup(sessionId: string) {
  const connection = useConnectionStore()
  const state = reactive(createState(sessionId))
  const phase = ref<Phase>('idle')
  const error = ref<string | null>(null)
  const commands = ref<CommandInfo[]>([])
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
      if (holders === 0) void unsubscribe()
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
  async function send(text: string, delivery: Delivery = 'queue'): Promise<MethodResult<'session.send'> | MethodResult<'command.exec'>> {
    const slash = /^\/([A-Za-z0-9_:.-]+)(?:\s+([\s\S]*))?$/.exec(text.trim())
    if (slash) {
      const name = slash[1] ?? ''
      const command = commands.value.find((c) => c.name === name || c.name === `/${name}`)
      if (command && command.source === 'builtin') {
        if (command.runsIn === 'client') {
          throw new ProtocolError(-32030, `/${name} runs only in the terminal UI`, 'ui_only')
        }
        const args = (slash[2] ?? '').trim()
        const result = await connection.request('command.exec', args === '' ? { sessionId, name } : { sessionId, name, args })
        // A command rewrites the transcript in place (`/clear`, `/rewind`, …)
        // and that is no event: take a fresh snapshot.
        if ('rows' in result) await subscribe(true)
        return result
      }
    }
    return connection.request('session.send', busy.value ? { sessionId, text, delivery } : { sessionId, text })
  }

  function cancel(mode: 'hard' | 'soft' = 'hard', clearQueue = false): Promise<MethodResult<'session.cancel'>> {
    return connection.request('session.cancel', { sessionId, mode, clearQueue })
  }

  /** Answer a question; an `already_resolved` refusal means another client won, and closes the card. */
  async function respond(askId: string, reply: 'once' | 'always' | 'reject', options: { cascade?: boolean; note?: string } = {}): Promise<void> {
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

  async function setMode(permissionMode: PermissionMode): Promise<void> {
    const result = await connection.request('session.setMode', { sessionId, permissionMode })
    state.permissionMode = result.permissionMode
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
    cancel,
    respond,
    dequeue,
    setMode,
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

/** The store of one session (created on first use, one per session id). */
export function useSessionStore(sessionId: string) {
  let definition = definitions.get(sessionId)
  if (definition === undefined) {
    definition = define(sessionId)
    definitions.set(sessionId, definition)
  }
  return definition()
}

export type SessionStore = ReturnType<typeof useSessionStore>
