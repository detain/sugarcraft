import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ProtocolError } from '../protocol/jsonrpc'
import { browserContext } from '../lib/clientTools/browserContext'
import { useConnectionStore } from './connection'

/** One tool this browser can run when the model asks. */
export interface ClientToolImplementation {
  /** Bare name, lower snake case — the announced tool is `client__` + this. */
  name: string
  description: string
  inputSchema: Record<string, unknown>
  timeoutSeconds?: number
  run: (args: Record<string, unknown>) => unknown | Promise<unknown>
}

/** The implementations every tab offers while the server has the door open. */
export const DEFAULT_CLIENT_TOOLS: ClientToolImplementation[] = [browserContext]

/** Prefix the server puts in front of every client-hosted name. */
const PROXY_PREFIX = 'client__'

/**
 * C-TOOL-6.i: `tools.client.*` joins the generated method-name union only
 * when the protocol trio regen lands with this step's weld (the lane never
 * carries the trio). Until then these two names cannot cross the typed
 * `request` boundary as {@link import('../protocol/generated').MethodName},
 * so the store's calls go through this one narrowed escape hatch — string
 * names, plain param objects — instead of a second untyped client.
 */
type ClientToolMethod = 'tools.client.register' | 'tools.client.result'

/**
 * Client-hosted tools for the tab (C-TOOL-6.i): the web half of the
 * `tools.client.*` round trip.
 *
 * After every successful session subscribe, {@see attach} announces the local
 * implementations; the server answers with a registration id that lives only
 * as long as this socket. When the model calls one, `tool.client.call` lands
 * on the session's feed and this store runs the handler under the event's
 * deadline and answers `tools.client.result`. Only the client holding the
 * registration the call was announced under takes the work — every other tab
 * that hears the same event ignores it, because its id does not match.
 *
 * A `client_tools_disabled` refusal (the server's `server.clientTools` is
 * off — the default) switches this store off for the connection without
 * retrying; a fresh handshake re-asks, so flipping the setting and
 * reconnecting is all it takes to come back.
 */
export const useClientToolsStore = defineStore('clientTools', () => {
  const connection = useConnectionStore()
  const implementations = new Map<string, ClientToolImplementation>(DEFAULT_CLIENT_TOOLS.map((tool) => [PROXY_PREFIX + tool.name, tool]))
  /** sessionId → the registration id this connection announced it under. */
  const registrations = new Map<string, string>()
  /** True while the server has refused the whole family for this connection. */
  const disabled = ref(false)
  const lastError = ref<string | null>(null)
  // Name matters: the dormant-roster guard derives browser callers by looking
  // for `request('method'` call sites, so this narrowed alias stays `request`.
  const request = connection.request as unknown as (method: ClientToolMethod, params: Record<string, unknown>) => Promise<Record<string, unknown>>

  function implement(tool: ClientToolImplementation): void {
    implementations.set(PROXY_PREFIX + tool.name, tool)
  }

  /** The announcement list the registration sends, in stable order. */
  function announcements(): Record<string, unknown>[] {
    return [...implementations.values()]
      .sort((left, right) => (left.name < right.name ? -1 : 1))
      .map((tool) => ({
        name: tool.name,
        description: tool.description,
        inputSchema: tool.inputSchema,
        ...(tool.timeoutSeconds === undefined ? {} : { timeoutSeconds: tool.timeoutSeconds }),
      }))
  }

  /** Announce the local tools for $sessionId; called after each successful subscribe. */
  async function attach(sessionId: string): Promise<void> {
    if (disabled.value || !connection.connected) return
    try {
      const result = await request('tools.client.register', { sessionId, tools: announcements() })
      // CW-14: an answer without a string id must not become the literal
      // 'undefined' in the map — every call that followed would answer
      // under a registration nobody issued. Same law as the deadline guard.
      const registrationId = result['registrationId']
      if (typeof registrationId !== 'string') {
        lastError.value = 'tools.client.register answered without a registration id'
        return
      }
      registrations.set(sessionId, registrationId)
      lastError.value = null
    } catch (failure) {
      if (failure instanceof ProtocolError && failure.kind === 'client_tools_disabled') {
        disabled.value = true
        return
      }
      lastError.value = failure instanceof Error ? failure.message : String(failure)
    }
  }

  function detach(sessionId: string): void {
    registrations.delete(sessionId)
  }

  function answer(sessionId: string, callId: string, registrationId: string, content: string, isError: boolean): void {
    if (!connection.connected) return
    void request('tools.client.result', { sessionId, callId, registrationId, content, isError }).catch(() => {
      // The engine already settled the call another way (another client
      // answered, or the deadline passed server-side). Nothing left to do.
    })
  }

  connection.onHello(() => {
    // A new socket means new registrations and a possibly new setting.
    registrations.clear()
    disabled.value = false
    lastError.value = null
  })

  connection.onEvent((envelope) => {
    if (envelope.type !== 'tool.client.call' || envelope.sessionId === null) return
    const sessionId = envelope.sessionId
    const data = envelope.data as { callId?: unknown; registrationId?: unknown; name?: unknown; arguments?: unknown; deadlineAt?: unknown }
    const mine = registrations.get(sessionId)
    if (mine === undefined || mine !== data.registrationId) return // another tab owns this call
    const tool = implementations.get(String(data.name))
    const callId = String(data.callId)
    const registrationId = String(data.registrationId)
    if (tool === undefined) {
      answer(sessionId, callId, registrationId, 'this tab no longer implements that tool', true)
      return
    }
    const deadlineAt = typeof data.deadlineAt === 'number' ? data.deadlineAt : 0
    const remaining = deadlineAt - Date.now()
    if (remaining <= 0) {
      answer(sessionId, callId, registrationId, 'the call had already reached its deadline when it arrived here', true)
      return
    }
    let timer: ReturnType<typeof setTimeout> | null = null
    const outcome = Promise.race([
      Promise.resolve()
        .then(() => tool.run((typeof data.arguments === 'object' && data.arguments !== null ? data.arguments : {}) as Record<string, unknown>))
        .then((value) => ({ kind: 'done' as const, value })),
      new Promise<{ kind: 'missed' }>((resolve) => {
        timer = setTimeout(() => resolve({ kind: 'missed' }), remaining)
      }),
    ])
    void outcome
      .then((settled) => {
        if (timer !== null) clearTimeout(timer)
        if (settled.kind === 'missed') {
          answer(sessionId, callId, registrationId, 'the tool did not finish before its deadline', true)
          return
        }
        const value = settled.value
        answer(sessionId, callId, registrationId, typeof value === 'string' ? value : JSON.stringify(value ?? null), false)
      })
      .catch((failure: unknown) => {
        if (timer !== null) clearTimeout(timer)
        answer(sessionId, callId, registrationId, failure instanceof Error ? failure.message : String(failure), true)
      })
  })

  return { disabled, lastError, implement, announcements, attach, detach, registrations, implementations }
})
