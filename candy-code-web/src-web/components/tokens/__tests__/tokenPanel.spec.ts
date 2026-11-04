import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { Wire } from '../../../stores/agents/__tests__/harness'
import TokenPanel from '../TokenPanel.vue'

/**
 * G5-G: the viewer-token panel over token.list / token.grant / token.revoke
 * (X-DESIGN1 §D-2, G4-G ## HANDOFFS). The laws this suite exists to keep:
 *  - the raw token crosses the wire ONCE, inside the grant answer, and lives
 *    in the DOM only while the mint dialog is open — closing it drops it, and
 *    no list response ever carries one;
 *  - grants go out read-only-scoped, full stop (owner ruling §7 item 2);
 *  - revoke is confirm-gated, and a refused confirm sends nothing;
 *  - the uniform 401 answer locks the whole management surface;
 *  - a viewer-kind principal sees a statement, not a control.
 */

let wire: Wire

const tick = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 0))
  await flushPromises()
}

const ROW = {
  id: 'a1b2c3d4',
  label: 'demo-viewer',
  scopes: ['read'],
  sessionIds: null,
  createdAt: 1_760_000_000.5,
  expiresAt: 1_762_592_000.5,
  lastUsedAt: 1_760_100_000.25,
}

const LIST = { tokens: [ROW] }

const MINT = {
  id: 'e5f6a7b8',
  token: 'cv1_' + '0'.repeat(40),
  label: 'fresh',
  scopes: ['read'],
  createdAt: 1_760_200_000.5,
  expiresAt: 1_762_792_000.5,
}

async function open(hello: Record<string, unknown> = {}): Promise<ReturnType<typeof mount>> {
  await wire.connect(() => {}, hello)
  const wrapper = mount(TokenPanel)
  await tick()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('TokenPanel list (G5-G)', () => {
  it('renders the grants token.list answers, with no raw token anywhere', async () => {
    const wrapper = await open()
    expect(wire.sent('token.list')).toHaveLength(1)
    await wire.answer('token.list', LIST)
    await tick()
    expect(wrapper.find('[data-testid="tokens-row-a1b2c3d4"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tokens-row-a1b2c3d4"]').text()).toContain('demo-viewer')
    expect(wrapper.text()).toContain('all sessions')
    // The list result has no token field and nothing renders a cv1_ string.
    expect(wrapper.text()).not.toContain('cv1_')
  })

  it('states the empty', async () => {
    const wrapper = await open()
    await wire.answer('token.list', { tokens: [] })
    await tick()
    expect(wrapper.find('[data-testid="tokens-empty"]').exists()).toBe(true)
  })
})

describe('TokenPanel grant (G5-G)', () => {
  it('sends the read-only scope fixed and shows the raw token once', async () => {
    const wrapper = await open()
    await wire.answer('token.list', LIST)
    await tick()
    await wrapper.find('[data-testid="tokens-label"]').setValue('fresh')
    await wrapper.find('[data-testid="tokens-grant-form"]').trigger('submit')
    await tick()
    const frames = wire.sent('token.grant')
    expect(frames).toHaveLength(1)
    expect(frames[0]?.params).toEqual({ label: 'fresh', scopes: ['read'], days: 30 })
    expect(wrapper.find('[data-testid="tokens-mint-dialog"]').exists()).toBe(false)
    await wire.answer('token.grant', MINT)
    await tick()
    expect(wrapper.find('[data-testid="tokens-mint-token"]').text()).toBe(MINT.token)
  })

  it('drops the raw token for good when the mint dialog closes, and reloads', async () => {
    const wrapper = await open()
    await wire.answer('token.list', LIST)
    await tick()
    await wrapper.find('[data-testid="tokens-label"]').setValue('fresh')
    await wrapper.find('[data-testid="tokens-grant-form"]').trigger('submit')
    await tick()
    await wire.answer('token.grant', MINT)
    await tick()
    await wrapper.find('[data-testid="tokens-mint-close"]').trigger('click')
    await tick()
    expect(wrapper.find('[data-testid="tokens-mint-token"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('cv1_')
    // The close re-lists; the second answer still cannot carry a token.
    await wire.answer('token.list', { tokens: [{ ...ROW, ...MINT, token: undefined }] })
    await tick()
    expect(wrapper.text()).not.toContain('cv1_')
    expect(wire.sent('token.list')).toHaveLength(2)
  })

  it('threads the session pin through when ticked', async () => {
    const wrapper = await open()
    await wire.answer('token.list', LIST)
    await tick()
    await wrapper.find('[data-testid="tokens-label"]').setValue('pinned')
    await wrapper.find('[data-testid="tokens-pin"]').setValue(true)
    await wrapper.find('[data-testid="tokens-pin-ids"]').setValue('s1  s2')
    await wrapper.find('[data-testid="tokens-days"]').setValue('90')
    await wrapper.find('[data-testid="tokens-grant-form"]').trigger('submit')
    await tick()
    expect(wire.sent('token.grant')[0]?.params).toEqual({
      label: 'pinned',
      scopes: ['read'],
      days: 90,
      sessionIds: ['s1', 's2'],
    })
  })
})

describe('TokenPanel revoke (G5-G)', () => {
  it('confirms, sends the id, and reports', async () => {
    // The typed parameter gives mock.calls[0]?.[0] its string type (aboutModal idiom).
    const confirmSpy = vi.fn((_question: string) => true)
    vi.stubGlobal('confirm', confirmSpy)
    const wrapper = await open()
    await wire.answer('token.list', LIST)
    await tick()
    await wrapper.find('[data-testid="tokens-revoke-a1b2c3d4"]').trigger('click')
    await tick()
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    expect(String(confirmSpy.mock.calls[0]?.[0])).toContain('demo-viewer')
    expect(wire.sent('token.revoke')[0]?.params).toEqual({ id: 'a1b2c3d4' })
    await wire.answer('token.revoke', { revoked: true })
    await tick()
    expect(wrapper.find('[data-testid="tokens-row-a1b2c3d4"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tokens-notice"]').text()).toBe('Grant revoked.')
  })

  it('a refused confirm sends nothing', async () => {
    vi.stubGlobal('confirm', vi.fn(() => false))
    const wrapper = await open()
    await wire.answer('token.list', LIST)
    await tick()
    await wrapper.find('[data-testid="tokens-revoke-a1b2c3d4"]').trigger('click')
    await tick()
    expect(wire.sent('token.revoke')).toHaveLength(0)
    expect(wrapper.find('[data-testid="tokens-row-a1b2c3d4"]').exists()).toBe(true)
  })
})

describe('TokenPanel gating (G5-G)', () => {
  it('the uniform 401 locks the whole management surface', async () => {
    const wrapper = await open()
    await wire.fail('token.list', -32001, 'unauthorized')
    await tick()
    expect(wrapper.find('[data-testid="tokens-grant-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tokens-list"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tokens-reload"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tokens-error"]').text()).toContain('Not authorized')
    // Not the viewer statement — this is a refused owner, not a viewer.
    expect(wrapper.find('[data-testid="tokens-viewer-banner"]').exists()).toBe(false)
  })

  it('a viewer-kind principal sees a statement, never a control', async () => {
    const wrapper = await open({ principal: { kind: 'viewer', via: 'viewer', scopes: ['read'] } })
    expect(wire.sent('token.list')).toHaveLength(0)
    expect(wrapper.find('[data-testid="tokens-viewer-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tokens-grant-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tokens-list"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tokens-reload"]').exists()).toBe(false)
  })

  it('a non-admin owner never calls the family at all', async () => {
    const wrapper = await open({ principal: { kind: 'owner', via: 'bearer', scopes: ['read', 'write'] } })
    expect(wire.sent('token.list')).toHaveLength(0)
    expect(wrapper.find('[data-testid="tokens-grant-form"]').exists()).toBe(false)
  })
})
