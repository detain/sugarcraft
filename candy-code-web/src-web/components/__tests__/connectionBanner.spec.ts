import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useConnectionStore } from '../../stores/connection'
import ConnectionBanner from '../ConnectionBanner.vue'

let sockets: FakeSocket[]
let timers: FakeTimers

function socket(): FakeSocket {
  const last = sockets[sockets.length - 1]
  if (!last) throw new Error('no socket')
  return last
}

/** The ladder's six waits, each ended by the socket dying before its handshake. */
async function spendTheLadder(): Promise<void> {
  socket().drop()
  for (const delay of [500, 1_000, 2_000, 4_000, 8_000, 15_000]) {
    timers.advance(delay)
    await flush()
    socket().drop()
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  sockets = []
  timers = new FakeTimers()
})

describe('ConnectionBanner · the exhausted ladder (ISS-052)', () => {
  it('says the server is gone once the retries run out, and stops claiming to retry', async () => {
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
    socket().answer(helloResult(), 'hello')

    const wrapper = mount(ConnectionBanner)
    expect(wrapper.find('[data-testid="banner-offline"]').exists()).toBe(false)

    await spendTheLadder()

    const offline = wrapper.find('[data-testid="banner-offline"]')
    expect(offline.exists()).toBe(true)
    expect(offline.text()).toContain('the server did not answer after 6 tries')
    expect(offline.text()).toContain('Retries have stopped')
    // The count-down lie is gone: no attempt is scheduled any more.
    expect(wrapper.find('[data-testid="banner-reconnecting"]').exists()).toBe(false)
    expect(timers.pending).toBe(0)

    await offline.find('button').trigger('click')
    await flush()
    expect(sockets).toHaveLength(8)
    expect(wrapper.find('[data-testid="banner-reconnecting"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="banner-offline"]').exists()).toBe(false)
  })
})
