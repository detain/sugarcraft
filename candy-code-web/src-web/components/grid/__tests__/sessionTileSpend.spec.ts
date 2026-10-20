import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import SessionTile from '../SessionTile.vue'
import { Wire } from './harness'
import { useSessionStore } from '../../../stores/session'

/**
 * WEB-5 parity, grid half: the tile's meta line must show an unpriced
 * session's spend as the lower bound it is (`≥$…`), and an exact figure
 * otherwise. Both payload shapes: state.unpriced true/false.
 */

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
})

async function openTile(unpriced: boolean, spentUsd: number) {
  const wire = await Wire.connect()
  const session = useSessionStore('s1')
  session.state.spentUsd = spentUsd
  session.state.unpriced = unpriced
  const wrapper = mount(SessionTile, { props: { id: 's1', focused: true }, attachTo: document.body })
  await flushPromises()
  await nextTick()
  return { wrapper, wire, session }
}

describe('SessionTile spend bound', () => {
  it('renders the exact figure for a priced session', async () => {
    const { wrapper } = await openTile(false, 0.004)
    expect(wrapper.find('[data-testid="tile-spend"]').text()).toBe('idle · $0.0040')
    wrapper.unmount()
  })

  it('marks the lower bound for an unpriced session, zero included', async () => {
    const { wrapper } = await openTile(true, 0)
    expect(wrapper.find('[data-testid="tile-spend"]').text()).toBe('idle · ≥$0.00')
    wrapper.unmount()
  })
})
