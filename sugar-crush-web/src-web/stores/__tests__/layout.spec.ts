import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { flush, helloResult } from '../../__tests__/fakes'
import { Wire } from '../../components/grid/__tests__/harness'
import { MAX_TABS, MAX_TILES, useLayoutStore } from '../layout'

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
})

describe('layout: tabs and tiles', () => {
  it('opens a tab once, focuses it, and fronts the neighbour when the focused one closes', async () => {
    await Wire.connect()
    const layout = useLayoutStore()
    layout.focus('a')
    layout.focus('b')
    layout.openTab('a')
    layout.focus('c')
    expect(layout.tabs).toEqual(['a', 'b', 'c'])
    expect(layout.focused).toBe('c')

    layout.focus('b')
    expect(layout.closeTab('b')).toBe('c')
    expect(layout.tabs).toEqual(['a', 'c'])
    expect(layout.closeTab('c')).toBe('a')
    expect(layout.closeTab('a')).toBeNull()
  })

  it('keeps at most MAX_TABS, dropping the oldest unfocused tab', async () => {
    await Wire.connect()
    const layout = useLayoutStore()
    layout.focus('first')
    for (let i = 0; i < MAX_TABS; i++) layout.openTab(`s${i}`)
    expect(layout.tabs).toHaveLength(MAX_TABS)
    expect(layout.tabs).toContain('first')
    expect(layout.tabs).not.toContain('s0')
  })

  it('tiles the first nine tabs in 1, 2 or 3 columns', async () => {
    await Wire.connect()
    const layout = useLayoutStore()
    layout.openTab('a')
    expect(layout.columns).toBe(1)
    layout.openTab('b')
    expect(layout.columns).toBe(2)
    for (const id of ['c', 'd']) layout.openTab(id)
    expect(layout.columns).toBe(2)
    for (const id of ['e', 'f', 'g', 'h', 'i', 'j']) layout.openTab(id)
    expect(layout.tiles).toHaveLength(MAX_TILES)
    expect(layout.columns).toBe(3)
  })

  it('moves a tab and forgets sessions that are gone', async () => {
    await Wire.connect()
    const layout = useLayoutStore()
    for (const id of ['a', 'b', 'c']) layout.openTab(id)
    layout.moveTab('c', 0)
    expect(layout.tabs).toEqual(['c', 'a', 'b'])
    layout.focus('a')
    layout.pruneTabs((id) => id !== 'a')
    expect(layout.tabs).toEqual(['c', 'b'])
    expect(layout.focused).toBe('c')
  })

  it('remembers the tabs, the mode and the focus for this viewer', async () => {
    await Wire.connect()
    const layout = useLayoutStore()
    layout.focus('a')
    layout.openTab('b')
    layout.setMode('grid')
    await flush()

    setActivePinia(createPinia())
    await Wire.connect()
    const again = useLayoutStore()
    expect(again.tabs).toEqual(['a', 'b'])
    expect(again.mode).toBe('grid')
    expect(again.focused).toBe('a')
  })

  it('survives garbage in storage', async () => {
    localStorage.setItem('sugar-crush-web:tabs', '{"not":"a list"}')
    localStorage.setItem('sugar-crush-web:mode', '"sideways"')
    await Wire.connect()
    const layout = useLayoutStore()
    expect(layout.tabs).toEqual([])
    expect(layout.mode).toBe('tabs')
  })
})

describe('layout: client.viewing', () => {
  it('tells the server once per change what is on screen, coalesced', async () => {
    const wire = await Wire.connect()
    const layout = useLayoutStore()
    layout.show({ sessionIds: ['a'], foreground: 'a', narrate: false })
    layout.show({ sessionIds: ['a', 'b'], foreground: 'b', narrate: true })
    await flush()
    expect(wire.sent('client.viewing').map((frame) => frame.params)).toEqual([{ sessionIds: ['a', 'b'], foreground: 'b', narrate: true }])

    layout.show({ sessionIds: ['a', 'b'], foreground: 'b', narrate: true })
    await flush()
    expect(wire.sent('client.viewing')).toHaveLength(1)
  })

  it('says it again on a new socket', async () => {
    const wire = await Wire.connect()
    const layout = useLayoutStore()
    layout.show({ sessionIds: ['a'], foreground: 'a', narrate: false })
    await flush()
    wire.socket.drop()
    wire.timers.advance(1000)
    await flush()
    wire.socket.open()
    wire.socket.answer(helloResult(), 'hello')
    await flush()
    expect(wire.sent('client.viewing')).toHaveLength(1)
    expect(wire.sockets).toHaveLength(2)
  })
})
