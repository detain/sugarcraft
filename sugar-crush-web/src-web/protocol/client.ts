import { AuthError } from './auth'
import { IDEMPOTENT_METHODS, PROTOCOL, type EventEnvelope, type MethodName, type MethodParams, type MethodResult } from './generated'
import { isEvent, isResponse, ProtocolError, type RpcResponse } from './jsonrpc'
import { Backoff, realTimers, Watchdog, type Timers } from './reconnect'

export const SUBPROTOCOL = 'sugarcrush.v1'

/**
 * - `connecting`: the first connect is under way;
 * - `connected`: the handshake is done, requests flow;
 * - `reconnecting`: the socket dropped and a retry is scheduled or running;
 * - `signed-out`: no valid cookie — the user must sign in (no retry);
 * - `stopped`: {@see SugarCrushClient.stop()} was called, or the server
 *   speaks a protocol this client does not.
 */
export type ClientStatus = 'idle' | 'connecting' | 'connected' | 'reconnecting' | 'signed-out' | 'stopped'

export type HelloResult = MethodResult<'server.hello'>

export interface SocketLike {
  readonly readyState: number
  readonly protocol: string
  onopen: ((event: unknown) => void) | null
  onclose: ((event: { code: number; reason: string }) => void) | null
  onerror: ((event: unknown) => void) | null
  onmessage: ((event: { data: unknown }) => void) | null
  send(data: string): void
  close(code?: number, reason?: string): void
}

export type SocketFactory = (url: string, protocols: string[]) => SocketLike

export interface ClientOptions {
  /** A fresh WebSocket URL (one ticket each); throws {@see AuthError} when signed out. */
  connectUrl: () => Promise<string>
  socket?: SocketFactory
  timers?: Timers
  backoff?: Backoff
  client?: { name: string; version: string; instanceId?: string }
  caps?: string[]
  /** sessionId => the last seq held, handed to `server.hello` as `resume`. */
  resume?: () => Record<string, number>
}

interface Pending {
  method: MethodName
  payload: Record<string, unknown>
  idempotent: boolean
  retried: boolean
  sentAt: number
  resolve: (value: unknown) => void
  reject: (error: unknown) => void
}

type Listener<T> = (value: T) => void

/** Close codes after which retrying cannot help: the cookie was revoked. */
const SIGNED_OUT_CODES = new Set([4001])
const HELLO_ID = 'hello'
const DEFAULT_TICK_MS = 15_000

/**
 * One WebSocket to `sugarcrush serve`, speaking `sugarcrush.v1` (Appendix O
 * §6, §7.6): JSON-RPC requests correlated by id, `event` notifications fanned
 * out to listeners, the `server.hello` handshake with resume cursors, a
 * liveness watchdog (no frame for two tick intervals means the socket is
 * dead), and reconnects on a jittered backoff with a fresh ticket each time.
 *
 * Requests in flight when the socket drops are refused `disconnected` —
 * except the side-effecting (idempotent) ones, which carry an
 * `idempotencyKey` and are sent once more after the reconnect: the server
 * answers a repeat key with the original answer, so a prompt resent after a
 * drop is never a second turn.
 */
export class SugarCrushClient {
  private readonly options: ClientOptions
  private readonly timers: Timers
  private readonly backoff: Backoff
  private readonly socketFactory: SocketFactory
  private readonly watchdog: Watchdog
  private socket: SocketLike | null = null
  private retryHandle: unknown = null
  private nextId = 0
  private readonly pending = new Map<string, Pending>()
  private readonly waiting: Pending[] = []
  private readonly eventListeners = new Set<Listener<EventEnvelope>>()
  private readonly changeListeners = new Set<Listener<SugarCrushClient>>()
  private readonly helloListeners = new Set<Listener<HelloResult>>()

  status: ClientStatus = 'idle'
  hello: HelloResult | null = null
  /** The version the first handshake reported; a different one later means the server was updated. */
  firstVersion: string | null = null
  rttMs: number | null = null
  retryAt: number | null = null
  lastError: string | null = null

  constructor(options: ClientOptions) {
    this.options = options
    this.timers = options.timers ?? realTimers
    this.backoff = options.backoff ?? new Backoff()
    this.socketFactory = options.socket ?? ((url, protocols) => new WebSocket(url, protocols) as unknown as SocketLike)
    this.watchdog = new Watchdog(this.timers, () => this.socket?.close(4000, 'silent'))
  }

  onEvent(listener: Listener<EventEnvelope>): () => void {
    this.eventListeners.add(listener)
    return () => this.eventListeners.delete(listener)
  }

  onChange(listener: Listener<SugarCrushClient>): () => void {
    this.changeListeners.add(listener)
    return () => this.changeListeners.delete(listener)
  }

  onHello(listener: Listener<HelloResult>): () => void {
    this.helloListeners.add(listener)
    return () => this.helloListeners.delete(listener)
  }

  /** Whether the first handshake's server version differs from the current one. */
  get serverChanged(): boolean {
    return this.firstVersion !== null && this.hello !== null && this.hello.server.version !== this.firstVersion
  }

  /** Connect (or reconnect now, skipping a scheduled wait). */
  start(): void {
    if (this.status === 'connected' || this.status === 'connecting') return
    this.cancelRetry()
    void this.connect()
  }

  /** Close for good: no reconnect, every waiting request refused. */
  stop(): void {
    this.cancelRetry()
    this.setStatus('stopped')
    this.watchdog.disarm()
    const socket = this.socket
    this.socket = null
    socket?.close(1000, 'stopped')
    this.failAll(ProtocolError.disconnected(), true)
  }

  /** Drop the socket now and reconnect on the usual schedule (a close the server did not send). */
  drop(): void {
    this.socket?.close(4000, 'dropped')
  }

  request<M extends MethodName>(method: M, params: MethodParams<M>): Promise<MethodResult<M>> {
    const idempotent = (IDEMPOTENT_METHODS as readonly string[]).includes(method)
    const payload: Record<string, unknown> = { ...(params as Record<string, unknown>) }
    if (idempotent && typeof payload.idempotencyKey !== 'string') {
      payload.idempotencyKey = randomKey()
    }

    return new Promise<MethodResult<M>>((resolve, reject) => {
      const entry: Pending = {
        method,
        payload,
        idempotent,
        retried: false,
        sentAt: 0,
        resolve: resolve as (value: unknown) => void,
        reject,
      }
      if (this.status === 'connected') {
        this.send(entry)
      } else if (idempotent && (this.status === 'connecting' || this.status === 'reconnecting')) {
        this.waiting.push(entry)
      } else {
        reject(ProtocolError.disconnected())
      }
    })
  }

  private async connect(): Promise<void> {
    this.setStatus(this.hello === null && this.backoff.attempts === 0 ? 'connecting' : 'reconnecting')
    let url: string
    try {
      url = await this.options.connectUrl()
    } catch (error) {
      if (error instanceof AuthError && error.signedOut) {
        this.lastError = error.message
        this.failAll(ProtocolError.disconnected(), true)
        this.setStatus('signed-out')
        return
      }
      this.lastError = error instanceof Error ? error.message : String(error)
      this.scheduleRetry()
      return
    }
    if (this.status === 'stopped') return

    let socket: SocketLike
    try {
      socket = this.socketFactory(url, [SUBPROTOCOL])
    } catch (error) {
      this.lastError = error instanceof Error ? error.message : String(error)
      this.scheduleRetry()
      return
    }
    this.socket = socket
    const sentAt = this.timers.now()

    socket.onopen = () => {
      if (this.socket !== socket) return
      this.watchdog.arm(2 * DEFAULT_TICK_MS)
      socket.send(JSON.stringify({
        jsonrpc: '2.0',
        id: HELLO_ID,
        method: 'server.hello',
        params: {
          minProtocol: PROTOCOL,
          maxProtocol: PROTOCOL,
          client: this.options.client ?? { name: 'sugar-crush-web', version: '0' },
          caps: this.options.caps ?? ['deltas', 'narration', 'diff.unified'],
          resume: this.options.resume?.() ?? {},
        },
      }))
    }
    socket.onmessage = (message) => {
      if (this.socket !== socket) return
      this.heard()
      let frame: unknown
      try {
        frame = JSON.parse(String(message.data))
      } catch {
        return
      }
      if (isResponse(frame) && frame.id === HELLO_ID) {
        this.helloAnswered(frame, sentAt)
      } else if (isResponse(frame)) {
        this.answered(frame)
      } else if (isEvent(frame)) {
        for (const listener of this.eventListeners) listener(frame.params)
      }
    }
    socket.onerror = () => {
      // onclose follows and decides.
    }
    socket.onclose = (event) => {
      if (this.socket !== socket) return
      this.socket = null
      this.watchdog.disarm()
      this.backoff.disconnected(this.timers.now())
      if (this.status === 'stopped') return

      this.failAll(ProtocolError.disconnected(), false)
      if (SIGNED_OUT_CODES.has(event.code)) {
        this.lastError = 'signed out by the server'
        this.failAll(ProtocolError.disconnected(), true)
        this.setStatus('signed-out')
        return
      }
      this.lastError = event.reason !== '' ? `connection closed (${event.code}: ${event.reason})` : `connection closed (${event.code})`
      this.scheduleRetry()
    }
  }

  private helloAnswered(frame: RpcResponse, sentAt: number): void {
    if (frame.error) {
      const error = ProtocolError.fromResponse(frame.error)
      this.lastError = error.message
      if (error.kind === 'unsupported_protocol') {
        this.stop()
        this.lastError = error.message
        this.emitChange()
        return
      }
      this.socket?.close(4000, 'hello refused')
      return
    }

    const hello = frame.result as HelloResult
    this.hello = hello
    this.firstVersion ??= hello.server.version
    this.rttMs = Math.max(0, this.timers.now() - sentAt)
    this.lastError = null
    this.retryAt = null
    this.backoff.connected(this.timers.now())
    const tick = typeof hello.limits.tickIntervalMs === 'number' && hello.limits.tickIntervalMs > 0 ? hello.limits.tickIntervalMs : DEFAULT_TICK_MS
    this.watchdog.arm(2 * tick)
    this.setStatus('connected')
    for (const listener of this.helloListeners) listener(hello)

    const queued = this.waiting.splice(0)
    for (const entry of queued) this.send(entry)
  }

  private answered(frame: RpcResponse): void {
    const id = String(frame.id)
    const entry = this.pending.get(id)
    if (!entry) return
    this.pending.delete(id)
    const rtt = this.timers.now() - entry.sentAt
    this.rttMs = this.rttMs === null ? rtt : Math.round(this.rttMs * 0.8 + rtt * 0.2)
    if (frame.error) {
      entry.reject(ProtocolError.fromResponse(frame.error))
    } else {
      entry.resolve(frame.result)
    }
  }

  private send(entry: Pending): void {
    const socket = this.socket
    if (socket === null) {
      entry.reject(ProtocolError.disconnected())
      return
    }
    const id = `r${++this.nextId}`
    entry.sentAt = this.timers.now()
    this.pending.set(id, entry)
    socket.send(JSON.stringify({ jsonrpc: '2.0', id, method: entry.method, params: entry.payload }))
  }

  private heard(): void {
    const tick = this.hello && typeof this.hello.limits.tickIntervalMs === 'number' ? this.hello.limits.tickIntervalMs : DEFAULT_TICK_MS
    this.watchdog.arm(2 * tick)
  }

  /**
   * Refuse the requests in flight. An idempotent one that was not yet
   * retried is kept for one resend after the reconnect, unless $all.
   */
  private failAll(error: ProtocolError, all: boolean): void {
    const inflight = [...this.pending.values()]
    this.pending.clear()
    for (const entry of inflight) {
      if (!all && entry.idempotent && !entry.retried) {
        entry.retried = true
        this.waiting.push(entry)
      } else {
        entry.reject(error)
      }
    }
    if (all) {
      for (const entry of this.waiting.splice(0)) entry.reject(error)
    }
  }

  private scheduleRetry(): void {
    if (this.status === 'stopped') return
    this.cancelRetry()
    const delay = this.backoff.next()
    this.retryAt = this.timers.now() + delay
    this.setStatus('reconnecting')
    this.retryHandle = this.timers.setTimeout(() => {
      this.retryHandle = null
      void this.connect()
    }, delay)
  }

  private cancelRetry(): void {
    if (this.retryHandle !== null) {
      this.timers.clearTimeout(this.retryHandle)
      this.retryHandle = null
    }
    this.retryAt = null
  }

  private setStatus(status: ClientStatus): void {
    this.status = status
    this.emitChange()
  }

  private emitChange(): void {
    for (const listener of this.changeListeners) listener(this)
  }
}

/** 32 hex characters; crypto.getRandomValues works outside secure contexts too. */
export function randomKey(): string {
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}
