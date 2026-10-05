import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { BackgroundSession, Events } from '../../protocol/generated'
import { useConnectionStore } from '../connection'
import { describe, type Delivery } from '../session'

export interface BackgroundOutput {
  text: string
  offset: number
  total: number
  loading: boolean
  error: string | null
}

/** Bytes of a background session's output the panel keeps (the tail). */
const OUTPUT_KEEP = 262_144

export const SETTLED = ['completed', 'failed', 'stopped', 'timed_out'] as const

export function isSettled(session: BackgroundSession): boolean {
  return (SETTLED as readonly string[]).includes(session.status)
}

/**
 * The workspace's background (`/bg`) sessions (roadmap O-4b, shown by O-6c):
 * `bg.list` on every handshake, then the server-scope `bg.started` /
 * `bg.status` / `bg.completed` events. Output is read on demand
 * (`bg.output`, from an offset); a settled session's answer can be sent into
 * a session (`bg.inject`).
 */
export const useBackgroundStore = defineStore('background', () => {
  const connection = useConnectionStore()
  const byId = ref<Record<string, BackgroundSession>>({})
  const outputs = ref<Record<string, BackgroundOutput>>({})
  const error = ref<string | null>(null)

  const items = computed(() => Object.values(byId.value).sort((a, b) => b.createdAt.localeCompare(a.createdAt) || a.bgId.localeCompare(b.bgId)))
  const running = computed(() => items.value.filter((session) => !isSettled(session)).length)

  function put(session: BackgroundSession): void {
    byId.value = { ...byId.value, [session.bgId]: { ...byId.value[session.bgId], ...session } }
  }

  async function load(): Promise<void> {
    try {
      const result = await connection.request('bg.list', {})
      const next: Record<string, BackgroundSession> = {}
      for (const session of result.items) next[session.bgId] = session
      byId.value = next
      error.value = null
    } catch (failure) {
      error.value = describe(failure)
    }
  }

  function output(bgId: string): BackgroundOutput {
    return outputs.value[bgId] ?? { text: '', offset: 0, total: 0, loading: false, error: null }
  }

  /** Read what $bgId has written past what this panel holds. */
  async function loadOutput(bgId: string): Promise<void> {
    const current = output(bgId)
    if (current.loading) return
    outputs.value = { ...outputs.value, [bgId]: { ...current, loading: true } }
    let next: BackgroundOutput = { ...current, loading: false, error: null }
    try {
      for (let page = 0; page < 8; page++) {
        const result = await connection.request('bg.output', { bgId, offset: next.offset })
        const text = next.text + result.text
        next = { ...next, text: text.length > OUTPUT_KEEP ? text.slice(text.length - OUTPUT_KEEP) : text, offset: result.offset + new TextEncoder().encode(result.text).length, total: result.total }
        if (result.text === '' || next.offset >= result.total) break
      }
    } catch (failure) {
      next = { ...next, error: describe(failure) }
    }
    outputs.value = { ...outputs.value, [bgId]: next }
  }

  async function spawn(task: string, agent?: string): Promise<BackgroundSession> {
    const session = await connection.request('bg.spawn', agent ? { task, agent } : { task })
    put(session)
    return session
  }

  async function stop(bgId: string): Promise<void> {
    await connection.request('bg.stop', { bgId })
  }

  async function inject(bgId: string, sessionId: string, delivery: Delivery = 'queue'): Promise<void> {
    await connection.request('bg.inject', { bgId, sessionId, delivery })
  }

  connection.onHello(() => {
    void load()
  })

  connection.onEvent((envelope) => {
    switch (envelope.type) {
      case 'bg.started':
      case 'bg.completed':
        put(envelope.data as unknown as Events['bg.started'])
        break
      case 'bg.status': {
        const data = envelope.data as unknown as Events['bg.status']
        const current = byId.value[data.bgId]
        if (current) put({ ...current, status: data.status as BackgroundSession['status'] })
        else void load()
        break
      }
    }
  })

  return { byId, items, running, outputs, error, load, output, loadOutput, spawn, stop, inject }
})
