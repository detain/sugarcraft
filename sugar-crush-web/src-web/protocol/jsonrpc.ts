import type { EventEnvelope, EventName, Events } from './generated'

/** A JSON-RPC 2.0 frame the server sends: an answer or an `event` notification. */
export interface RpcResponse {
  jsonrpc: '2.0'
  id: string | number | null
  result?: unknown
  error?: { code: number; message: string; data?: { kind?: string; retryable?: boolean; retryAfterMs?: number; [key: string]: unknown } }
}

export interface RpcNotification {
  jsonrpc: '2.0'
  method: string
  params?: unknown
}

/** One event, typed by its `type` when it is one this client knows. */
export type ProtocolEvent<E extends EventName = EventName> = Omit<EventEnvelope, 'type' | 'data'> & {
  type: E
  data: Events[E]
}

/**
 * A request the server answered with an error, or one that never got an
 * answer. Branch on {@see kind} — the protocol's machine-readable reason —
 * never on the message (SERVER.md "Errors").
 */
export class ProtocolError extends Error {
  readonly code: number
  readonly kind: string
  readonly retryable: boolean
  readonly data: Record<string, unknown>

  constructor(code: number, message: string, kind: string, data: Record<string, unknown> = {}) {
    super(message)
    this.name = 'ProtocolError'
    this.code = code
    this.kind = kind
    this.retryable = data.retryable === true
    this.data = data
  }

  /** Raised locally: the socket closed before the answer came. */
  static disconnected(): ProtocolError {
    return new ProtocolError(0, 'the connection to the server was lost', 'disconnected')
  }

  static fromResponse(error: NonNullable<RpcResponse['error']>): ProtocolError {
    const data = error.data ?? {}
    return new ProtocolError(error.code, error.message, typeof data.kind === 'string' ? data.kind : 'error', data)
  }
}

/** Whether a decoded frame is an `event` notification. */
export function isEvent(frame: unknown): frame is RpcNotification & { params: EventEnvelope } {
  if (typeof frame !== 'object' || frame === null) return false
  const f = frame as Record<string, unknown>
  return f.method === 'event' && typeof f.params === 'object' && f.params !== null && !('id' in f)
}

/** Whether a decoded frame answers a request. */
export function isResponse(frame: unknown): frame is RpcResponse {
  if (typeof frame !== 'object' || frame === null) return false
  const f = frame as Record<string, unknown>
  return 'id' in f && ('result' in f || 'error' in f)
}

/** The number of UTF-8 bytes in $text: delta offsets are byte offsets. */
export function utf8Length(text: string): number {
  let bytes = 0
  for (let i = 0; i < text.length; i++) {
    const code = text.charCodeAt(i)
    if (code < 0x80) bytes += 1
    else if (code < 0x800) bytes += 2
    else if (code >= 0xd800 && code <= 0xdbff && i + 1 < text.length) {
      bytes += 4
      i++
    } else bytes += 3
  }
  return bytes
}
