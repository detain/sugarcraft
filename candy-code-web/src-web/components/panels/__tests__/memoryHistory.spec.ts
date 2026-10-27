import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import MemoryPanel from '../MemoryPanel.vue'

/**
 * The memory history and undo surface (C-WEB-3.a): the History tab calls
 * `memory.log` on open — never on mount, because the log commits pending
 * notes first — rows render as plain text, and `memory.undo` rides a
 * confirmed button that only write scope shows.
 */
let pinia: ReturnType<typeof createPinia>
let wire: Wire

const REVISIONS = [
  { sha: 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0', shortSha: 'a1b2c3d', committedAt: '2026-10-26T12:00:00+00:00', subject: 'memory: add repo note' },
  { sha: '0f9e8d7c6b5a4938271605f4e3d2c1b0a9e8d7c6', shortSha: '0f9e8d7', committedAt: '2026-10-25T09:30:00+00:00', subject: 'memory: commit pending notes' },
]

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

async function openPanel(scopes: string[] = ['read', 'write', 'approve', 'admin']) {
  await wire.connect(() => {}, { principal: { kind: 'owner', scopes } })
  const wrapper = mount(MemoryPanel, { global: { plugins: [pinia] } })
  await flushPromises()
  await wire.answer('memory.list', { items: [] })
  return wrapper
}

describe('MemoryPanel history tab', () => {
  it('loads memory.log on tab open (not on mount) and lists the rows', async () => {
    const wrapper = await openPanel()
    expect(wire.sent('memory.log')).toHaveLength(0)

    await wrapper.find('[data-testid="memory-tab-history"]').trigger('click')
    expect(wire.sent('memory.log')).toHaveLength(1)
    await wire.answer('memory.log', { items: REVISIONS })

    const rows = wrapper.findAll('[data-testid="memory-revision"]')
    expect(rows.map((row) => row.attributes('data-sha'))).toEqual([REVISIONS[0]?.sha, REVISIONS[1]?.sha])
    expect(rows[0]?.find('[data-testid="memory-rev-sha"]').text()).toBe('a1b2c3d')
    expect(rows[0]?.text()).toContain('2026-10-26T12:00:00+00:00')
    expect(rows[0]?.find('[data-testid="memory-rev-subject"]').text()).toBe('memory: add repo note')
  })

  it('undo sends memory.undo with the full revision, reloads the scope list, and re-logs', async () => {
    const confirmSpy = vi.fn(() => true)
    vi.stubGlobal('confirm', confirmSpy)
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="memory-tab-history"]').trigger('click')
    await wire.answer('memory.log', { items: REVISIONS })

    await wrapper.find('[data-testid="memory-undo"]').trigger('click')
    await flushPromises()
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    const { idempotencyKey, ...undoParams } = wire.sent('memory.undo').pop()!.params as Record<string, unknown>
    expect(undoParams).toEqual({ revision: REVISIONS[0]?.sha })
    expect(idempotencyKey).toMatch(/^[0-9a-f]{32}$/) // a retry-safe write, like every IDEMPOTENT_METHODS member
    await wire.answer('memory.undo', { restored: REVISIONS[0]?.sha, commit: 'feedc0de' })

    // The notes on disk changed with the restore: the current scope reloads.
    expect(wire.sent('memory.list')).toHaveLength(2)
    await wire.answer('memory.list', { items: [] })
    // The restore is itself recorded as a commit: the history refreshes.
    expect(wire.sent('memory.log')).toHaveLength(2)
    await wire.answer('memory.log', { items: REVISIONS })
    expect(wire.sent('memory.list')).toHaveLength(2)
  })

  it('hides the undo button without write scope', async () => {
    const wrapper = await openPanel(['read'])
    await wrapper.find('[data-testid="memory-tab-history"]').trigger('click')
    await wire.answer('memory.log', { items: REVISIONS })

    expect(wrapper.findAll('[data-testid="memory-revision"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-testid="memory-undo"]')).toHaveLength(0)
  })
})
