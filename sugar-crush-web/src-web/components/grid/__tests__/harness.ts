import { FakeSocket, FakeTimers, flush, helloResult } from '../../../__tests__/fakes'
import { Backoff } from '../../../protocol/reconnect'
import { useApprovalsStore } from '../../../stores/approvals'
import { useConnectionStore } from '../../../stores/connection'
import { useLayoutStore } from '../../../stores/layout'
import { useSessionsStore } from '../../../stores/sessions'

/**
 * A connected client over a {@see FakeSocket}, for the O-6a specs: the
 * connection, sessions, approvals and layout stores created, the handshake
 * answered. Events are applied synchronously (no animation frame).
 */
export class Wire {
  readonly sockets: FakeSocket[] = []
  readonly timers = new FakeTimers()
  private seq = 0

  get socket(): FakeSocket {
    const last = this.sockets[this.sockets.length - 1]
    if (!last) throw new Error('no socket')
    return last
  }

  static async connect(): Promise<Wire> {
    const wire = new Wire()
    const connection = useConnectionStore()
    connection.configure({
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const created = new FakeSocket(url, protocols)
        wire.sockets.push(created)
        return created
      },
      timers: wire.timers,
      backoff: new Backoff({ random: () => 0.5 }),
    }, (fn) => fn())
    useSessionsStore()
    useApprovalsStore()
    useLayoutStore()
    connection.start()
    await flush()
    wire.socket.open()
    wire.socket.answer(helloResult(), 'hello')
    await flush()
    return wire
  }

  /** The frames sent with $method, oldest first. */
  sent(method: string): Record<string, unknown>[] {
    return this.socket.sent.filter((frame) => frame.method === method)
  }

  /** Answer the newest unanswered $method request with $result. */
  answer(method: string, result: unknown): void {
    const frame = this.sent(method).pop()
    if (!frame) throw new Error(`no ${method} sent`)
    this.socket.answer(result, frame.id)
  }

  /** A session-scope event of $sessionId (durable ones numbered in order). */
  event(sessionId: string, type: string, data: Record<string, unknown>, durable = true): void {
    this.socket.event({ sessionId, ...(durable ? { seq: ++this.seq } : {}), type, ts: 1, durable, turnId: 't1', data })
  }

  /** A server-scope event. */
  serverEvent(type: string, data: Record<string, unknown>): void {
    this.socket.event({ sessionId: null, type, ts: 1, durable: false, data })
  }
}

export function ask(askId: string, sessionId: string, tool = 'Bash'): Record<string, unknown> {
  return {
    sessionId,
    askId,
    toolCallId: `call-${askId}`,
    tool,
    arguments: { command: `run ${askId}` },
    reason: 'needs approval',
    source: 'gate',
    mode: 'default',
    options: ['once', 'always', 'reject'],
    alwaysScope: { tool },
  }
}

export function summary(id: string, overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return { id, name: `session ${id}`, status: 'idle', open: true, preview: null, createdAt: '2026-10-05 10:00:00', updatedAt: '2026-10-05 10:00:00', ...overrides }
}
