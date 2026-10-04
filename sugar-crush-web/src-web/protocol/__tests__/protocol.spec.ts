import { describe, expect, it } from 'vitest'
import { codeFromHash, wsUrl, apiUrl } from '../auth'
import { GAP_TIMEOUT_MS, MAX_HELD, SeqCursor } from '../cursor'
import { ProtocolError, utf8Length } from '../jsonrpc'
import { Backoff, Watchdog } from '../reconnect'
import { FakeTimers } from '../../__tests__/fakes'

describe('SeqCursor', () => {
  it('applies in order and advances', () => {
    const cursor = new SeqCursor<string>(0)
    expect(cursor.offer(1, 'a', 0)).toEqual(['a'])
    expect(cursor.offer(2, 'b', 0)).toEqual(['b'])
    expect(cursor.value).toBe(2)
  })

  it('drops duplicates at or below the cursor (replay overlap)', () => {
    const cursor = new SeqCursor<string>(5)
    expect(cursor.offer(5, 'x', 0)).toEqual([])
    expect(cursor.offer(3, 'x', 0)).toEqual([])
    expect(cursor.value).toBe(5)
  })

  it('holds an event past a hole and releases the run once it fills', () => {
    const cursor = new SeqCursor<string>(0)
    expect(cursor.offer(1, 'a', 0)).toEqual(['a'])
    expect(cursor.offer(3, 'c', 0)).toEqual([])
    expect(cursor.offer(4, 'd', 0)).toEqual([])
    expect(cursor.value).toBe(1)
    expect(cursor.heldCount).toBe(2)
    expect(cursor.offer(2, 'b', 10)).toEqual(['b', 'c', 'd'])
    expect(cursor.value).toBe(4)
    expect(cursor.heldCount).toBe(0)
    expect(cursor.needsResync(100_000)).toBe(false)
  })

  it('asks for a resync when a hole stays open past the timeout', () => {
    const cursor = new SeqCursor<string>(0)
    cursor.offer(2, 'b', 1000)
    expect(cursor.needsResync(1000 + GAP_TIMEOUT_MS)).toBe(false)
    expect(cursor.needsResync(1001 + GAP_TIMEOUT_MS)).toBe(true)
  })

  it('asks for a resync when too much waits behind a hole', () => {
    const cursor = new SeqCursor<number>(0)
    for (let seq = 2; seq <= MAX_HELD + 2; seq++) cursor.offer(seq, seq, 0)
    expect(cursor.needsResync(0)).toBe(true)
  })

  it('reset drops what is held at or below the new start', () => {
    const cursor = new SeqCursor<string>(0)
    cursor.offer(3, 'c', 0)
    cursor.offer(9, 'i', 0)
    cursor.reset(5)
    expect(cursor.value).toBe(5)
    expect(cursor.heldCount).toBe(1)
  })
})

describe('Backoff', () => {
  it('climbs 0.5 s, 1, 2, 4, 8, then caps at 15 s', () => {
    const backoff = new Backoff({ random: () => 0.5 })
    expect([1, 2, 3, 4, 5, 6, 7].map(() => backoff.next())).toEqual([500, 1000, 2000, 4000, 8000, 15000, 15000])
  })

  it('jitters by ±30 %', () => {
    expect(new Backoff({ random: () => 0 }).next()).toBe(350)
    expect(new Backoff({ random: () => 1 }).next()).toBe(650)
  })

  it('resets after a connection that stayed up 60 s, not after a flap', () => {
    const backoff = new Backoff({ random: () => 0.5 })
    backoff.next()
    backoff.next()
    backoff.connected(0)
    backoff.disconnected(10_000)
    expect(backoff.next()).toBe(2000)
    backoff.connected(20_000)
    backoff.disconnected(80_000)
    expect(backoff.next()).toBe(500)
  })
})

describe('Watchdog', () => {
  it('fires after silence and is re-armed by every frame', () => {
    const timers = new FakeTimers()
    let fired = 0
    const watchdog = new Watchdog(timers, () => fired++)
    watchdog.arm(30_000)
    timers.advance(20_000)
    watchdog.arm(30_000)
    timers.advance(20_000)
    expect(fired).toBe(0)
    timers.advance(10_001)
    expect(fired).toBe(1)
  })
})

describe('auth helpers', () => {
  it('reads the one-time code from the sign-in fragment', () => {
    expect(codeFromHash('#code=3f9c0a1b2c3d4e5f')).toBe('3f9c0a1b2c3d4e5f')
    expect(codeFromHash('#/code=3f9c0a1b2c3d4e5f')).toBe('3f9c0a1b2c3d4e5f')
    expect(codeFromHash('#/s/abc')).toBeNull()
    expect(codeFromHash('#code=<script>')).toBeNull()
    expect(codeFromHash('')).toBeNull()
  })

  it('builds the socket URL from the page base, ws or wss, with the ticket', () => {
    expect(wsUrl('t 1', 'http://127.0.0.1:7420/#/s/x')).toBe('ws://127.0.0.1:7420/ws?ticket=t%201')
    expect(wsUrl('abc', 'https://agent.example.com/crush/')).toBe('wss://agent.example.com/crush/ws?ticket=abc')
    expect(apiUrl('ticket', 'https://agent.example.com/crush/')).toBe('https://agent.example.com/crush/api/ticket')
  })
})

describe('jsonrpc helpers', () => {
  it('counts UTF-8 bytes, as the server offsets deltas', () => {
    expect(utf8Length('abc')).toBe(3)
    expect(utf8Length('é')).toBe(2)
    expect(utf8Length('→')).toBe(3)
    expect(utf8Length('😀')).toBe(4)
  })

  it('keeps an error kind and its retryable flag', () => {
    const error = ProtocolError.fromResponse({ code: -32010, message: 'busy', data: { kind: 'too_many_turns', retryable: true } })
    expect(error.kind).toBe('too_many_turns')
    expect(error.retryable).toBe(true)
    expect(ProtocolError.disconnected().kind).toBe('disconnected')
  })
})
