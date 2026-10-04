import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Events, PendingAsk } from '../protocol/generated'
import { useConnectionStore } from './connection'

export type SessionAsk = PendingAsk & { sessionId: string }

/**
 * Every open permission question across the server's open sessions — the
 * badge counts in the sidebar and the tab title (Appendix O §7.4 "attention
 * model"). `permission.pending` seeds it on every handshake and on every
 * `server.tick`, so a question in a session this tab does not follow still
 * shows; the followed sessions' `permission.requested` / `resolved` events
 * keep it live in between.
 */
export const useApprovalsStore = defineStore('approvals', () => {
  const connection = useConnectionStore()
  const byAsk = ref<Record<string, SessionAsk>>({})
  const listeners = new Set<(ask: SessionAsk) => void>()

  const all = computed(() => Object.values(byAsk.value))
  const total = computed(() => all.value.length)

  function forSession(sessionId: string): SessionAsk[] {
    return all.value.filter((ask) => ask.sessionId === sessionId)
  }

  function countFor(sessionId: string): number {
    return forSession(sessionId).length
  }

  function add(ask: SessionAsk): void {
    const isNew = byAsk.value[ask.askId] === undefined
    byAsk.value = { ...byAsk.value, [ask.askId]: ask }
    if (isNew) for (const listener of listeners) listener(ask)
  }

  function remove(askId: string): void {
    if (byAsk.value[askId] === undefined) return
    const next = { ...byAsk.value }
    delete next[askId]
    byAsk.value = next
  }

  async function refresh(): Promise<void> {
    try {
      const result = await connection.request('permission.pending', {})
      const next: Record<string, SessionAsk> = {}
      for (const item of result.items as SessionAsk[]) {
        if (typeof item.sessionId === 'string') next[item.askId] = item
      }
      for (const ask of Object.values(next)) {
        if (byAsk.value[ask.askId] === undefined) for (const listener of listeners) listener(ask)
      }
      byAsk.value = next
    } catch {
      // keep what is known; the next tick retries
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
    if (envelope.type === 'server.tick') {
      void refresh()
    } else if (envelope.type === 'permission.requested' && envelope.sessionId !== null) {
      add({ ...(envelope.data as unknown as Events['permission.requested']), sessionId: envelope.sessionId })
    } else if (envelope.type === 'permission.resolved') {
      remove((envelope.data as unknown as Events['permission.resolved']).askId)
    } else if (envelope.type === 'turn.completed' && envelope.sessionId !== null) {
      // Questions still open when a turn ends are resolved `cancelled` by the server.
      for (const ask of forSession(envelope.sessionId)) remove(ask.askId)
    }
  })

  return { byAsk, all, total, forSession, countFor, refresh, remove, onNew }
})
