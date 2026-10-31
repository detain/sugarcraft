import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { METHOD_SCOPES } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { Wire } from '../components/grid/__tests__/harness'

/**
 * C-WEB-5.g flipped the WEB-1 negative control into a positive pin:
 * `server.shutdown` is still Scope::Admin on the wire, and the browser may
 * now call it — but only through the confirmed stop control in the About
 * overlay. AboutModal.vue is the ONLY shipped module allowed to call it, and
 * the two doors R13 demands (admin scope plus a confirm that names the blast
 * radius) are pinned behaviourally in components/__tests__/aboutModal.spec.ts.
 * The client still RECEIVES the `server.shutdown` event without any outbound
 * request (ISS-052's draining banner reads it) — that path must hold on a
 * connection that never touches the stop button, and the scan below fails
 * loudly if any second caller appears.
 */

const METHOD = ['server', 'shutdown'].join('.')

/** Every module that ships to the browser: src minus the generated schema, tests and fixtures. */
const sources = Object.entries(
  import.meta.glob<string>('../**/*.{ts,vue}', { query: '?raw', import: 'default', eager: true }),
).filter(([path]) => !path.includes('__tests__') && !path.startsWith('../protocol/generated.'))

describe('server.shutdown is called ONLY by the confirmed About stop (C-WEB-5.g)', () => {
  it('the schema really scopes it to admin', () => {
    expect(METHOD_SCOPES[METHOD as keyof typeof METHOD_SCOPES]).toBe('admin')
  })

  it('AboutModal is the single client module that calls the method', () => {
    expect(sources.length).toBeGreaterThan(40)
    // request('M' | request<'X'>('M' | a literal frame body `method: 'M'`.
    // The event handler's `case 'server.shutdown'` deliberately does not match.
    const call = new RegExp(`\\brequest(?:<[^(]*>)?\\(\\s*['"]${METHOD.replace('.', '\\.')}['"]|method:\\s*['"]${METHOD.replace('.', '\\.')}['"]`)
    const callers = sources.filter(([, text]) => call.test(String(text)))
    expect(callers.map(([path]) => path)).toEqual(['../components/AboutModal.vue'])
  })

  it('the shutdown event is received without the method ever being sent', async () => {
    setActivePinia(createPinia())
    const wire = await Wire.connect()
    const connection = useConnectionStore()
    expect(connection.shutdown).toBeNull()
    wire.serverEvent('server.shutdown', { reason: 'maintenance', graceSeconds: 3 })
    expect(connection.shutdown).toEqual({ reason: 'maintenance', graceSeconds: 3 })
    expect(wire.socket.sent.filter((frame) => frame.method === METHOD)).toEqual([])
  })
})
