import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { FakeSocket, flush, helloResult } from '../../__tests__/fakes'
import type { SessionSummary } from '../../protocol/generated'
import { Backoff } from '../../protocol/reconnect'
import { createAppRouter } from '../../router'
import { useConnectionStore } from '../../stores/connection'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

/**
 * C-WEB-3.e: `session.share` / `session.unshare` get their first callers.
 * A row's menu carries "Share page…" for a write-scoped connection; it
 * publishes a snapshot and the dialog shows the full URL (origin + the
 * server's token path) with the UTC expiry and a clipboard copy. Live links
 * come from the server's cross-session `session.shares` listing — each gets
 * a revoke item naming only its prefix — so the token is never kept in web
 * storage: a fresh browser can still revoke a link it never made. A
 * read-only connection sees neither item.
 */

const OWNER_SCOPES = ['read', 'write', 'approve', 'admin']
const FULL_TOKEN = 'deadbeefcafe0123deadbeefcafe0123'
const PATH = `/s/${FULL_TOKEN}`
const URL = `${window.location.origin}${PATH}`
/** 2027-04-03 12:00:00 UTC, as unix seconds (the wire shape). */
const EXPIRES_AT = 1806796800
const EXPIRES_LABEL = new Date(EXPIRES_AT * 1000).toISOString().slice(0, 16).replace('T', ' ')

let router: ReturnType<typeof createAppRouter>

function row(id: string): SessionSummary {
  return {
    id,
    name: `session ${id}`,
    status: 'idle',
    open: true,
    preview: null,
    createdAt: '2026-10-17 00:00:00',
    updatedAt: '2026-10-17 00:00:00',
    permissionMode: 'default',
    root: '/repo',
  }
}

function shareItem(sessionId: string, tokenPrefix: string): Record<string, unknown> {
  return { sessionId, sessionName: `session ${sessionId}`, token: FULL_TOKEN, tokenPrefix, path: PATH, format: 'html', expiresAt: EXPIRES_AT }
}

/**
 * Same wiring as the grid harness's Wire.connect, but the handshake's
 * principal scopes are the test's — the read-only polarity must answer a
 * hello without 'write' for the item gating to mean anything.
 */
async function connectScoped(scopes: string[]): Promise<Wire> {
  const wire = new Wire()
  const connection = useConnectionStore()
  connection.configure(
    {
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const created = new FakeSocket(url, protocols)
        wire.sockets.push(created)
        return created
      },
      timers: wire.timers,
      backoff: new Backoff({ random: () => 0.5 }),
    },
    (fn) => fn(),
  )
  useSessionsStore()
  connection.start()
  await flush()
  wire.socket.open()
  wire.socket.answer(helloResult({ principal: { kind: 'guest', scopes } }), 'hello')
  await flush()
  return wire
}

async function mountSidebar(scopes: string[]): Promise<{ wire: Wire; wrapper: ReturnType<typeof mount> }> {
  const wire = await connectScoped(scopes)
  const sessions = useSessionsStore()
  sessions.byId = { s1: row('s1') }
  await flush()
  router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id: 's1' } })
  await router.isReady()
  const wrapper = mount(SessionSidebar, { props: { activeId: 's1' }, global: { plugins: [router] } })
  return { wire, wrapper }
}

/** Every value either storage holds, serialized for a substring scan. */
function webStorageDump(): string {
  const parts: string[] = []
  for (const storage of [localStorage, sessionStorage]) {
    for (let i = 0; i < storage.length; i++) {
      const key = storage.key(i)
      if (key !== null) parts.push(`${key}=${storage.getItem(key) ?? ''}`)
    }
  }
  return parts.join('\n')
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  sessionStorage.clear()
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('share and revoke from the sessions sidebar (C-WEB-3.e)', () => {
  it('Share page… publishes, and the dialog shows the full URL, the UTC expiry, and a working copy', async () => {
    const writeClipboard = vi.fn(() => Promise.resolve())
    vi.stubGlobal('navigator', { clipboard: { writeText: writeClipboard } })
    const { wire, wrapper } = await mountSidebar(OWNER_SCOPES)

    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-share-s1"]').text()).toBe('Share page…')
    await wrapper.find('[data-testid="row-share-s1"]').trigger('click')

    // The store sends the bare params; the protocol client attaches its
    // retry idempotencyKey for the idempotent-methods family.
    expect(wire.sent('session.share').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    expect((wire.sent('session.share').at(-1)?.params as Record<string, unknown>)).not.toHaveProperty('days')
    const shareFrame = wire.sent('session.share').at(-1)
    if (!shareFrame) throw new Error('no session.share sent')
    wire.socket.answer({ token: FULL_TOKEN, path: PATH, format: 'html', expiresAt: EXPIRES_AT }, shareFrame.id)
    await flushPromises()
    await flush()

    expect(wrapper.find('[data-testid="share-url"]').text()).toBe(URL)
    expect(wrapper.find('[data-testid="share-expiry"]').text()).toBe(`Expires ${EXPIRES_LABEL} UTC`)
    expect(wrapper.html()).toContain(FULL_TOKEN)

    await wrapper.find('[data-testid="share-copy"]').trigger('click')
    await flushPromises()
    expect(writeClipboard).toHaveBeenCalledWith(URL)
    expect(wrapper.find('[data-testid="share-copy"]').text()).toBe('Copied')

    await wrapper.find('[data-testid="share-close"]').trigger('click')
    expect(wrapper.find('[data-testid="share-dialog"]').exists()).toBe(false)
  })

  it('revoke rides the server listing: the item names the prefix, the call carries the full token, the menu follows the re-read', async () => {
    const { wire, wrapper } = await mountSidebar(OWNER_SCOPES)
    // The hello's listing fetch answers with one live share for s1.
    wire.answer('session.shares', { items: [shareItem('s1', FULL_TOKEN.slice(0, 8))] })
    await flushPromises()
    await flush()

    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    const revoke = wrapper.find(`[data-testid="row-unshare-s1-${FULL_TOKEN.slice(0, 8)}"]`)
    expect(revoke.exists()).toBe(true)
    // The menu shows the prefix only; the full token never renders there.
    expect(revoke.text()).toContain(`Revoke share ${FULL_TOKEN.slice(0, 8)}…`)
    expect(revoke.text()).not.toContain(FULL_TOKEN)

    await revoke.trigger('click')
    expect(wire.sent('session.unshare').at(-1)?.params).toMatchObject({ token: FULL_TOKEN })
    const unshareFrame = wire.sent('session.unshare').at(-1)
    if (!unshareFrame) throw new Error('no session.unshare sent')
    wire.socket.answer({ revoked: true }, unshareFrame.id)
    await flushPromises()
    // The revoke re-reads the listing; answering it empty removes the item.
    wire.answer('session.shares', { items: [] })
    await flushPromises()
    await flush()
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find(`[data-testid="row-unshare-s1-${FULL_TOKEN.slice(0, 8)}"]`).exists()).toBe(false)
  })

  it('a connection without the write scope sees neither item, while the listing still shows nothing anyway', async () => {
    const { wire, wrapper } = await mountSidebar(['read'])
    wire.answer('session.shares', { items: [shareItem('s1', FULL_TOKEN.slice(0, 8))] })
    await flushPromises()
    await flush()

    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-share-s1"]').exists()).toBe(false)
    expect(wire.sent('session.share')).toHaveLength(0)
    expect(wire.sent('session.unshare')).toHaveLength(0)
    expect(wrapper.find('[data-testid^="row-unshare-"]').exists()).toBe(false)
  })

  it('no share token ever reaches web storage — the server listing is the only record', async () => {
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(() => Promise.resolve()) } })
    const { wire, wrapper } = await mountSidebar(OWNER_SCOPES)

    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-share-s1"]').trigger('click')
    const shareFrame = wire.sent('session.share').at(-1)
    if (!shareFrame) throw new Error('no session.share sent')
    wire.socket.answer({ token: FULL_TOKEN, path: PATH, format: 'html', expiresAt: EXPIRES_AT }, shareFrame.id)
    await flushPromises()
    await flush()
    wire.answer('session.shares', { items: [shareItem('s1', FULL_TOKEN.slice(0, 8))] })
    await flushPromises()
    await flush()

    expect(webStorageDump()).not.toContain(FULL_TOKEN)
    expect(webStorageDump()).not.toContain('/s/')
  })
})
