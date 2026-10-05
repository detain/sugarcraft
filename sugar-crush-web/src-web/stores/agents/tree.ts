/**
 * Delegated runs as the UI draws them (roadmap O-6c, Appendix P §4.3): the
 * `subagent.*` beats a session's turns produce — the P-B v2 wire shape,
 * `SubAgentActivity::toArray()` on the server — normalised into one typed row
 * per run, and arranged into the tree of who delegated to whom.
 *
 * Pure functions over plain data, so the merge rules are tested without a
 * store or a socket:
 * - a beat names its `task` and `description` only on `started`; a later beat
 *   that leaves them empty keeps the earlier ones;
 * - beats are ordered by the run's own `seq`, within one GENERATION of the
 *   run (`stats.startedAt`): a follow-up of a finished run starts a newer
 *   generation and replaces it, while a replayed older beat never moves a run
 *   backwards (finished → running).
 */

export type RunStatus = 'queued' | 'running' | 'done' | 'failed' | 'cancelled' | 'empty'

export interface RunCall {
  id: string
  label: string
  state: string
}

export interface AgentRun {
  id: string
  name: string
  task: string
  description: string
  op: string
  status: RunStatus
  seq: number
  /** The run's start (seconds since the epoch), its generation; 0 when unknown. */
  startedAt: number
  outcome: string
  error: string | null
  parentCallId: string
  parentAgentId: string | null
  model: string
  step: number
  maxSteps: number
  tools: number
  tokens: number
  cost: number
  tail: string
  calls: RunCall[]
  resumeId: string | null
  childSessionId: string | null
}

export interface AgentNode {
  run: AgentRun
  children: AgentNode[]
}

type Raw = Record<string, unknown>

function str(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function num(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : 0
}

function nullable(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function statusOf(op: string, outcome: string): RunStatus {
  if (op === 'queued') return 'queued'
  if (op !== 'finished') return 'running'
  switch (outcome) {
    case 'failed':
      return 'failed'
    case 'cancelled':
      return 'cancelled'
    case 'empty':
      return 'empty'
    default:
      return 'done'
  }
}

/** One beat as a typed run row, or null when it names no run. */
export function normalizeRun(beat: object): AgentRun | null {
  const raw = beat as Raw
  const id = str(raw.id)
  if (id === '') return null
  const op = str(raw.op) || 'progress'
  const outcome = str(raw.outcome)
  const stats = (raw.stats !== null && typeof raw.stats === 'object' ? raw.stats : {}) as Raw
  const calls = Array.isArray(raw.calls)
    ? raw.calls
        .filter((call): call is Raw => call !== null && typeof call === 'object')
        .map((call) => ({ id: str(call.id), label: str(call.label), state: str(call.state) }))
    : []
  return {
    id,
    name: str(raw.name) || id,
    task: str(raw.task),
    description: str(raw.description),
    op,
    status: statusOf(op, outcome),
    seq: num(raw.seq),
    startedAt: num(stats.startedAt),
    outcome,
    error: nullable(raw.error),
    parentCallId: str(raw.parentCallId),
    parentAgentId: nullable(raw.parentAgentId),
    model: str(raw.model),
    step: num(stats.step),
    maxSteps: num(stats.maxSteps),
    tools: num(stats.tools),
    tokens: num(raw.tokens) || num(stats.tokensIn) + num(stats.tokensOut),
    cost: num(raw.cost) || num(stats.costUsd),
    tail: str(raw.tail),
    calls,
    resumeId: nullable(raw.resumeId),
    childSessionId: nullable(raw.childSessionId),
  }
}

/** Whether $next is a newer beat of the run than $previous (see the module doc). */
export function isNewer(previous: AgentRun, next: AgentRun): boolean {
  if (next.startedAt !== previous.startedAt && next.startedAt !== 0 && previous.startedAt !== 0) {
    return next.startedAt > previous.startedAt
  }
  if (next.seq !== previous.seq) return next.seq > previous.seq
  // The same beat number: a finished beat outranks any other.
  return !(previous.op === 'finished' && next.op !== 'finished')
}

/** $next folded over $previous: the newer beat wins, the started-only fields survive. */
export function mergeRun(previous: AgentRun | undefined, next: AgentRun): AgentRun {
  if (previous === undefined) return next
  const [older, newer] = isNewer(previous, next) ? [previous, next] : [next, previous]
  return {
    ...newer,
    task: newer.task || older.task,
    description: newer.description || older.description,
    parentCallId: newer.parentCallId || older.parentCallId,
    model: newer.model || older.model,
  }
}

/**
 * The raw beat a reducer keeps: $next over $previous, keeping the fields only
 * `started` carries when $next leaves them empty.
 */
export function keepStarted<T extends object>(previous: T | undefined, next: T): T {
  if (previous === undefined) return next
  const before = previous as Raw
  const after = next as Raw
  const merged: Raw = { ...before, ...after }
  for (const field of ['task', 'description', 'parentCallId', 'model']) {
    if (str(after[field]) === '' && str(before[field]) !== '') merged[field] = before[field]
  }
  return merged as T
}

/** Every run of $sources folded by id, later sources over earlier ones by beat order. */
export function foldRuns(...sources: Iterable<object>[]): Record<string, AgentRun> {
  const runs: Record<string, AgentRun> = {}
  for (const source of sources) {
    for (const raw of source) {
      const run = normalizeRun(raw)
      if (run !== null) runs[run.id] = mergeRun(runs[run.id], run)
    }
  }
  return runs
}

/**
 * The runs as a forest: a run hangs under the run that delegated to it
 * (`parentAgentId`) when that run is known, else it is a root. Siblings keep
 * the order they started in. With $root (a `Task` call id, or a test), only
 * the roots it picks (and everything below them).
 */
export function buildTree(runs: AgentRun[], root?: string | ((run: AgentRun) => boolean)): AgentNode[] {
  const isRoot = root === undefined ? () => true : typeof root === 'string' ? (run: AgentRun) => run.parentCallId === root : root
  const byId = new Map(runs.map((run) => [run.id, run]))
  const children = new Map<string, AgentRun[]>()
  const roots: AgentRun[] = []
  for (const run of runs) {
    const parent = run.parentAgentId
    if (parent !== null && parent !== run.id && byId.has(parent)) {
      const list = children.get(parent) ?? []
      list.push(run)
      children.set(parent, list)
    } else {
      roots.push(run)
    }
  }
  const order = (a: AgentRun, b: AgentRun): number => a.startedAt - b.startedAt || a.id.localeCompare(b.id)
  const seen = new Set<string>()
  const grow = (run: AgentRun): AgentNode => {
    seen.add(run.id)
    const below = (children.get(run.id) ?? []).filter((child) => !seen.has(child.id)).sort(order)
    return { run, children: below.map(grow) }
  }
  return roots
    .filter(isRoot)
    .sort(order)
    .map(grow)
}

/**
 * Whether $run was started by tool call $call: its beats name the call
 * (`parentCallId`) — or, when a beat names none, a `Task` call that asked
 * the same agent for the same description.
 */
export function startedBy(run: AgentRun, call: { toolCallId: string; name: string; arguments: Record<string, unknown> }): boolean {
  if (run.parentCallId !== '') return run.parentCallId === call.toolCallId
  if (call.name !== 'Task' || run.parentAgentId !== null) return false
  const agent = str(call.arguments.agent) || str(call.arguments.subagent_type)
  return agent !== '' && run.name === agent && run.description === str(call.arguments.description)
}

/** How many runs of $nodes (at any depth) are still going. */
export function activeCount(nodes: AgentNode[]): number {
  let count = 0
  for (const node of nodes) {
    if (node.run.status === 'running' || node.run.status === 'queued') count++
    count += activeCount(node.children)
  }
  return count
}

/** The status glyph the tree, the cards and the agent view share. */
export function statusIcon(status: RunStatus): string {
  switch (status) {
    case 'queued':
      return '◌'
    case 'running':
      return '●'
    case 'done':
      return '✔'
    case 'cancelled':
      return '■'
    case 'empty':
      return '○'
    default:
      return '✖'
  }
}
