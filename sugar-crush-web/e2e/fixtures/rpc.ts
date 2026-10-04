import type { SugarCrushServer } from './server'

interface Envelope {
  sessionId: string | null
  seq?: number
  type: string
  data: Record<string, unknown>
}

/**
 * A second, scripted client: the way a script drives `sugarcrush serve`
 * (bearer token as the `sugarcrush.auth.<token>` subprotocol). The tests use
 * it to make things happen while the browser is not looking.
 */
export class RpcClient {
  private nextId = 0
  private readonly pending = new Map<string, { resolve: (v: unknown) => void; reject: (e: unknown) => void }>()
  private readonly events: Envelope[] = []
  private readonly waiters: { match: (e: Envelope) => boolean; resolve: (e: Envelope) => void }[] = []

  private constructor(private readonly socket: WebSocket) {
    socket.onmessage = (message) => {
      const frame = JSON.parse(String(message.data)) as Record<string, unknown>
      if (typeof frame.id === 'string' && this.pending.has(frame.id)) {
        const entry = this.pending.get(frame.id)
        this.pending.delete(frame.id)
        if (frame.error) entry?.reject(frame.error)
        else entry?.resolve(frame.result)
        return
      }
      if (frame.method === 'event') {
        const envelope = frame.params as Envelope
        this.events.push(envelope)
        for (const waiter of [...this.waiters]) {
          if (waiter.match(envelope)) {
            this.waiters.splice(this.waiters.indexOf(waiter), 1)
            waiter.resolve(envelope)
          }
        }
      }
    }
  }

  static async connect(server: SugarCrushServer): Promise<RpcClient> {
    const socket = new WebSocket(`ws://127.0.0.1:${server.port}/ws`, ['sugarcrush.v1', `sugarcrush.auth.${server.token}`])
    await new Promise<void>((resolve, reject) => {
      socket.onopen = () => resolve()
      socket.onerror = () => reject(new Error('rpc socket failed'))
    })
    const client = new RpcClient(socket)
    await client.call('server.hello', { minProtocol: 1, maxProtocol: 1, client: { name: 'e2e', version: '0' } })
    return client
  }

  call<T = Record<string, unknown>>(method: string, params: Record<string, unknown> = {}): Promise<T> {
    const id = `e${++this.nextId}`
    return new Promise<T>((resolve, reject) => {
      this.pending.set(id, { resolve: resolve as (v: unknown) => void, reject })
      this.socket.send(JSON.stringify({ jsonrpc: '2.0', id, method, params }))
    })
  }

  /** The next event (seen or still to come) matching $match. */
  waitFor(match: (e: Envelope) => boolean, timeoutMs = 60_000): Promise<Envelope> {
    const seen = this.events.find(match)
    if (seen) return Promise.resolve(seen)
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('timed out waiting for an event')), timeoutMs)
      this.waiters.push({ match, resolve: (e) => {
        clearTimeout(timer)
        resolve(e)
      } })
    })
  }

  close(): void {
    this.socket.close()
  }
}
