import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { t } from '../../lib/i18n'
import type { TranscriptItem } from '../../stores/reducer'
import Transcript from '../Transcript.vue'

/**
 * CW-UX-09: an empty transcript rendered a blank scroller — nothing told the
 * reader whether the run had produced nothing yet or the view was merely
 * waiting. The block is keyed off `items.length` and reuses the existing
 * catalog string (no new key: the i18n chain window is closed for this lane).
 */
const notice: TranscriptItem = { kind: 'notice', key: 'n1', level: 'info', content: 'a row' }

beforeEach(() => {
  setActivePinia(createPinia())
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

describe('CW-UX-09 — the transcript announces emptiness', () => {
  it('shows the empty state when there are no items', () => {
    const wrapper = mount(Transcript, { props: { items: [] } })
    const empty = wrapper.find('[data-testid="transcript-empty"]')
    expect(empty.exists()).toBe(true)
    expect(empty.text()).toBe(t('web.agents.transcript_empty'))
  })

  it('and stands down as soon as the run has a row', () => {
    const wrapper = mount(Transcript, { props: { items: [notice] } })
    expect(wrapper.find('[data-testid="transcript-empty"]').exists()).toBe(false)
  })
})
