import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { METHOD_SCOPES } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { Wire } from '../components/grid/__tests__/harness'

/**
 * Negative control for WEB-1: `server.shutdown` is Scope::Admin on the
 * wire and stays deliberately dormant in the browser. The web client may
 * RECEIVE the `server.shutdown` event (ISS-052's draining banner reads it),
 * but it must never CALL the method. This spec is the standing proof: a
 * source scan of every shipped module, and a behavioural pin that the
 * event path involves no outbound request.
 */

const METHOD = ['server', 'shutdown'].join('.')

/** Every module that ships to the browser: src minus the generated schema, tests and fixtures. */
const sources = Object.entries(
  import.meta.glob<string>('../**/*.{ts,vue}', { query: '?raw', import: 'default', eager: true }),
).filter(([path]) => !path.includes('__tests__') && !path.startsWith('../protocol/generated.'))

describe('server.shutdown stays dormant in the client (WEB-1 negative control)', () => {
  it('the schema really scopes it to admin', () => {
    expect(METHOD_SCOPES[METHOD as keyof typeof METHOD_SCOPES]).toBe('admin')
  })

  it('no client module calls the method', () => {
    expect(sources.length).toBeGreaterThan(40)
    // request('M' | request<'X'>('M' | a literal frame body `method: 'M'`.
    // The event handler's `case 'server.shutdown'` deliberately does not match.
    const call = new RegExp(`\\brequest(?:<[^(]*>)?\\(\\s*['"]${METHOD.replace('.', '\\.')}['"]|method:\\s*['"]${METHOD.replace('.', '\\.')}['"]`)
    const callers = sources.filter(([, text]) => call.test(String(text)))
    expect(callers.map(([path]) => path)).toEqual([])
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
