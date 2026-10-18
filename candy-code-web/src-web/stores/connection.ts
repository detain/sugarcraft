import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { login, logout, ticket, wsUrl } from '../protocol/auth'
import { CandyCodeClient, type ClientOptions, type ClientStatus, type HelloResult } from '../protocol/client'
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
 * its events, what the handshake said about it after a reconnect, and the
 * way back when the server drops this client's queue (ISS-052): `resubscribe`
 * re-follows from the cursor the session already holds.
 */
export interface SessionFollower {
  cursor(): number | null
  event(envelope: EventEnvelope): void
  resumed(result: Subscription | { error: string } | undefined): void
  resubscribe(): void
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
  /**
   * The reconnect ladder ran out (ISS-052): the banner names the server gone
   * and offers the retry, instead of "reconnecting…" counting down forever.
   */
  const exhausted = ref(false)
  /**
   * The last `server.shutdown` seen (ISS-052): the banner names the reason
   * and the grace the server asked for; a fresh handshake clears it.
   */
  const shutdown = shallowRef<{ reason: string; graceSeconds: number } | null>(null)
  const statusLabel = computed(() => LABELS[status.value])
  const connected = computed(() => status.value === 'connected')
  const scopes = computed(() => hello.value?.principal.scopes ?? [])
  /**
   * What the composer offers first for a prompt sent while a turn runs: the
   * server's `queueMode` setting, as the terminal's Enter reads it — `steer`
   * unless the server says otherwise (decision D6).
   */
  const defaultDelivery = computed<NonNullable<MethodParams<'session.send'>['delivery']>>(() => hello.value?.defaults?.delivery ?? 'steer')

  /**
   * The server's media readiness, read once from the handshake (W6.7): the
   * flags answer "may this launch offer generation" WITHOUT the browser ever
   * dialing the SD server. `null` means the server predates the field — say
   * nothing, offer nothing (hide affordances, never guess). `baseUrlHostOnly`
   * is a sha256 prefix, never a hostname (plan :839).
   */
  const capabilitiesMedia = computed(() => hello.value?.features?.media ?? null)
  const canGenerateImage = computed(() => capabilitiesMedia.value?.image === true)
  const canGenerateVideo = computed(() => capabilitiesMedia.value?.video === true)

  let client: CandyCodeClient | null = null
  let overrides: Partial<ClientOptions> = {}
  let scheduler: Scheduler = defaultScheduler
  let queued: EventEnvelope[] = []
  let flushPending = false
  const followers = new Map<string, SessionFollower>()
  const listeners = new Set<(envelope: EventEnvelope) => void>()
  const helloListeners = new Set<(hello: HelloResult) => void>()

  function sync(source: CandyCodeClient): void {
    status.value = source.status
    rttMs.value = source.rttMs
    retryAt.value = source.retryAt
    lastError.value = source.lastError
    serverChanged.value = source.serverChanged
    exhausted.value = source.exhausted
    if (source.hello !== hello.value) hello.value = source.hello
  }

  function flush(): void {
    flushPending = false
    const batch = queued
    queued = []
    for (const envelope of batch) {
      handleServerEvent(envelope)
      if (envelope.sessionId !== null) followers.get(envelope.sessionId)?.event(envelope)
      for (const listener of listeners) listener(envelope)
    }
  }

  /**
   * The two server-scope events no session owns (ISS-052). An overflow means
   * this client's queue was dropped for one session (`data.sessionId`) or for
   * the whole socket: follow again from every held cursor before the silence
   * reads as a dead pane. A shutdown is named and counted for the banner.
   */
  function handleServerEvent(envelope: EventEnvelope): void {
    switch (envelope.type) {
      case 'server.overflow': {
        const sessionId = envelope.data.sessionId
        if (typeof sessionId === 'string') followers.get(sessionId)?.resubscribe()
        else for (const follower of followers.values()) follower.resubscribe()
        return
      }
      case 'server.shutdown': {
        const reason = typeof envelope.data.reason === 'string' ? envelope.data.reason : ''
        const graceSeconds = typeof envelope.data.graceSeconds === 'number' ? envelope.data.graceSeconds : 0
        shutdown.value = { reason, graceSeconds }
        return
      }
      default:
        return
    }
  }

  function enqueue(envelope: EventEnvelope): void {
    queued.push(envelope)
    if (!flushPending) {
      flushPending = true
      scheduler(flush)
    }
  }

  function ensureClient(): CandyCodeClient {
    if (client !== null) return client
    const created = new CandyCodeClient({
      connectUrl: async () => wsUrl(await ticket()),
      client: { name: 'candy-code-web', version: __APP_VERSION__, instanceId: instanceId() },
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
      shutdown.value = null
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
    defaultDelivery,
    capabilitiesMedia,
    canGenerateImage,
    canGenerateVideo,
    rttMs,
    retryAt,
    lastError,
    serverChanged,
    exhausted,
    shutdown,
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
