import type { SocketLike } from '../protocol/client'
import type { Timers } from '../protocol/reconnect'

/** A WebSocket stand-in the tests drive by hand: open it, feed frames, close it. */
export class FakeSocket implements SocketLike {
  readyState = 0
  protocol = 'sugarcrush.v1'
  onopen: ((event: unknown) => void) | null = null
  onclose: ((event: { code: number; reason: string }) => void) | null = null
  onerror: ((event: unknown) => void) | null = null
  onmessage: ((event: { data: unknown }) => void) | null = null
  readonly sent: Record<string, unknown>[] = []
  closedWith: { code?: number; reason?: string } | null = null

  constructor(
    readonly url: string,
    readonly protocols: string[],
  ) {}

  send(data: string): void {
    this.sent.push(JSON.parse(data) as Record<string, unknown>)
  }

  close(code?: number, reason?: string): void {
    if (this.closedWith !== null) return
    this.closedWith = { code, reason }
    this.readyState = 3
    this.onclose?.({ code: code ?? 1000, reason: reason ?? '' })
  }

  open(): void {
    this.readyState = 1
    this.onopen?.({})
  }

  receive(frame: unknown): void {
    this.onmessage?.({ data: JSON.stringify(frame) })
  }

  /** The server closing the socket. */
  drop(code = 1006, reason = ''): void {
    this.readyState = 3
    this.onclose?.({ code, reason })
  }

  last(): Record<string, unknown> {
    const frame = this.sent[this.sent.length - 1]
    if (!frame) throw new Error('nothing sent')
    return frame
  }

  answer(result: unknown, id: unknown = this.last().id): void {
    this.receive({ jsonrpc: '2.0', id, result })
  }

  fail(code: number, kind: string, message = kind, id: unknown = this.last().id, extra: Record<string, unknown> = {}): void {
    this.receive({ jsonrpc: '2.0', id, error: { code, message, data: { kind, ...extra } } })
  }

  event(params: Record<string, unknown>): void {
    this.receive({ jsonrpc: '2.0', method: 'event', params })
  }
}

/** Timers advanced by hand. */
export class FakeTimers implements Timers {
  private time = 1_000_000
  private seq = 0
  private readonly queue = new Map<number, { at: number; fn: () => void }>()

  setTimeout(fn: () => void, ms: number): unknown {
    const id = ++this.seq
    this.queue.set(id, { at: this.time + ms, fn })
    return id
  }

  clearTimeout(handle: unknown): void {
    this.queue.delete(handle as number)
  }

  now(): number {
    return this.time
  }

  get pending(): number {
    return this.queue.size
  }

  advance(ms: number): void {
    const until = this.time + ms
    for (;;) {
      let next: [number, { at: number; fn: () => void }] | null = null
      for (const entry of this.queue) {
        if (entry[1].at <= until && (next === null || entry[1].at < next[1].at)) next = entry
      }
      if (next === null) break
      this.queue.delete(next[0])
      this.time = next[1].at
      next[1].fn()
    }
    this.time = until
  }
}

export function helloResult(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    protocol: 1,
    server: { version: '1.0.0', connectionId: 'c1', root: '/repo', pid: 1 },
    features: { methods: [], events: [] },
    limits: { tickIntervalMs: 15000 },
    principal: { kind: 'owner', scopes: ['read', 'write', 'approve', 'admin'] },
    defaults: { permissionMode: 'default' },
    resumed: {},
    ...overrides,
  }
}

export const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0))
