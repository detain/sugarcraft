import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import type { ToolItem } from '../../stores/reducer'
import { useTranscriptViewStore } from '../../stores/transcriptView'
import ReasoningFold from '../ReasoningFold.vue'
import ToolCard from '../ToolCard.vue'
import Transcript from '../Transcript.vue'

/**
 * C-WEB-4 (GAP-UI-22, GAP-UI-32 web half): the two per-viewer transcript
 * toggles. Pinned here, per the step: each toggle flips every fold or card;
 * a card the viewer toggled by hand keeps its state through toolbar flips;
 * and the toggles persist across a remount — a fresh row (the virtualiser
 * recycles rows away and back) reads the store, not stale local state.
 *
 * Rows are mounted directly rather than through Transcript's virtualiser
 * after the first flush: happy-dom measures 0px, so the virtualiser drops
 * every row on any re-render in this environment (the Transcript tests in
 * components.spec.ts assert synchronously for the same reason). The toolbar
 * itself lives outside the rows and is exercised on Transcript directly.
 */

function tool(overrides: Partial<ToolItem> = {}): ToolItem {
  return { kind: 'tool', key: 'k', toolCallId: 'c1', name: 'Bash', arguments: { command: 'ls' }, status: 'done', content: 'ok', ...overrides }
}

beforeEach(() => {
  setActivePinia(createPinia())
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

function foldOpen(fold: ReturnType<typeof mount>): boolean {
  return (fold.element as HTMLDetailsElement).open
}

function cardOpen(card: ReturnType<typeof mount>): string | undefined {
  return card.find('button.head').attributes('aria-expanded')
}

describe('thinking toggle', () => {
  it('flips every settled fold open and back', async () => {
    const folds = [mount(ReasoningFold, { props: { text: 'one' } }), mount(ReasoningFold, { props: { text: 'two' } })]
    expect(folds.map(foldOpen)).toEqual([false, false])

    useTranscriptViewStore().toggleThinking()
    await nextTick()
    expect(folds.map(foldOpen)).toEqual([true, true])

    useTranscriptViewStore().toggleThinking()
    await nextTick()
    expect(folds.map(foldOpen)).toEqual([false, false])
  })

  it('a live fold stays open whatever the toggle says', async () => {
    const live = mount(ReasoningFold, { props: { text: 'streaming', live: true } })
    expect(foldOpen(live)).toBe(true)
    useTranscriptViewStore().toggleThinking()
    await nextTick()
    expect(foldOpen(live)).toBe(true)
    useTranscriptViewStore().toggleThinking()
    await nextTick()
    expect(foldOpen(live)).toBe(true)
  })
})

describe('expand-tools toggle', () => {
  it('flips every card; a failure or refusal opens regardless', async () => {
    const cards = [
      mount(ToolCard, { props: { item: tool({ toolCallId: 'a' }) } }),
      mount(ToolCard, { props: { item: tool({ toolCallId: 'b' }) } }),
    ]
    expect(cards.map(cardOpen)).toEqual(['false', 'false'])

    useTranscriptViewStore().toggleExpandTools()
    await nextTick()
    expect(cards.map(cardOpen)).toEqual(['true', 'true'])

    useTranscriptViewStore().toggleExpandTools()
    await nextTick()
    expect(cards.map(cardOpen)).toEqual(['false', 'false'])

    for (const status of ['error', 'denied'] as const) {
      const bad = mount(ToolCard, { props: { item: tool({ status }) } })
      expect(cardOpen(bad)).toBe('true')
    }
  })

  it('a manually toggled card keeps its state through toolbar flips', async () => {
    const view = useTranscriptViewStore()
    view.toggleExpandTools() // expand everything
    const cards = [
      mount(ToolCard, { props: { item: tool({ toolCallId: 'a' }) } }),
      mount(ToolCard, { props: { item: tool({ toolCallId: 'b' }) } }),
    ]
    expect(cards.map(cardOpen)).toEqual(['true', 'true'])

    await cards[0]!.find('button.head').trigger('click') // close the first by hand
    view.toggleExpandTools() // collapse all
    await nextTick()
    expect(cards.map(cardOpen)).toEqual(['false', 'false'])
    view.toggleExpandTools() // expand all again
    await nextTick()
    // The hand-closed card stays closed; the untouched card follows the store.
    expect(cards.map(cardOpen)).toEqual(['false', 'true'])

    // Hand-opened survives the other direction too.
    view.toggleExpandTools() // back to collapsed for fresh rows
    await nextTick()
    const plain = mount(ToolCard, { props: { item: tool({ toolCallId: 'c' }) } })
    expect(cardOpen(plain)).toBe('false')
    await plain.find('button.head').trigger('click')
    view.toggleExpandTools()
    await nextTick()
    view.toggleExpandTools()
    await nextTick()
    expect(cardOpen(plain)).toBe('true')
  })
})

describe('toolbar and persistence', () => {
  it('Transcript offers the two switches and reports them pressed', async () => {
    const wrapper = mount(Transcript, { props: { items: [] } })
    const thinking = wrapper.find('[data-testid="toggle-thinking"]')
    const tools = wrapper.find('[data-testid="toggle-tools"]')
    expect(thinking.attributes('aria-pressed')).toBe('false')
    expect(tools.attributes('aria-pressed')).toBe('false')
    await thinking.trigger('click')
    await tools.trigger('click')
    expect(thinking.attributes('aria-pressed')).toBe('true')
    expect(tools.attributes('aria-pressed')).toBe('true')
    expect(useTranscriptViewStore().showThinking).toBe(true)
    expect(useTranscriptViewStore().expandTools).toBe(true)
  })

  it('the toggles persist across a remount (fresh pinia reads storage back)', async () => {
    const wrapper = mount(Transcript, { props: { items: [] } })
    await wrapper.find('[data-testid="toggle-thinking"]').trigger('click')
    await wrapper.find('[data-testid="toggle-tools"]').trigger('click')
    await nextTick() // let the store watchers land their writes
    wrapper.unmount()

    // A brand-new pinia — page reload, or a virtualised row recycled away and
    // back — must open everything from the stored switches, not local state.
    setActivePinia(createPinia())
    const fold = mount(ReasoningFold, { props: { text: 'later' } })
    const card = mount(ToolCard, { props: { item: tool() } })
    expect(foldOpen(fold)).toBe(true)
    expect(cardOpen(card)).toBe('true')

    const again = mount(Transcript, { props: { items: [] } })
    expect(again.find('[data-testid="toggle-thinking"]').attributes('aria-pressed')).toBe('true')
    expect(again.find('[data-testid="toggle-tools"]').attributes('aria-pressed')).toBe('true')
  })
})
