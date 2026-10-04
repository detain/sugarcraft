import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { login, logout, ticket, wsUrl } from '../protocol/auth'
import { SugarCrushClient, type ClientOptions, type ClientStatus, type HelloResult } from '../protocol/client'
import type { EventEnvelope, MethodName, MethodParams, MethodResult, Subscription } from '../protocol/generated'

export type { ClientStatus as ConnectionStatus }

const LABELS: Record<ClientStatus, string> = {
  idle: 'not connected',
  connecting: 'connecting…',
  connected: 'connected',
  reconnecting: 'reconnecting…',
  'signed-out': 'signed out',
  stopped: 'disconnected',
}

/**
 * What a followed session hands the connection: its cursor for `resume`,
 * its events, and what the handshake said about it after a reconnect.
 */
export interface SessionFollower {
  cursor(): number | null
  event(envelope: EventEnvelope): void
  resumed(result: Subscription | { error: string } | undefined): void
}

type Scheduler = (flush: () => void) => void

function defaultScheduler(flush: () => void): void {
  // A hidden tab gets no animation frames; approvals must still land there.
  if (typeof requestAnimationFrame === 'function' && !(typeof document !== 'undefined' && document.hidden)) {
    requestAnimationFrame(() => flush())
  } else {
    setTimeout(flush, 16)
  }
}

function instanceId(): string {
  const bytes = new Uint8Array(4)
  globalThis.crypto.getRandomValues(bytes)
  return 'tab-' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

/**
 * The one WebSocket this tab holds, and everything about it the UI shows:
 * status, the server's handshake, round-trip time, the next retry. Events are
 * queued as they arrive and applied once per animation frame (Appendix O
 * §7.5), to the session that follows them and to every global listener (the
 * sessions index, the approvals).
 */
export const useConnectionStore = defineStore('connection', () => {
  const status = ref<ClientStatus>('idle')
  const hello = shallowRef<HelloResult | null>(null)
  const rttMs = ref<number | null>(null)
  const retryAt = ref<number | null>(null)
  const lastError = ref<string | null>(null)
  const serverChanged = ref(false)
  const statusLabel = computed(() => LABELS[status.value])
  const connected = computed(() => status.value === 'connected')
  const scopes = computed(() => hello.value?.principal.scopes ?? [])

  let client: SugarCrushClient | null = null
  let overrides: Partial<ClientOptions> = {}
  let scheduler: Scheduler = defaultScheduler
  let queued: EventEnvelope[] = []
  let flushPending = false
  const followers = new Map<string, SessionFollower>()
  const listeners = new Set<(envelope: EventEnvelope) => void>()
  const helloListeners = new Set<(hello: HelloResult) => void>()

  function sync(source: SugarCrushClient): void {
    status.value = source.status
    rttMs.value = source.rttMs
    retryAt.value = source.retryAt
    lastError.value = source.lastError
    serverChanged.value = source.serverChanged
    if (source.hello !== hello.value) hello.value = source.hello
  }

  function flush(): void {
    flushPending = false
    const batch = queued
    queued = []
    for (const envelope of batch) {
      if (envelope.sessionId !== null) followers.get(envelope.sessionId)?.event(envelope)
      for (const listener of listeners) listener(envelope)
    }
  }

  function enqueue(envelope: EventEnvelope): void {
    queued.push(envelope)
    if (!flushPending) {
      flushPending = true
      scheduler(flush)
    }
  }

  function ensureClient(): SugarCrushClient {
    if (client !== null) return client
    const created = new SugarCrushClient({
      connectUrl: async () => wsUrl(await ticket()),
      client: { name: 'sugar-crush-web', version: __APP_VERSION__, instanceId: instanceId() },
      resume: () => {
        const cursors: Record<string, number> = {}
        for (const [id, follower] of followers) {
          const cursor = follower.cursor()
          if (cursor !== null) cursors[id] = cursor
        }
        return cursors
      },
      ...overrides,
    })
    created.onChange(sync)
    created.onEvent(enqueue)
    created.onHello((result) => {
      // Events that arrived before the handshake belong to the old socket's
      // stream; apply them first so a follower's cursor is current.
      flush()
      const resumed = result.resumed as Record<string, Subscription | { error: string }>
      for (const [id, follower] of followers) follower.resumed(resumed[id])
      for (const listener of helloListeners) listener(result)
    })
    client = created
    return created
  }

  /** Test seam: replace the transport, timers or ticket source before {@see start()}. */
  function configure(options: Partial<ClientOptions>, frameScheduler?: Scheduler): void {
    overrides = options
    if (frameScheduler) scheduler = frameScheduler
    client?.stop()
    client = null
  }

  function start(): void {
    ensureClient().start()
  }

  function stop(): void {
    client?.stop()
  }

  /** Reconnect at once instead of waiting out the backoff. */
  function retryNow(): void {
    ensureClient().start()
  }

  async function signIn(credential: { code: string } | { token: string }): Promise<void> {
    await login(credential)
    lastError.value = null
    ensureClient().start()
  }

  async function signOut(): Promise<void> {
    try {
      await logout()
    } finally {
      client?.stop()
      status.value = 'signed-out'
    }
  }

  function request<M extends MethodName>(method: M, params: MethodParams<M>): Promise<MethodResult<M>> {
    return ensureClient().request(method, params)
  }

  function follow(sessionId: string, follower: SessionFollower): () => void {
    followers.set(sessionId, follower)
    return () => {
      if (followers.get(sessionId) === follower) followers.delete(sessionId)
    }
  }

  function onEvent(listener: (envelope: EventEnvelope) => void): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  }

  function onHello(listener: (hello: HelloResult) => void): () => void {
    helloListeners.add(listener)
    return () => helloListeners.delete(listener)
  }

  /** Apply every queued event now (tests, and before a navigation reads state). */
  function flushNow(): void {
    flush()
  }

  return {
    status,
    statusLabel,
    connected,
    hello,
    scopes,
    rttMs,
    retryAt,
    lastError,
    serverChanged,
    configure,
    start,
    stop,
    retryNow,
    signIn,
    signOut,
    request,
    follow,
    onEvent,
    onHello,
    flushNow,
  }
})
