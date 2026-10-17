import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Events } from '../../protocol/generated'
import { useConnectionStore } from '../connection'
import { describe } from '../session'

export type TodoItem = Events['todo.updated']['items'][number]

export interface SessionTodos {
  items: TodoItem[]
  /** The seq of the `todo.updated` the list came from; 0 for a `todo.get` answer. */
  seq: number
  loaded: boolean
  error: string | null
}

/**
 * Each session's todo list, as its agent's `Todo` tool keeps it (roadmap
 * 3.C / O-6c): `todo.get` when a panel shows a session, then every durable
 * `todo.updated` the session's events carry — the whole list each time. A
 * replayed older event (lower seq) never replaces a newer list.
 */
export const useTodosStore = defineStore('todos', () => {
  const connection = useConnectionStore()
  const bySession = ref<Record<string, SessionTodos>>({})

  function forSession(sessionId: string): SessionTodos {
    return bySession.value[sessionId] ?? { items: [], seq: 0, loaded: false, error: null }
  }

  function put(sessionId: string, next: SessionTodos): void {
    bySession.value = { ...bySession.value, [sessionId]: next }
  }

  async function load(sessionId: string): Promise<void> {
    try {
      const result = await connection.request('todo.get', { sessionId })
      const current = forSession(sessionId)
      // An event that landed while the answer was on its way is newer.
      put(sessionId, current.seq > 0 && current.loaded ? { ...current, loaded: true } : { items: result.items, seq: current.seq, loaded: true, error: null })
    } catch (failure) {
      put(sessionId, { ...forSession(sessionId), error: describe(failure) })
    }
  }

  connection.onEvent((envelope) => {
    if (envelope.type !== 'todo.updated' || envelope.sessionId === null) return
    const current = forSession(envelope.sessionId)
    const seq = typeof envelope.seq === 'number' ? envelope.seq : current.seq
    if (seq < current.seq) return
    put(envelope.sessionId, { items: (envelope.data as unknown as Events['todo.updated']).items, seq, loaded: true, error: null })
  })

  return { bySession, forSession, load }
})
