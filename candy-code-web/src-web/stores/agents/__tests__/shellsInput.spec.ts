import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { TAIL_CAP_BYTES, useShellsStore } from '../shells'
import { Wire } from './harness'

/**
 * C-TOOL-6.l: the typing door and the tail pane. `shells.input` composes
 * text and one named key into a single human send, `pollTail` appends the
 * answer of a cursor-threaded `shells.output` read, and each stream of a tail
 * keeps only its newest 64 KiB. These are the store halves the Processes
 * section drives; the component's own pins live in processesSection.spec.
 *
 * Promise discipline: every request must be CAPTURED before its answer (the
 * store awaits the wire frame), so the pins below take the pending promise
 * first, feed the socket, then await it.
 */
let wire: Wire

function openStore() {
  setActivePinia(createPinia())
  wire = new Wire()

  return wire.connect(() => useShellsStore()).then(() => useShellsStore())
}

const OUT = (stdout: string, cursor: string, stderr = '') => ({
  shellId: 'sh-input',
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

describe('shells store: input + tail (C-TOOL-6.l)', () => {
  it('sends text and key in ONE request and answers the wire shape', async () => {
    const shells = await openStore()
    const pending = shells.input('sh-input', 's1', 'ls -l', 'Enter')
    const sent = wire.sent('shells.input')
    expect(sent).toHaveLength(1)
    expect(sent[0]?.params).toEqual({ sessionId: 's1', shellId: 'sh-input', text: 'ls -l', key: 'Enter' })
    await wire.answer('shells.input', { accepted: true, cursor: 42 })
    expect(await pending).toEqual({ accepted: true, cursor: 42 })
  })

  it('a key-only send carries NO text param (absent, never empty)', async () => {
    const shells = await openStore()
    const pending = shells.input('sh-input', 's1', undefined, 'C-c')
    expect(wire.sent('shells.input')[0]?.params).toEqual({ sessionId: 's1', shellId: 'sh-input', key: 'C-c' })
    await wire.answer('shells.input', { accepted: true, cursor: 7 })
    expect(await pending).toEqual({ accepted: true, cursor: 7 })
  })

  it('a rejected send floats the wire error to the caller', async () => {
    const shells = await openStore()
    const pending = shells.input('sh-input', 's1', undefined, 'Tab')
    const failure = wire.fail('shells.input', -32009, 'shell_settled', 'has already finished')
    await expect(pending).rejects.toThrow(/already finished/)
    await failure
  })

  it('input threads the held tail cursor from the answer, keeping its stderr half', async () => {
    const shells = await openStore()
    const stepping = shells.pollTail('sh-input', 's1')
    await wire.answer('shells.output', OUT('out', '4.2'))
    await stepping
    expect(shells.tail('sh-input').cursor).toBe('4.2')

    const pending = shells.input('sh-input', 's1', 'y', 'Enter')
    await wire.answer('shells.input', { accepted: true, cursor: 10 })
    await pending
    // 10 is the stdout offset just before the keystroke; stderr resumes at 2.
    expect(shells.tail('sh-input').cursor).toBe('10.2')

    const nextStep = shells.pollTail('sh-input', 's1')
    expect(wire.sent('shells.output')[1]?.params).toMatchObject({ cursor: '10.2' })
    await wire.answer('shells.output', OUT('', '10.2'))
    await nextStep
  })

  it('pollTail appends from the held cursor and advances it per stream', async () => {
    const shells = await openStore()

    const first = shells.pollTail('sh-input', 's1')
    expect(wire.sent('shells.output')[0]?.params).toMatchObject({ sessionId: 's1', shellId: 'sh-input', cursor: '0.0' })
    await wire.answer('shells.output', OUT('first ', '6.0'))
    await first

    const second = shells.pollTail('sh-input', 's1')
    expect(wire.sent('shells.output')[1]?.params).toMatchObject({ cursor: '6.0' })
    await wire.answer('shells.output', OUT('second', '12.3', 'oops'))
    await second

    const held = shells.tail('sh-input')
    expect(held.stdout).toBe('first second')
    expect(held.stderr).toBe('oops')
    expect(held.cursor).toBe('12.3')
    expect(held.error).toBeNull()
  })

  it('each stream caps at the newest 64 KiB of UTF-8 bytes', async () => {
    const shells = await openStore()
    const wide = 'é'.repeat(TAIL_CAP_BYTES / 2 + 16) // 2 bytes per char — over cap on its own.
    const stepping = shells.pollTail('sh-input', 's1')
    await wire.answer('shells.output', OUT(wide + 'tail', '0.1'))
    await stepping
    const held = shells.tail('sh-input')
    const bytes = new TextEncoder().encode(held.stdout).length
    expect(bytes).toBeLessThanOrEqual(TAIL_CAP_BYTES)
    expect(held.stdout.endsWith('tail')).toBe(true)
    // The cap never splits a multi-byte character into a replacement glyph.
    expect(held.stdout).not.toContain('\uFFFD')
  })

  it('a failed poll step keeps the held bytes, names the error, and retries from the SAME cursor', async () => {
    const shells = await openStore()

    const first = shells.pollTail('sh-input', 's1')
    await wire.answer('shells.output', OUT('kept', '4.0'))
    await first

    const failing = shells.pollTail('sh-input', 's1')
    await wire.fail('shells.output', -32004, 'shell_not_found', 'read broke')
    await failing
    expect(shells.tail('sh-input').stdout).toBe('kept')
    expect(shells.tail('sh-input').error).toContain('read broke')

    const retry = shells.pollTail('sh-input', 's1')
    expect(wire.sent('shells.output')[2]?.params).toMatchObject({ cursor: '4.0' })
    await wire.answer('shells.output', OUT(' and more', '13.0'))
    await retry
    expect(shells.tail('sh-input').stdout).toBe('kept and more')
    expect(shells.tail('sh-input').error).toBeNull()
  })

  it('resetTail forgets the pane so a re-expand starts from the head', async () => {
    const shells = await openStore()
    const stepping = shells.pollTail('sh-input', 's1')
    await wire.answer('shells.output', OUT('gone', '4.0'))
    await stepping
    shells.resetTail('sh-input')
    expect(shells.tail('sh-input').stdout).toBe('')
    expect(shells.tail('sh-input').cursor).toBe('0.0')

    const nextStep = shells.pollTail('sh-input', 's1')
    expect(wire.sent('shells.output')[1]?.params).toMatchObject({ cursor: '0.0' })
    await wire.answer('shells.output', OUT('', '0.0'))
    await nextStep
  })
})
