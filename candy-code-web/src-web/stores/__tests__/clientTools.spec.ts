import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { flush } from '../../__tests__/fakes'
import { Wire } from '../../components/grid/__tests__/harness'
import { useClientToolsStore, type ClientToolImplementation } from '../clientTools'
import { useSessionStore } from '../session'

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
})

/** The registration answer a server with the door open gives. */
function registered(registrationId = 'abcd1234abcd1234'): Record<string, unknown> {
  return { registrationId, names: ['client__browser_context'], effective: 'next_turn' }
}

function callData(registrationId: string, overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    callId: 'call1234567890a',
    registrationId,
    name: 'client__browser_context',
    arguments: {},
    deadlineAt: Date.now() + 60_000,
    ...overrides,
  }
}

async function attached(wire: Wire): Promise<ReturnType<typeof useClientToolsStore>> {
  const store = useClientToolsStore()
  void store.attach('s1')
  await flush()
  wire.answer('tools.client.register', registered())
  await flush()
  return store
}

/** The params of the $i-th frame sent with $method (oldest first). */
function paramsOf(wire: Wire, method: string, i = 0): Record<string, unknown> {
  const frame = wire.sent(method)[i]
  if (!frame) throw new Error(`no ${method} frame`)
  return frame.params as Record<string, unknown>
}

describe('client-hosted tools in the tab', () => {
  it('announces the sample tool after a successful subscribe, with its payload shape', async () => {
    const wire = await Wire.connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 0,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 0, pendingAsks: [], queue: [], messages: [] },
    })
    await subscribed
    await flush()

    const frames = wire.sent('tools.client.register')
    expect(frames).toHaveLength(1)
    const params = paramsOf(wire, 'tools.client.register')
    expect(params.sessionId).toBe('s1')
    const tools = params.tools as Record<string, unknown>[]
    expect(tools).toHaveLength(1)
    const announced = tools[0]!
    expect(announced.name).toBe('browser_context')
    expect(typeof announced.description).toBe('string')
    const schema = announced.inputSchema as Record<string, unknown>
    expect(schema.type).toBe('object')
    expect(announced.timeoutSeconds).toBe(10)

    // And the answer lands as this session's registration.
    wire.answer('tools.client.register', registered())
    await flush()
    expect(useClientToolsStore().registrations.get('s1')).toBe('abcd1234abcd1234')
  })

  it('runs the call its own registration announced and answers with the content', async () => {
    const wire = await Wire.connect()
    const store = await attached(wire)

    wire.event('s1', 'tool.client.call', callData('deadbeefdeadbeef'), false)
    wire.event('other', 'tool.client.call', callData('abcd1234abcd1234'), false)
    await flush()
    expect(wire.sent('tools.client.result'), 'a foreign id and another session are not this tab\'s work').toHaveLength(0)

    // A name under our own registration that we do not implement is answered
    // as an error — the registration matching is the door, the name is ours
    // to refuse honestly.
    wire.event('s1', 'tool.client.call', callData('abcd1234abcd1234', { name: 'client__nobody' }), false)
    await flush()
    let answers = wire.sent('tools.client.result')
    expect(answers).toHaveLength(1)
    expect((answers[0] as { params: Record<string, unknown> }).params.isError).toBe(true)

    wire.event('s1', 'tool.client.call', callData('abcd1234abcd1234'), false)
    await flush()
    answers = wire.sent('tools.client.result')
    expect(answers).toHaveLength(2)
    const answer = (answers[1] as { params: Record<string, unknown> }).params
    expect(answer.callId).toBe('call1234567890a')
    expect(answer.registrationId).toBe('abcd1234abcd1234')
    expect(answer.sessionId).toBe('s1')
    expect(answer.isError).toBe(false)
    const context = JSON.parse(String(answer.content)) as Record<string, unknown>
    expect(Object.keys(context).sort()).toEqual(['colorScheme', 'devicePixelRatio', 'locale', 'online', 'timeZone', 'viewport'])

    expect(store.disabled).toBe(false)
  })

  it('answers a call whose deadline already passed as an error without running', async () => {
    const wire = await Wire.connect()
    const store = useClientToolsStore()
    let ran = 0
    store.implement({
      name: 'counter',
      description: 'counts how often it ran',
      inputSchema: { type: 'object', properties: {} },
      run: () => {
        ran++
        return 'ok'
      },
    } satisfies ClientToolImplementation)
    void store.attach('s1')
    await flush()
    wire.answer('tools.client.register', { registrationId: 'r'.repeat(16), names: ['client__counter'], effective: 'next_turn' })
    await flush()

    wire.event('s1', 'tool.client.call', callData('r'.repeat(16), { name: 'client__counter', deadlineAt: Date.now() - 1 }), false)
    await flush()
    const answers = wire.sent('tools.client.result')
    expect(answers).toHaveLength(1)
    expect(paramsOf(wire, 'tools.client.result').isError).toBe(true)
    expect(String(paramsOf(wire, 'tools.client.result').content)).toContain('deadline')
    expect(ran).toBe(0)
  })

  it('answers a handler that misses its deadline, and one that throws, as errors', async () => {
    const wire = await Wire.connect()
    const store = useClientToolsStore()
    store.implement({
      name: 'slowpoke',
      description: 'never finishes in five milliseconds',
      inputSchema: { type: 'object', properties: {} },
      run: () => new Promise((resolve) => setTimeout(resolve, 5_000, 'late')),
    })
    store.implement({
      name: 'thrower',
      description: 'fails on purpose',
      inputSchema: { type: 'object', properties: {} },
      run: () => {
        throw new Error('broken on purpose')
      },
    })
    void store.attach('s1')
    await flush()
    wire.answer('tools.client.register', { registrationId: 'f'.repeat(16), names: ['client__slowpoke', 'client__thrower'], effective: 'next_turn' })
    await flush()

    wire.event('s1', 'tool.client.call', callData('f'.repeat(16), { name: 'client__slowpoke', deadlineAt: Date.now() + 5 }), false)
    await new Promise((resolve) => setTimeout(resolve, 30))
    await flush()
    let answers = wire.sent('tools.client.result')
    expect(answers).toHaveLength(1)
    expect(paramsOf(wire, 'tools.client.result').isError).toBe(true)
    expect(String(paramsOf(wire, 'tools.client.result').content)).toContain('did not finish')

    wire.event('s1', 'tool.client.call', callData('f'.repeat(16), { name: 'client__thrower' }), false)
    await flush()
    answers = wire.sent('tools.client.result')
    expect(answers).toHaveLength(2)
    expect(paramsOf(wire, 'tools.client.result', 1).isError).toBe(true)
    expect(String(paramsOf(wire, 'tools.client.result', 1).content)).toContain('broken on purpose')
  })

  it('a disabled door switches the store off for the connection without retrying', async () => {
    const wire = await Wire.connect()
    const store = useClientToolsStore()
    void store.attach('s1')
    await flush()
    wire.socket.fail(403, 'client_tools_disabled', 'this server has client-hosted tools disabled (server.clientTools)')
    await flush()
    expect(store.disabled).toBe(true)
    expect(store.lastError, 'a closed door is the setting, not an error to chew on').toBe(null)

    void store.attach('s1')
    void store.attach('s2')
    await flush()
    expect(wire.sent('tools.client.register'), 'no retry storm against a door that is shut').toHaveLength(1)

    // Even a stray event finds no listener willing to work while off.
    wire.event('s1', 'tool.client.call', callData('abcd1234abcd1234'), false)
    await flush()
    expect(wire.sent('tools.client.result')).toHaveLength(0)
  })
})
