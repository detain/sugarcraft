import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
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

/**
 * Realized-server shapes (G4-G + addendum, TokenMethods): stamps are
 * ISO-8601 `date(ATOM)` strings and an unpinned list row OMITS the
 * sessionIds key entirely — fabricating the old epoch-number / explicit-
 * null drift here would make this suite a false oracle.
 */
const ROW = {
  id: 'a1b2c3d4',
  label: 'demo-viewer',
  scopes: ['read'],
  // No sessionIds key: the unpinned wire shape omits it, never nulls it.
  createdAt: '2026-10-09T09:46:40+00:00',
  expiresAt: '2026-11-08T12:00:00+00:00',
}

const LIST = { tokens: [ROW] }

const MINT = {
  id: 'e5f6a7b8',
  token: 'cv1_' + '0'.repeat(40),
  label: 'fresh',
  scopes: ['read'],
  createdAt: '2026-10-11T08:00:00+00:00',
  expiresAt: '2026-11-10T08:00:00+00:00',
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
    // The realized stamp is an ISO string; the row renders its UTC expiry.
    // Under the old epoch math this render throws RangeError — crash pin.
    expect(wrapper.find('[data-testid="tokens-row-a1b2c3d4"]').text()).toContain('Expires 2026-11-08 12:00 UTC')
    // The list result has no token field and nothing renders a cv1_ string.
    expect(wrapper.text()).not.toContain('cv1_')
  })

  it('renders the unpinned state for a row whose sessionIds key is omitted', async () => {
    // G4-G-ADDENDUM review MAJOR-1(b): the wire never answers null here —
    // the key is simply absent, and the row must not touch .length on it.
    const wrapper = await open()
    await wire.answer('token.list', { tokens: [{ ...ROW, id: 'unpin1', label: 'unpinned-row' }] })
    await tick()
    const row = wrapper.find('[data-testid="tokens-row-unpin1"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('all sessions')
    expect(row.text()).not.toContain('pinned to')
  })

  it('renders a pinned row from the present-array shape', async () => {
    const wrapper = await open()
    await wire.answer('token.list', { tokens: [{ ...ROW, id: 'pin100', label: 'pinned-row', sessionIds: ['s1', 's2'] }] })
    await tick()
    expect(wrapper.find('[data-testid="tokens-row-pin100"]').text()).toContain('pinned to 2 sessions')
  })

  it('sorts newest-first by the parsed instant, honouring each stamp offset', async () => {
    // summer-east is 10:00Z and summer-z is 10:30Z: a raw string compare
    // would rank '…T12:00:00+02:00' above '…T10:30:00+00:00'. Date.parse
    // does not. Feeding an already-sorted-by-string order makes every
    // wrong comparator produce a wrong DOM order.
    const summerEast = { id: 'stmr-east', label: 'summer-east', scopes: ['read'], createdAt: '2026-06-01T12:00:00+02:00', expiresAt: '2026-07-01T00:00:00+02:00' }
    const summerZ = { id: 'stmr-zulu', label: 'summer-zulu', scopes: ['read'], createdAt: '2026-06-01T10:30:00+00:00', expiresAt: '2026-07-01T00:00:00+00:00' }
    const winterZ = { id: 'wntr-zulu', label: 'winter-zulu', scopes: ['read'], createdAt: '2026-01-15T09:00:00+00:00', expiresAt: '2026-02-14T09:00:00+00:00' }
    const wrapper = await open()
    await wire.answer('token.list', { tokens: [winterZ, summerEast, summerZ] })
    await tick()
    const ids = wrapper
      .findAll('li.row')
      .map((li) => li.attributes('data-testid') ?? '')
    expect(ids).toEqual(['tokens-row-stmr-zulu', 'tokens-row-stmr-east', 'tokens-row-wntr-zulu'])
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
    // The store stamps idempotencyKey on every write-scope request
    // (IDEMPOTENT_METHODS family — memoryHistory/processesSection idiom).
    const { idempotencyKey: grantStamp, ...grantParams } = frames[0]!.params as Record<string, unknown>
    expect(grantStamp).toMatch(/^[0-9a-f]{32}$/)
    expect(grantParams).toEqual({ label: 'fresh', scopes: ['read'], days: 30 })
    expect(wrapper.find('[data-testid="tokens-mint-dialog"]').exists()).toBe(false)
    await wire.answer('token.grant', MINT)
    await tick()
    expect(wrapper.find('[data-testid="tokens-mint-token"]').text()).toBe(MINT.token)
    // The mint answer carries the same ISO stamp shape (expiryLabel crash pin).
    expect(wrapper.find('.mint-meta').text()).toContain('Expires 2026-11-10 08:00 UTC')
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
    const { idempotencyKey: pinStamp, ...pinParams } = wire.sent('token.grant')[0]!.params as Record<string, unknown>
    expect(pinStamp).toMatch(/^[0-9a-f]{32}$/) // write-stamped (IDEMPOTENT_METHODS)
    expect(pinParams).toEqual({
      label: 'pinned',
      scopes: ['read'],
      days: 90,
      sessionIds: ['s1', 's2'],
    })
  })

  it('the mint dialog runs the spine: modal, focus enters, Escape drops the token', async () => {
    // MN-12: the mint dialog was the one shown-once popup outside the shared
    // dialog spine. Mounted into the document so the spine's focus-in on open
    // is observable through document.activeElement (useDialog.spec idiom).
    await wire.connect(() => {}, {})
    const wrapper = mount(TokenPanel, { attachTo: document.body })
    await tick()
    await wire.answer('token.list', LIST)
    await tick()
    await wrapper.find('[data-testid="tokens-label"]').setValue('fresh')
    await wrapper.find('[data-testid="tokens-grant-form"]').trigger('submit')
    await tick()
    await wire.answer('token.grant', MINT)
    await tick()
    await nextTick()
    const root = wrapper.find('[data-testid="tokens-mint-dialog"]')
    expect(root.attributes('aria-modal')).toBe('true')
    expect(root.element.contains(document.activeElement)).toBe(true)
    await root.trigger('keydown', { key: 'Escape' })
    await tick()
    expect(wrapper.find('[data-testid="tokens-mint-dialog"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('cv1_')
    wrapper.unmount()
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
    const { idempotencyKey: revokeStamp, ...revokeParams } = wire.sent('token.revoke')[0]!.params as Record<string, unknown>
    expect(revokeStamp).toMatch(/^[0-9a-f]{32}$/) // write-stamped (IDEMPOTENT_METHODS)
    expect(revokeParams).toEqual({ id: 'a1b2c3d4' })
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
