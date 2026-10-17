import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Events, MethodResult } from '../../protocol/generated'
import { useConnectionStore } from '../connection'
import { describe } from '../session'

export type WorkflowRun = MethodResult<'workflow.runs'>['items'][number]

/**
 * The workflows a session can run and the runs it has seen (roadmap O-6c):
 * `workflow.list` on every handshake; `workflow.runs` per session when a panel
 * shows it, and again whenever that session's turn ends or a `Workflow` tool
 * call starts or finishes — a `/workflow` run is a turn, and the model's own
 * `Workflow` tool runs are tool calls.
 */
export const useWorkflowsStore = defineStore('workflows', () => {
  const connection = useConnectionStore()
  const available = ref(false)
  const names = ref<string[]>([])
  const runs = ref<Record<string, WorkflowRun[]>>({})
  const error = ref<string | null>(null)
  const watched = new Set<string>()

  async function load(): Promise<void> {
    try {
      const result = await connection.request('workflow.list', {})
      available.value = result.available
      names.value = result.items.map((item) => item.name)
      error.value = null
    } catch (failure) {
      error.value = describe(failure)
    }
  }

  function runsFor(sessionId: string): WorkflowRun[] {
    return runs.value[sessionId] ?? []
  }

  /** Read $sessionId's runs, and keep them current from its events from now on. */
  async function loadRuns(sessionId: string): Promise<void> {
    watched.add(sessionId)
    try {
      const result = await connection.request('workflow.runs', { sessionId })
      runs.value = { ...runs.value, [sessionId]: result.items }
    } catch (failure) {
      error.value = describe(failure)
    }
  }

  /** Start workflow $name in $sessionId; $vars become its `key=value` context. */
  async function run(sessionId: string, name: string, vars: Record<string, string> = {}): Promise<void> {
    await connection.request('workflow.run', Object.keys(vars).length === 0 ? { sessionId, name } : { sessionId, name, vars })
    void loadRuns(sessionId)
  }

  async function pause(workflowId: string): Promise<string> {
    return (await connection.request('workflow.pause', { workflowId })).status
  }

  async function resume(sessionId: string, workflowId: string): Promise<void> {
    await connection.request('workflow.resume', { sessionId, workflowId })
    void loadRuns(sessionId)
  }

  connection.onHello(() => {
    void load()
  })

  connection.onEvent((envelope) => {
    const sessionId = envelope.sessionId
    if (sessionId === null || !watched.has(sessionId)) return
    if (envelope.type === 'turn.completed') {
      void loadRuns(sessionId)
    } else if (envelope.type === 'tool.started' || envelope.type === 'tool.finished') {
      if ((envelope.data as unknown as Events['tool.started']).name === 'Workflow') void loadRuns(sessionId)
    }
  })

  return { available, names, runs, error, load, runsFor, loadRuns, run, pause, resume }
})

/**
 * `key=value` pairs from what a user typed (whitespace-separated), or the
 * reason they cannot be sent: a workflow's context values are single words.
 */
export function parseVars(text: string): Record<string, string> | string {
  const vars: Record<string, string> = {}
  for (const pair of text.trim().split(/\s+/).filter((part) => part !== '')) {
    const at = pair.indexOf('=')
    if (at <= 0 || at === pair.length - 1) return `"${pair}" is not key=value`
    const name = pair.slice(0, at)
    if (!/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/.test(name)) return `"${name}" is not a valid name`
    vars[name] = pair.slice(at + 1)
  }
  return vars
}
