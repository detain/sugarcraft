import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBackgroundStore } from '../../../stores/agents/background'
import { useShellsStore } from '../../../stores/agents/shells'
import { Wire } from '../../../stores/agents/__tests__/harness'
import ProcessesSection from '../ProcessesSection.vue'
import BackgroundPanel from '../BackgroundPanel.vue'

/**
 * C-TOOL-3.e: the Processes section — the launch's background shell table in
 * the web, the twin of `/shells`. Every call names the session on screen (the
 * server owner-filters it), a running row carries a write-gated Stop, and the
 * section polls `shells.list` ONLY while mounted: an open table costs one read
 * per tick, a closed one costs nothing. There are no shell events by design.
 */
const SHELL_ROWS = {
  items: [
    { shellId: 'sh-aaaa1111', label: 'build', state: 'running', exitCode: null, exitNote: null, expired: false, ageSeconds: 5 },
    { shellId: 'sh-bbbb2222', label: 'test', state: 'finished', exitCode: 0, exitNote: null, expired: false, ageSeconds: 65 },
  ],
}

const OUTPUT = {
  shellId: 'sh-aaaa1111',
  stdout: 'hello out',
  stderr: 'oops',
  cursor: '9.5',
  skippedBytes: 0,
  bytesAvailable: 14,
  state: 'running',
  exitCode: null,
  exitNote: null,
  expired: false,
}

let pinia: ReturnType<typeof createPinia>
let wire: Wire

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
  // Scope the fake to the interval the component owns, so the harness's
  // setTimeout-based flush keeps working while polling is deterministic.
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
})

afterEach(() => {
  vi.useRealTimers()
})

async function openSection(scopes: string[] = ['read', 'write', 'approve', 'admin'], sessionId: string | null = 's1') {
  await wire.connect(() => useShellsStore(), { principal: { kind: 'owner', scopes } })
  const wrapper = mount(ProcessesSection, { props: { sessionId }, global: { plugins: [pinia] } })
  await flushPromises()

  return wrapper
}

describe('ProcessesSection', () => {
  it('reads shells.list for the session and renders a row per shell with state, settle and age', async () => {
    const wrapper = await openSection()
    const sent = wire.sent('shells.list')
    expect(sent).toHaveLength(1)
    expect(sent[0]?.params).toMatchObject({ sessionId: 's1' })

    await wire.answer('shells.list', SHELL_ROWS)
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="proc-item"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('build')
    expect(rows[0]?.text()).toContain('running')
    expect(rows[0]?.text()).toContain('5s')
    expect(rows[1]?.text()).toContain('test')
    expect(rows[1]?.text()).toContain('exit 0')
    expect(rows[1]?.text()).toContain('1m 5s')
  })

  it('reads recent output only when a row is expanded', async () => {
    const wrapper = await openSection()
    await wire.answer('shells.list', SHELL_ROWS)
    await flushPromises()

    expect(wire.sent('shells.output')).toHaveLength(0)
    await wrapper.find('[data-testid="proc-head-sh-aaaa1111"]').trigger('click')
    await flushPromises()

    const out = wire.sent('shells.output')
    expect(out).toHaveLength(1)
    expect(out[0]?.params).toMatchObject({ sessionId: 's1', shellId: 'sh-aaaa1111' })

    await wire.answer('shells.output', OUTPUT)
    await flushPromises()
    expect(wrapper.find('[data-testid="proc-output"]').text()).toContain('hello out')
    expect(wrapper.find('[data-testid="proc-output"]').text()).toContain('oops')
  })

  it('a running row has a write-gated Stop that calls shells.stop and re-reads the table', async () => {
    const wrapper = await openSection()
    await wire.answer('shells.list', SHELL_ROWS)
    await flushPromises()
    await wrapper.find('[data-testid="proc-head-sh-aaaa1111"]').trigger('click')
    await wire.answer('shells.output', OUTPUT)
    await flushPromises()

    expect(wrapper.find('[data-testid="proc-stop-sh-aaaa1111"]').exists()).toBe(true)
    // The finished row never gets a Stop — it has nothing to kill.
    expect(wrapper.find('[data-testid="proc-stop-sh-bbbb2222"]').exists()).toBe(false)

    await wrapper.find('[data-testid="proc-stop-sh-aaaa1111"]').trigger('click')
    await flushPromises()

    const stop = wire.sent('shells.stop')
    expect(stop).toHaveLength(1)
    expect(stop[0]?.params).toMatchObject({ sessionId: 's1', shellId: 'sh-aaaa1111' })
    // The reload rides AFTER the stop answers: settle the ladder frame first.
    await wire.answer('shells.stop', { shellId: 'sh-aaaa1111', found: true, wasRunning: true, confirmed: true, grouped: true, exitCode: null, expired: false })
    await flushPromises()
    // The reload the stop triggers: a second list read for the same session.
    expect(wire.sent('shells.list')).toHaveLength(2)
  })

  it('a REJECTING shells.stop surfaces as the error line, never an uncaught rejection', async () => {
    // Sibling of BackgroundPanel's act(): the Stop click must not float a
    // rejected promise. The collector is the non-vacuity pin — reverting the
    // act() wrap lands the rejection on 'unhandledRejection' and reddens here.
    const rejections: unknown[] = []
    const onRejection = (reason: unknown): void => void rejections.push(reason)
    process.on('unhandledRejection', onRejection)
    try {
      const wrapper = await openSection()
      await wire.answer('shells.list', SHELL_ROWS)
      await flushPromises()
      await wrapper.find('[data-testid="proc-head-sh-aaaa1111"]').trigger('click')
      await wire.answer('shells.output', OUTPUT)
      await flushPromises()

      await wrapper.find('[data-testid="proc-stop-sh-aaaa1111"]').trigger('click')
      await flushPromises()
      expect(wire.sent('shells.stop')).toHaveLength(1)
      await wire.fail('shells.stop', -32003, 'permission_denied', 'stop refused here')
      await flushPromises()
      await flushPromises()

      expect(wrapper.find('[data-testid="proc-error"]').text()).toContain('stop refused here')
      expect(rejections).toEqual([])
      // The failed stop does not fake a reload: still the single on-mount read.
      expect(wire.sent('shells.list')).toHaveLength(1)
    } finally {
      process.off('unhandledRejection', onRejection)
    }
  })

  it('a read-only caller sees no Stop button', async () => {
    const wrapper = await openSection(['read'])
    await wire.answer('shells.list', SHELL_ROWS)
    await flushPromises()
    await wrapper.find('[data-testid="proc-head-sh-aaaa1111"]').trigger('click')
    await wire.answer('shells.output', OUTPUT)
    await flushPromises()

    expect(wrapper.find('[data-testid="proc-stop-sh-aaaa1111"]').exists()).toBe(false)
  })

  it('polls shells.list only while mounted, and the interval is dead after unmount', async () => {
    const wrapper = await openSection()
    // on-mount read, none answered (counting sent frames is the assertion).
    expect(wire.sent('shells.list')).toHaveLength(1)

    vi.advanceTimersByTime(3000)
    expect(wire.sent('shells.list')).toHaveLength(2)
    vi.advanceTimersByTime(3000)
    expect(wire.sent('shells.list')).toHaveLength(3)

    wrapper.unmount()
    vi.advanceTimersByTime(9000)
    expect(wire.sent('shells.list')).toHaveLength(3)
  })

  it('without a session it prompts and never reads the table', async () => {
    const wrapper = await openSection(['read', 'write'], null)
    expect(wrapper.text()).toContain('Open a session')
    expect(wrapper.findAll('[data-testid="proc-item"]')).toHaveLength(0)
    expect(wire.sent('shells.list')).toHaveLength(0)
  })

  it('is mounted inside BackgroundPanel and reads the shell table there', async () => {
    // The bg store must exist BEFORE the handshake: its first load rides onHello.
    await wire.connect(() => {
      useBackgroundStore()
      useShellsStore()
    }, { principal: { kind: 'owner', scopes: ['read', 'write', 'approve', 'admin'] } })
    await wire.answer('bg.list', { items: [] })
    const wrapper = mount(BackgroundPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('command.exec', { rows: [], effects: [] })
    await wire.answer('shells.list', SHELL_ROWS)
    await flushPromises()

    const section = wrapper.find('[data-testid="processes-section"]')
    expect(section.exists()).toBe(true)
    expect(section.findAll('[data-testid="proc-item"]')).toHaveLength(2)
  })
})

// C-TOOL-6.l: interactive (pty) rows expand into a POLLING typing pane —
// a rendered tail threaded on the shells.output cursor, plus one shells.input
// call per line/keystroke. The fixture is deliberately separate from
// SHELL_ROWS so every pre-6.l pin above keeps asserting the shape it was
// written against.
const INT_ROWS = {
  items: [
    { shellId: 'sh-int1', label: 'build', state: 'running', exitCode: null, exitNote: null, expired: false, ageSeconds: 5, interactive: true },
    { shellId: 'sh-p1', label: 'plain', state: 'running', exitCode: null, exitNote: null, expired: false, ageSeconds: 4, interactive: false },
  ],
}

const INT_OUT = (stdout: string, cursor: string, stderr = '') => ({
  shellId: 'sh-int1',
  stdout,
  stderr,
  cursor,
  skippedBytes: 0,
  bytesAvailable: stdout.length + stderr.length,
  state: 'running',
  exitCode: null,
  exitNote: null,
  expired: false,
})

describe('ProcessesSection — the typing pane (C-TOOL-6.l)', () => {
  it('interactive rows expand into the rendered tail + input line; plain rows keep the read-once dump', async () => {
    const wrapper = await openSection()
    await wire.answer('shells.list', INT_ROWS)
    await flushPromises()

    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    const first = wire.sent('shells.output')
    expect(first).toHaveLength(1)
    expect(first[0]?.params).toMatchObject({ sessionId: 's1', shellId: 'sh-int1', cursor: '0.0' })
    // CRLF off the pty (ONLCR) stays a line break — the CR never eats the word.
    await wire.answer('shells.output', INT_OUT('ready\r\n', '7.0'))
    await flushPromises()

    expect(wrapper.find('[data-testid="proc-tail"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="proc-tail"]').text()).toContain('ready')
    expect(wrapper.find('[data-testid="proc-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="proc-key-C-c"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="proc-output"]').exists()).toBe(false)

    // Expanding the plain row collapses the pane and reads the plain window —
    // cursorless, because loadOutput answers the tail itself.
    await wrapper.find('[data-testid="proc-head-sh-p1"]').trigger('click')
    await flushPromises()
    const second = wire.sent('shells.output')[1]
    expect(second?.params).toMatchObject({ sessionId: 's1', shellId: 'sh-p1' })
    expect(Object.keys(second?.params as Record<string, unknown>)).not.toContain('cursor')
    await wire.answer('shells.output', { ...OUTPUT, shellId: 'sh-p1' })
    await flushPromises()
    expect(wrapper.find('[data-testid="proc-output"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="proc-tail"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="proc-input"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('the tail polls on its own 750 ms clock, threads the cursor, and dies on collapse and on unmount', async () => {
    const wrapper = await openSection()
    await wire.answer('shells.list', INT_ROWS)
    await flushPromises()
    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    expect(wire.sent('shells.output')).toHaveLength(1)
    await wire.answer('shells.output', INT_OUT('a', '1.0'))
    await flushPromises()

    vi.advanceTimersByTime(750)
    expect(wire.sent('shells.output')).toHaveLength(2)
    expect(wire.sent('shells.output')[1]?.params).toMatchObject({ cursor: '1.0' })
    await wire.answer('shells.output', INT_OUT('b', '2.0'))
    await flushPromises()

    // Collapse: the tail clock stops and the buffer is forgotten.
    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    vi.advanceTimersByTime(2_249) // stays under the list tick to keep counting clean
    expect(wire.sent('shells.output')).toHaveLength(2)

    // Re-expand starts the pane from the head again.
    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    expect(wire.sent('shells.output')[2]?.params).toMatchObject({ cursor: '0.0' })
    await wire.answer('shells.output', INT_OUT('c', '1.0'))
    await flushPromises()

    wrapper.unmount()
    vi.advanceTimersByTime(6_000)
    expect(wire.sent('shells.output')).toHaveLength(3) // the panel closing stops everything
  })

  it('Enter sends the draft WITH its Enter in one call, then a bare Enter sends just the key', async () => {
    const wrapper = await openSection()
    await wire.answer('shells.list', INT_ROWS)
    await flushPromises()
    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    await wire.answer('shells.output', INT_OUT('', '0.0'))
    await flushPromises()

    const input = wrapper.find('[data-testid="proc-input"]')
    await input.setValue('ls -l')
    await input.trigger('keydown.enter')
    expect(wire.sent('shells.input')).toHaveLength(1)
    const { idempotencyKey, ...draftParams } = wire.sent('shells.input')[0]?.params as Record<string, unknown>
    expect(draftParams).toEqual({ sessionId: 's1', shellId: 'sh-int1', text: 'ls -l', key: 'Enter' })
    expect(idempotencyKey).toMatch(/^[0-9a-f]{32}$/) // client-stamped retry-safe write (IDEMPOTENT_METHODS)
    await wire.answer('shells.input', { accepted: true, cursor: 5 })
    await flushPromises()
    expect((input.element as HTMLInputElement).value).toBe('') // cleared only after the send landed

    await input.trigger('keydown.enter')
    const { idempotencyKey: bareKey, ...bareParams } = wire.sent('shells.input')[1]?.params as Record<string, unknown>
    expect(bareParams).toEqual({ sessionId: 's1', shellId: 'sh-int1', key: 'Enter' })
    expect(bareKey).toMatch(/^[0-9a-f]{32}$/)
    await wire.answer('shells.input', { accepted: true, cursor: 6 })
    await flushPromises()
    wrapper.unmount()
  })

  it('the named-key buttons send their key alone', async () => {
    const wrapper = await openSection()
    await wire.answer('shells.list', INT_ROWS)
    await flushPromises()
    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    await wire.answer('shells.output', INT_OUT('', '0.0'))
    await flushPromises()

    await wrapper.find('[data-testid="proc-key-C-c"]').trigger('click')
    const { idempotencyKey: ccStamp, ...ccParams } = wire.sent('shells.input')[0]?.params as Record<string, unknown>
    expect(ccParams).toEqual({ sessionId: 's1', shellId: 'sh-int1', key: 'C-c' })
    expect(ccStamp).toMatch(/^[0-9a-f]{32}$/) // client-stamped retry-safe write (IDEMPOTENT_METHODS)
    await wire.answer('shells.input', { accepted: true, cursor: 1 })
    await flushPromises()

    await wrapper.find('[data-testid="proc-key-Up"]').trigger('click')
    const { idempotencyKey: upStamp, ...upParams } = wire.sent('shells.input')[1]?.params as Record<string, unknown>
    expect(upParams).toEqual({ sessionId: 's1', shellId: 'sh-int1', key: 'Up' })
    expect(upStamp).toMatch(/^[0-9a-f]{32}$/)
    await wire.answer('shells.input', { accepted: true, cursor: 1 })
    await flushPromises()
    wrapper.unmount()
  })

  it('a read-only caller can watch the tail but cannot type — input and keys disabled, like Stop', async () => {
    const wrapper = await openSection(['read'])
    await wire.answer('shells.list', INT_ROWS)
    await flushPromises()
    await wrapper.find('[data-testid="proc-head-sh-int1"]').trigger('click')
    await flushPromises()
    expect(wire.sent('shells.output')).toHaveLength(1) // watching needs no write scope
    await wire.answer('shells.output', INT_OUT('view-only', '9.0'))
    await flushPromises()

    expect((wrapper.find('[data-testid="proc-input"]').element as HTMLInputElement).disabled).toBe(true)
    expect((wrapper.find('[data-testid="proc-key-C-c"]').element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.find('[data-testid="proc-stop-sh-int1"]').exists()).toBe(false)
    // The typing controls exist but carry the disabled attribute — jsdom
    // would still run click handlers if we triggered, so the honest pin is
    // the attribute the browser itself gates on, like 3.e pinned for Stop.
    expect(wire.sent('shells.input')).toHaveLength(0)
    wrapper.unmount()
  })
})
