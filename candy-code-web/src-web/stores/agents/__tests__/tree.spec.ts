import { describe, expect, it } from 'vitest'
import { activeCount, buildTree, foldRuns, isNewer, keepStarted, mergeRun, normalizeRun, startedBy, statusIcon, type AgentRun } from '../tree'

function beat(overrides: Record<string, unknown>): Record<string, unknown> {
  return { v: 2, op: 'progress', id: 'a1', name: 'reviewer', task: '', seq: 1, tail: '', parentCallId: 'c1', stats: { startedAt: 100 }, ...overrides }
}

function run(overrides: Record<string, unknown>): AgentRun {
  const normalized = normalizeRun(beat(overrides))
  if (normalized === null) throw new Error('no run')
  return normalized
}

describe('normalizeRun', () => {
  it('types a v2 beat', () => {
    const r = run({ op: 'finished', outcome: 'failed', error: 'boom', tokens: 1200, cost: 0.02, stats: { startedAt: 5, step: 3, maxSteps: 8 }, calls: [{ id: 'k', label: 'Read a.php', state: 'ok', at: 1 }], resumeId: 'res' })
    expect(r).toMatchObject({ id: 'a1', name: 'reviewer', status: 'failed', outcome: 'failed', error: 'boom', tokens: 1200, cost: 0.02, step: 3, maxSteps: 8, startedAt: 5, resumeId: 'res' })
    expect(r.calls).toEqual([{ id: 'k', label: 'Read a.php', state: 'ok' }])
  })

  it('maps ops and outcomes to a status, and refuses a beat with no id', () => {
    expect(run({ op: 'queued' }).status).toBe('queued')
    expect(run({ op: 'started' }).status).toBe('running')
    expect(run({ op: 'finished', outcome: 'complete' }).status).toBe('done')
    expect(run({ op: 'finished', outcome: 'cancelled' }).status).toBe('cancelled')
    expect(run({ op: 'finished', outcome: 'empty' }).status).toBe('empty')
    expect(normalizeRun({ op: 'started' })).toBeNull()
  })
})

describe('merging beats', () => {
  it('keeps what only started said, and takes the newer beat', () => {
    const merged = mergeRun(run({ op: 'started', task: 'review the parser', description: 'review', seq: 1 }), run({ seq: 2, tail: 'reading' }))
    expect(merged.task).toBe('review the parser')
    expect(merged.description).toBe('review')
    expect(merged.tail).toBe('reading')
    expect(merged.op).toBe('progress')
  })

  it('never moves a run backwards on a replayed older beat', () => {
    const finished = run({ op: 'finished', outcome: 'complete', seq: 5, task: 'x' })
    const replayed = run({ op: 'started', seq: 1, task: 'x' })
    expect(isNewer(finished, replayed)).toBe(false)
    expect(mergeRun(finished, replayed).status).toBe('done')
  })

  it('lets a follow-up (a newer generation) replace a finished run', () => {
    const finished = run({ op: 'finished', outcome: 'complete', seq: 9, stats: { startedAt: 100 } })
    const followUp = run({ op: 'started', seq: 1, stats: { startedAt: 200 } })
    expect(mergeRun(finished, followUp).status).toBe('running')
  })

  it('folds sources in order and keeps the started fields in raw beats too', () => {
    const runs = foldRuns([beat({ op: 'started', task: 'one', seq: 1 })], [beat({ seq: 2 }), beat({ id: 'a2', op: 'started', seq: 1 })])
    expect(Object.keys(runs)).toEqual(['a1', 'a2'])
    expect(runs.a1?.task).toBe('one')
    expect(keepStarted({ id: 'a1', task: 'one', tail: 'x' }, { id: 'a1', task: '', tail: 'y' })).toEqual({ id: 'a1', task: 'one', tail: 'y' })
  })
})

describe('buildTree', () => {
  const runs = [
    run({ id: 'root', op: 'started', stats: { startedAt: 1 } }),
    run({ id: 'child', op: 'started', parentAgentId: 'root', parentCallId: 'inner', stats: { startedAt: 2 } }),
    run({ id: 'grand', op: 'finished', outcome: 'complete', parentAgentId: 'child', parentCallId: 'deeper', stats: { startedAt: 3 } }),
    run({ id: 'other', op: 'started', parentCallId: 'c2', stats: { startedAt: 4 } }),
    run({ id: 'orphan', op: 'started', parentAgentId: 'gone', parentCallId: 'c3', stats: { startedAt: 5 } }),
  ]

  it('nests a run under the run that delegated to it', () => {
    const tree = buildTree(runs)
    expect(tree.map((n) => n.run.id)).toEqual(['root', 'other', 'orphan'])
    expect(tree[0]?.children.map((n) => n.run.id)).toEqual(['child'])
    expect(tree[0]?.children[0]?.children.map((n) => n.run.id)).toEqual(['grand'])
    expect(activeCount(tree)).toBe(4)
  })

  it('keeps only the roots one Task call started', () => {
    const tree = buildTree(runs, 'c1')
    expect(tree.map((n) => n.run.id)).toEqual(['root'])
    expect(tree[0]?.children[0]?.children[0]?.run.id).toBe('grand')
  })

  it('ties a run whose beats name no call to the Task call that asked for it', () => {
    const task = { toolCallId: 'c7', name: 'Task', arguments: { agent: 'reviewer', description: 'review it', prompt: 'p' } }
    expect(startedBy(run({ parentCallId: 'c7' }), task)).toBe(true)
    expect(startedBy(run({ parentCallId: 'c8' }), task)).toBe(false)
    expect(startedBy(run({ parentCallId: '', description: 'review it' }), task)).toBe(true)
    expect(startedBy(run({ parentCallId: '', description: 'something else' }), task)).toBe(false)
    expect(startedBy(run({ parentCallId: '', description: 'review it' }), { ...task, name: 'Bash' })).toBe(false)
  })

  it('has a glyph for every status', () => {
    expect(['queued', 'running', 'done', 'failed', 'cancelled', 'empty'].map((s) => statusIcon(s as AgentRun['status']))).toEqual(['◌', '●', '✔', '✖', '■', '○'])
  })
})
