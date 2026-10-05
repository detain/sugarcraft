import { FakeSocket, FakeTimers, flush, helloResult } from '../../../__tests__/fakes'
import { Backoff } from '../../../protocol/reconnect'
import { useConnectionStore } from '../../connection'

/**
 * A connection over a {@see FakeSocket}, said hello to, events applied at
 * once (no animation frame): what the agents stores and panels are tested on.
 */
export class Wire {
  readonly sockets: FakeSocket[] = []
  readonly timers = new FakeTimers()
  private seq = 0

  socket(): FakeSocket {
    const last = this.sockets[this.sockets.length - 1]
    if (!last) throw new Error('no socket')
    return last
  }

  /** Configure the connection store; $before runs before the handshake (create the stores that listen). */
  async connect(before: () => void = () => {}, hello: Record<string, unknown> = {}): Promise<ReturnType<typeof useConnectionStore>> {
    const connection = useConnectionStore()
    connection.configure({
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const created = new FakeSocket(url, protocols)
        this.sockets.push(created)
        return created
      },
      timers: this.timers,
      backoff: new Backoff({ random: () => 0.5 }),
    }, (fn) => fn())
    before()
    connection.start()
    await flush()
    this.socket().open()
    this.socket().answer(helloResult(hello), 'hello')
    await flush()
    return connection
  }

  /** The frames sent with $method, oldest first. */
  sent(method: string): Record<string, unknown>[] {
    return this.socket().sent.filter((frame) => frame.method === method)
  }

  /** Answer the newest unanswered $method request with $result. */
  async answer(method: string, result: unknown): Promise<void> {
    const frame = this.sent(method).pop()
    if (!frame) throw new Error(`no ${method} sent`)
    this.socket().answer(result, frame.id)
    await flush()
  }

  async fail(method: string, code: number, kind: string, message = kind): Promise<void> {
    const frame = this.sent(method).pop()
    if (!frame) throw new Error(`no ${method} sent`)
    this.socket().fail(code, kind, message, frame.id)
    await flush()
  }

  /** A session event (durable unless $durable is false). */
  event(sessionId: string | null, type: string, data: Record<string, unknown>, durable = true, seq?: number): void {
    const stamp = durable ? { seq: seq ?? ++this.seq } : {}
    this.socket().event({ sessionId, ...stamp, type, ts: 1, durable, data })
  }
}
