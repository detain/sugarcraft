import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Events, PendingAsk } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import { useConnectionStore } from './connection'

export type SessionAsk = PendingAsk & { sessionId: string }
export type Reply = 'once' | 'always' | 'reject'

/** One session's open questions, for the drawer. */
export interface SessionAsks {
  sessionId: string
  asks: SessionAsk[]
}

/**
 * Every open permission question across the server's open sessions — the
 * approvals drawer, the badge counts in the sidebar and tabs, and the tab
 * title (Appendix O §7.4 "attention model").
 *
 * The server tells every client of a question the moment it is put, in any
 * session, followed or not (`permission.asked`), and of its answer or
 * cancellation (`permission.settled`); a followed session's own
 * `permission.requested` / `resolved` say the same and are idempotent with
 * them by `askId`. `permission.pending` seeds the set on every handshake and
 * re-reads it on every `server.tick` — a backstop for anything a dropped
 * socket missed, no longer how a question first shows.
 */
export const useApprovalsStore = defineStore('approvals', () => {
  const connection = useConnectionStore()
  const byAsk = ref<Record<string, SessionAsk>>({})
  const order = ref<string[]>([])
  const listeners = new Set<(ask: SessionAsk) => void>()

  /** Oldest first: the question that has waited longest leads. */
  const all = computed(() => order.value.map((id) => byAsk.value[id]).filter((ask): ask is SessionAsk => ask !== undefined))
  const total = computed(() => all.value.length)
  const bySession = computed<SessionAsks[]>(() => {
    const groups = new Map<string, SessionAsk[]>()
    for (const ask of all.value) {
      const group = groups.get(ask.sessionId)
      if (group) group.push(ask)
      else groups.set(ask.sessionId, [ask])
    }
    return [...groups].map(([sessionId, asks]) => ({ sessionId, asks }))
  })

  function forSession(sessionId: string): SessionAsk[] {
    return all.value.filter((ask) => ask.sessionId === sessionId)
  }

  function countFor(sessionId: string): number {
    return forSession(sessionId).length
  }

  function add(ask: SessionAsk): void {
    const isNew = byAsk.value[ask.askId] === undefined
    byAsk.value = { ...byAsk.value, [ask.askId]: ask }
    if (isNew) {
      order.value = [...order.value, ask.askId]
      for (const listener of listeners) listener(ask)
    }
  }

  function remove(askId: string): void {
    if (byAsk.value[askId] === undefined) return
    const next = { ...byAsk.value }
    delete next[askId]
    byAsk.value = next
    order.value = order.value.filter((id) => id !== askId)
  }

  async function refresh(): Promise<void> {
    try {
      const result = await connection.request('permission.pending', {})
      const next: Record<string, SessionAsk> = {}
      for (const item of result.items as SessionAsk[]) {
        if (typeof item.sessionId === 'string') next[item.askId] = item
      }
      const fresh = Object.values(next).filter((ask) => byAsk.value[ask.askId] === undefined)
      byAsk.value = next
      order.value = [...order.value.filter((id) => next[id] !== undefined), ...fresh.map((ask) => ask.askId)]
      for (const ask of fresh) for (const listener of listeners) listener(ask)
    } catch {
      // keep what is known; the next tick retries
    }
  }

  /**
   * Answer $ask from anywhere (the drawer). An `already_resolved` or
   * `ask_not_found` refusal means another client won or the turn ended: the
   * question is gone either way, so it leaves the drawer.
   */
  async function respond(ask: SessionAsk, reply: Reply, options: { cascade?: boolean; note?: string } = {}): Promise<void> {
    try {
      await connection.request('permission.respond', { sessionId: ask.sessionId, askId: ask.askId, reply, ...options })
      remove(ask.askId)
    } catch (failure) {
      if (failure instanceof ProtocolError && (failure.kind === 'already_resolved' || failure.kind === 'ask_not_found')) {
        remove(ask.askId)
        return
      }
      throw failure
    }
  }

  /** Called once per newly seen question (desktop notifications hang off this). */
  function onNew(listener: (ask: SessionAsk) => void): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  }

  connection.onHello(() => {
    void refresh()
  })

  connection.onEvent((envelope) => {
    switch (envelope.type) {
      case 'server.tick':
        void refresh()
        break
      case 'permission.asked': {
        const data = envelope.data as unknown as Events['permission.asked']
        if (typeof data.sessionId === 'string') add(data)
        break
      }
      case 'permission.settled':
        remove((envelope.data as unknown as Events['permission.settled']).askId)
        break
      case 'permission.requested':
        if (envelope.sessionId !== null) add({ ...(envelope.data as unknown as Events['permission.requested']), sessionId: envelope.sessionId })
        break
      case 'permission.resolved':
        remove((envelope.data as unknown as Events['permission.resolved']).askId)
        break
      case 'turn.completed':
        // Questions still open when a turn ends are resolved `cancelled` by the server.
        if (envelope.sessionId !== null) for (const ask of forSession(envelope.sessionId)) remove(ask.askId)
        break
    }
  })

  return { byAsk, all, total, bySession, forSession, countFor, refresh, respond, remove, onNew }
})
