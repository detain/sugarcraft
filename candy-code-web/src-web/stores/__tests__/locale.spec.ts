import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { resetWebStrings, servedLocale, setWebStrings, t } from '../../lib/i18n'
import { useConnectionStore } from '../connection'
import { useLocaleStore } from '../locale'

/**
 * C-TUI-6.f: the hello-gated chrome-string fetch. The store must dial
 * `server.strings` only when the advertised roster offers it, pass the
 * browser's tag through, and keep English on any failure — the three
 * design-pinned behaviours, plus the locale passthrough arm.
 */
let sockets: FakeSocket[]
let timers: FakeTimers

function socket(): FakeSocket {
  const last = sockets[sockets.length - 1]
  if (!last) throw new Error('no socket')
  return last
}

function sent(method: string): Record<string, unknown>[] {
  return socket().sent.filter((frame) => frame.method === method)
}

async function connect(): Promise<ReturnType<typeof useConnectionStore>> {
  const connection = useConnectionStore()
  connection.configure(
    {
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const created = new FakeSocket(url, protocols)
        sockets.push(created)
        return created
      },
      timers,
      backoff: new Backoff({ random: () => 0.5 }),
    },
    (fn) => fn(),
  )
  connection.start()
  await flush()
  socket().open()
  return connection
}

async function handshake(methods: string[]): Promise<void> {
  socket().answer(helloResult({ features: { methods, events: [] } }), 'hello')
  await flush()
}

beforeEach(() => {
  setActivePinia(createPinia())
  resetWebStrings()
  sockets = []
  timers = new FakeTimers()
})

afterEach(() => {
  resetWebStrings()
  vi.unstubAllGlobals()
})

describe('locale store (C-TUI-6.f)', () => {
  it('loads the served bundle when the hello roster offers server.strings', async () => {
    vi.stubGlobal('navigator', { language: 'de-DE' })
    const connection = await connect()
    useLocaleStore()
    await handshake(['server.health', 'server.strings'])
    const frames = sent('server.strings')
    expect(frames.length).toBe(1)
    expect(frames[0].params).toEqual({ locale: 'de-DE' })
    socket().answer({ locale: 'de', strings: { 'web.chrome.app.settings': 'Einstellungen' } }, frames[0].id)
    await flush()
    expect(servedLocale.value).toBe('de')
    expect(t('web.chrome.app.settings')).toBe('Einstellungen')
    expect(connection.connected).toBe(true)
  })

  it('stays silent and English when the roster lacks server.strings', async () => {
    const connection = await connect()
    useLocaleStore()
    await handshake(['server.health', 'server.info'])
    expect(sent('server.strings').length).toBe(0)
    expect(servedLocale.value).toBe('en')
    expect(t('web.chrome.app.settings')).toBe('settings')
    expect(connection.connected).toBe(true)
  })

  it('falls back to English when the fetch fails after a prior load', async () => {
    const connection = await connect()
    useLocaleStore()
    await handshake(['server.strings'])
    const first = sent('server.strings').pop()
    if (!first) throw new Error('no server.strings frame')
    socket().answer({ locale: 'de', strings: { 'web.chrome.app.settings': 'Einstellungen' } }, first.id)
    await flush()
    expect(t('web.chrome.app.settings')).toBe('Einstellungen')
    // A reconnect handshakes again; this time the dial is refused — the
    // overlay from the previous server must NOT be kept as if it were the
    // fresh answer, and the chrome must not blank out either.
    await handshake(['server.strings'])
    const second = sent('server.strings').pop()
    if (!second) throw new Error('no second server.strings frame')
    socket().fail(4000, 'internal', 'boom', second.id)
    await flush()
    expect(servedLocale.value).toBe('en')
    expect(t('web.chrome.app.settings')).toBe('settings')
    expect(connection.connected).toBe(true)
  })

  it('asks in English when the browser names no language', async () => {
    vi.stubGlobal('navigator', {})
    await connect()
    useLocaleStore()
    await handshake(['server.strings'])
    const frame = sent('server.strings').pop()
    expect(frame?.params).toEqual({ locale: 'en' })
  })

  it('leaves a pre-seeded overlay alone when no server.strings is offered', async () => {
    // The guard skips the DIAL, it never resets: an overlay from any earlier
    // handshake on this page's lifetime survives a downgrade hello.
    await connect()
    useLocaleStore()
    await handshake(['server.strings'])
    const frame = sent('server.strings').pop()
    if (!frame) throw new Error('no server.strings frame')
    socket().answer({ locale: 'fr', strings: { 'web.chrome.app.settings': 'Paramètres' } }, frame.id)
    await flush()
    await handshake(['server.health'])
    expect(t('web.chrome.app.settings')).toBe('Paramètres')
    expect(servedLocale.value).toBe('fr')
  })
})
