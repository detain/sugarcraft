import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { effectScope, nextTick, watch } from 'vue'
import { ask, Wire } from '../../components/grid/__tests__/harness'
import { flush } from '../../__tests__/fakes'
import type { SessionAsk } from '../../stores/approvals'
import { askKind, questionAnnouncement, useQuestionAnnouncements } from '../questions'

/**
 * WEB-7a: question arrivals must announce through the app-root ARIA live
 * region. The store-level pin: a newly-arrived ask produces the announced
 * string via the composable that feeds the region.
 */

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
})

describe('question announcements (WEB-7a)', () => {
  it('names the session, the tool and the kind for a permission ask', () => {
    const gateAsk = ask('k1', 's1') as unknown as SessionAsk
    expect(questionAnnouncement(gateAsk, 's1')).toBe('s1 — Bash permission request is waiting for an answer: needs approval')
  })

  it('calls a tool-put ask a question and a hook ask a hook approval', () => {
    const toolAsk = { ...ask('k1', 's1', 'AskUser'), source: 'tool:AskUser' } as unknown as SessionAsk
    const hookAsk = { ...ask('k2', 's1'), source: 'hook:audit' } as unknown as SessionAsk
    expect(askKind(toolAsk)).toBe('question')
    expect(askKind(hookAsk)).toBe('hook approval')
  })

  it('drops an empty reason instead of announcing a dangling colon', () => {
    const blank = { ...ask('k1', 's1'), reason: '  ' } as unknown as SessionAsk
    expect(questionAnnouncement(blank, 's1')).toBe('s1 — Bash permission request is waiting for an answer')
  })

  it('announces the newest question as it arrives over the socket', async () => {
    const wire = await Wire.connect()
    wire.answer('permission.pending', { items: [] })
    await flush()
    const scope = effectScope()
    let announced!: { readonly value: string }
    scope.run(() => {
      announced = useQuestionAnnouncements()
    })
    expect(announced.value).toBe('')

    wire.serverEvent('permission.asked', ask('k1', 's1'))
    await nextTick()
    expect(announced.value).toBe('s1 — Bash permission request is waiting for an answer: needs approval')

    // A second arrival replaces the sentence — the region announces each change.
    wire.serverEvent('permission.asked', { ...ask('k2', 's1'), tool: 'Edit', reason: 'wants to write' })
    await nextTick()
    expect(announced.value).toBe('s1 — Edit permission request is waiting for an answer: wants to write')
    scope.stop()
  })
})

/**
 * WEB-7-P1: two quality-of-announcement fixes on the live-region feed.
 * (a) An identical repeat ask must still mutate the region's text — a screen
 * reader stays silent when the node never changes, so `useQuestionAnnouncements`
 * clears the ref and re-fills it on the next tick, forcing a fresh write.
 * (b) A trust prompt (`kind:"trust"`) is filtered out before `approvals.onNew`,
 * so it is never announced there; the composable also watches the Context panel
 * store's open trust prompt and speaks it once.
 */
describe('question announcement repeats and trust prompts (WEB-7-P1)', () => {
  it('two identical consecutive asks produce two DOM text writes of the sentence', async () => {
    const wire = await Wire.connect()
    wire.answer('permission.pending', { items: [] })
    await flush()
    const scope = effectScope()
    let announced!: { readonly value: string }
    scope.run(() => {
      announced = useQuestionAnnouncements()
    })
    const writes: string[] = []
    watch(() => announced.value, (value) => writes.push(value), { flush: 'sync' })

    const sentence = 's1 — Bash permission request is waiting for an answer: needs approval'
    wire.serverEvent('permission.asked', ask('k1', 's1'))
    await nextTick()
    expect(announced.value).toBe(sentence)

    // The same session, tool and reason re-asked: byte-identical sentence.
    wire.serverEvent('permission.asked', ask('k2', 's1'))
    await nextTick()

    // Every ask must register a fresh non-empty write, not just the first.
    const sentenceWrites = writes.filter((w) => w === sentence)
    expect(sentenceWrites).toHaveLength(2)
    // And the second one is preceded by a clear, so the region genuinely mutates.
    expect(writes).toContain('')
    scope.stop()
  })

  it('a trust prompt adopted by the Context panel store is announced once', async () => {
    const wire = await Wire.connect()
    wire.answer('permission.pending', { items: [] })
    await flush()
    const scope = effectScope()
    let announced!: { readonly value: string }
    scope.run(() => {
      announced = useQuestionAnnouncements()
    })
    const writes: string[] = []
    watch(() => announced.value, (value) => writes.push(value), { flush: 'sync' })
    const trustSentence = 's1 — project trust prompt for /srv/app is waiting in the Context panel'

    const trust = { askId: 't1', kind: 'trust', target: 'mcp', root: '/srv/app', reason: 'load tools', status: 'asked', sessionId: 's1' }
    wire.serverEvent('permission.asked', trust)
    await nextTick()
    await nextTick()
    expect(announced.value).toBe(trustSentence)

    // Re-delivery of the same prompt must not announce it a second time.
    wire.serverEvent('permission.asked', { ...trust })
    await nextTick()
    await nextTick()
    expect(writes.filter((w) => w === trustSentence)).toHaveLength(1)
    scope.stop()
  })
})
