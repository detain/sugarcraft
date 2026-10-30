import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useLayoutStore } from '../../stores/layout'
import { useSessionStore } from '../../stores/session'
import type { AssistantItem, TranscriptItem as TranscriptItemModel, UserItem } from '../../stores/reducer'
import Transcript from '../Transcript.vue'
import TranscriptItem from '../TranscriptItem.vue'
import SessionView from '../../views/SessionView.vue'
import { Wire } from '../grid/__tests__/harness'

/**
 * C-TUI-3.b: the three transcript row affordances.
 *
 *  - assistant rows copy their text through navigator.clipboard and are
 *    hidden outside a secure context (the notifications precedent,
 *    `stores/settings.ts`);
 *  - user rows ask for "Rewind to here" / "Edit & resend", which the view
 *    turns into one `command.exec` of `/rewind n --chat` behind a confirm,
 *    with "Edit & resend" seeding (never sending) the composer draft;
 *  - `n` counts exactly as the host's `Checkpoints::rewindConversation`
 *    does: one checkpoint per dispatched prompt, so the answer is one plus
 *    the `kind:'user'` items below the clicked row. Command echo rows are
 *    user-role but `uiOnly` and land as notices — the pin in
 *    "a uiOnly echo row is not a checkpoint" below is the load-bearing one.
 */

let wire: Wire
const clip = vi.fn()

function user(key: string, content: string): UserItem {
  return { kind: 'user', key, messageId: key, content }
}
function assistant(key: string, content: string): AssistantItem {
  return { kind: 'assistant', key, messageId: key, turnId: 't1', content }
}

function forceSecureContext(secure: boolean): void {
  Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true })
}

beforeEach(() => {
  setActivePinia(createPinia())
  // happy-dom ships no window.confirm; SessionView calls it behind the
  // browser guard, so every confirm-spying test needs a real function on
  // the property to wrap (and to restore to).
  Object.defineProperty(window, 'confirm', { value: () => true, configurable: true, writable: true })
  Object.defineProperty(navigator, 'clipboard', { value: { writeText: clip }, configurable: true })
  forceSecureContext(true)
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

afterEach(() => {
  vi.restoreAllMocks()
  clip.mockReset()
})

describe('row buttons', () => {
  it('a user row offers exactly the two checkpoint actions', () => {
    const row = mount(TranscriptItem, { props: { item: user('r1', 'first prompt') } })
    expect(row.find('[data-testid="rewind-here"]').exists()).toBe(true)
    expect(row.find('[data-testid="edit-resend"]').exists()).toBe(true)
    expect(row.find('[data-testid="copy-message"]').exists()).toBe(false)
  })

  it('an assistant row offers exactly a copy button, and copies its text', async () => {
    const row = mount(TranscriptItem, { props: { item: assistant('a1', 'the answer') } })
    const copy = row.find('[data-testid="copy-message"]')
    expect(copy.exists()).toBe(true)
    expect(copy.text()).toBe('copy')
    await copy.trigger('click')
    expect(clip).toHaveBeenCalledTimes(1)
    expect(clip).toHaveBeenCalledWith('the answer')
    expect(row.find('[data-testid="copy-message"]').text()).toBe('copied')
  })

  it('a failed clipboard write says so on the button instead of lying', async () => {
    clip.mockRejectedValueOnce(new Error('denied'))
    const row = mount(TranscriptItem, { props: { item: assistant('a1', 'text') } })
    await row.find('[data-testid="copy-message"]').trigger('click')
    expect(row.find('[data-testid="copy-message"]').text()).toBe("couldn't copy")
  })

  it('outside a secure context the copy affordance is absent, the rewind pair is not', () => {
    forceSecureContext(false)
    const answer = mount(TranscriptItem, { props: { item: assistant('a1', 'text') } })
    expect(answer.find('[data-testid="copy-message"]').exists()).toBe(false)
    const prompt = mount(TranscriptItem, { props: { item: user('r1', 'text') } })
    expect(prompt.find('[data-testid="rewind-here"]').exists()).toBe(true)
    expect(prompt.find('[data-testid="edit-resend"]').exists()).toBe(true)
  })

  it('notice and tool rows carry no row actions', () => {
    const notice = mount(TranscriptItem, { props: { item: { kind: 'notice', key: 'n1', level: 'info', content: 'echo' } as TranscriptItemModel } })
    expect(notice.find('[data-testid="rewind-here"]').exists()).toBe(false)
    expect(notice.find('[data-testid="copy-message"]').exists()).toBe(false)
  })

  it('Transcript relays both user-row actions up with the item', async () => {
    // Rows exist on the first flush (happy-dom measures 0px after that, so
    // the virtualiser drops them on any re-render — house pattern).
    const list = mount(Transcript, { props: { items: [user('r1', 'first prompt'), assistant('a1', 'text')] } })
    await list.find('[data-testid="rewind-here"]').trigger('click')
    expect(list.emitted('rewind')).toEqual([[user('r1', 'first prompt')]])
    expect(list.emitted('editResend')).toBeUndefined()

    const edit = mount(Transcript, { props: { items: [user('r2', 'again')] } })
    await edit.find('[data-testid="edit-resend"]').trigger('click')
    expect(edit.emitted('editResend')).toEqual([[user('r2', 'again')]])
    expect(edit.emitted('rewind')).toBeUndefined()
  })
})

async function openSeededSession(messages: Record<string, unknown>[]): Promise<ReturnType<typeof mount>> {
  wire = await Wire.connect()
  const session = useSessionStore('s1')
  const subscribed = session.subscribe()
  // throughSeq 0: the Wire numbers its events from seq 1, and the store's
  // replay cursor treats anything at or below the snapshot's throughSeq as
  // stale — a seeded 1 would silently drop the busy-status tests' events.
  wire.answer('session.subscribe', {
    reset: true,
    throughSeq: 0,
    pendingAsks: [],
    snapshot: { sessionId: 's1', status: 'idle', lastSeq: 0, pendingAsks: [], queue: [], messages },
  })
  await subscribed
  return mount(SessionView, { props: { id: 's1' }, global: { plugins: [] } })
}

/** Three prompts, one uiOnly echo, one answer — in transcript order. */
function seededRows(): Record<string, unknown>[] {
  return [
    { id: 'r1', role: 'user', content: 'first prompt' },
    { id: 'r2', role: 'assistant', content: 'an answer' },
    { id: 'r3', role: 'user', content: 'second prompt' },
    { id: 'r4', role: 'user', content: 'echo of /model gpt', uiOnly: true },
    { id: 'r5', role: 'user', content: 'third prompt' },
  ]
}

describe('SessionView rewind wiring', () => {
  it('rewinding the first of three prompts execs "3 --chat" — and the uiOnly echo is not counted', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('rewind', user('r1', 'first prompt'))
    await flushPromises()
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(confirm.mock.calls[0][0]).toContain('"first prompt"')
    const frames = wire.sent('command.exec')
    expect(frames).toHaveLength(1)
    // The wire layer stamps idempotencyKey on command.exec — match the rest.
    expect(frames[0].params).toMatchObject({ sessionId: 's1', name: 'rewind', args: '3 --chat' })
    // A command rewrites rows without an event — the store refetches.
    wire.answer('command.exec', { rows: [], effects: [] })
    await flushPromises()
    const resubscribes = wire.sent('session.subscribe')
    expect(resubscribes).toHaveLength(2)
    wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 2,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 2, pendingAsks: [], queue: [], messages: [] },
    })
    await flushPromises()
    expect(useSessionStore('s1').state.items).toEqual([])
  })

  it('a uiOnly echo row renders as a notice and never as a rewindable prompt', async () => {
    const wrapper = await openSeededSession(seededRows())
    const kinds = useSessionStore('s1').state.items.map((item) => item.kind)
    expect(kinds).toEqual(['user', 'assistant', 'user', 'notice', 'user'])
    // The newest prompt is one checkpoint back; the echo above it did not
    // shift the count — and it has no row of its own to click either.
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    wrapper.findComponent(Transcript).vm.$emit('rewind', user('r5', 'third prompt'))
    await flushPromises()
    expect(wire.sent('command.exec')[0].params).toMatchObject({ args: '1 --chat' })
    expect(kinds.filter((kind) => kind === 'user')).toHaveLength(3)
    void confirm
  })

  it('declining the confirm execs nothing', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('rewind', user('r1', 'first prompt'))
    await flushPromises()
    expect(wire.sent('command.exec')).toHaveLength(0)
    expect(wrapper.find('[data-testid="session-error"]').exists()).toBe(false)
  })

  it('edit & resend seeds the composer from the prompt and sends no prompt of its own', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('editResend', user('r3', 'second prompt'))
    await flushPromises()
    expect(wire.sent('command.exec')).toHaveLength(1)
    expect(wire.sent('command.exec')[0].params).toMatchObject({ name: 'rewind', args: '2 --chat' })
    expect(wire.sent('session.send')).toHaveLength(0)
    wire.answer('command.exec', { rows: [], effects: [] })
    await flushPromises()
    wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 2,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 2, pendingAsks: [], queue: [], messages: [] },
    })
    await flushPromises()
    expect(useLayoutStore().draft('s1')).toBe('second prompt')
  })

  it('edit & resend only offers the draft-replacement warning when a draft is held', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    // Plain rewind: never touches the held draft, never warns about it.
    useLayoutStore().setDraft('s1', 'unsent typing')
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('rewind', user('r1', 'first prompt'))
    await flushPromises()
    expect(confirm.mock.calls[0][0]).not.toContain('draft')
    expect(useLayoutStore().draft('s1')).toBe('unsent typing')
    // Edit & resend over a held draft: the confirm names the loss first.
    wrapper.findComponent(Transcript).vm.$emit('editResend', user('r3', 'second prompt'))
    await flushPromises()
    expect(confirm.mock.calls[1][0]).toContain('unsent draft in the composer will be replaced')
    confirm.mockReturnValue(false)
    wrapper.findComponent(Transcript).vm.$emit('editResend', user('r5', 'third prompt'))
    await flushPromises()
    // Declined: draft untouched, no third exec (the two above are the pair
    // from the successful rewind + the successful edit & resend).
    expect(wire.sent('command.exec')).toHaveLength(2)
    expect(useLayoutStore().draft('s1')).toBe('unsent typing')
  })

  it('a declined edit & resend leaves the draft exactly as it was', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    useLayoutStore().setDraft('s1', 'my real work')
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('editResend', user('r1', 'first prompt'))
    await flushPromises()
    expect(useLayoutStore().draft('s1')).toBe('my real work')
    expect(wire.sent('command.exec')).toHaveLength(0)
  })

  it('rewinding while a turn runs says so on the spot and execs nothing', async () => {
    const wrapper = await openSeededSession(seededRows())
    wire.event('s1', 'session.status', { status: 'busy' })
    await flushPromises()
    wrapper.findComponent(Transcript).vm.$emit('rewind', user('r1', 'first prompt'))
    await flushPromises()
    expect(wire.sent('command.exec')).toHaveLength(0)
    expect(wrapper.find('[data-testid="session-error"]').text()).toContain('running turn')
  })

  it('a row that vanished server-side is a loud error, not a silent rewind', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('rewind', user('gone', 'not in the transcript'))
    await flushPromises()
    expect(wire.sent('command.exec')).toHaveLength(0)
    expect(confirm).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="session-error"]').text()).toContain('no longer in the transcript')
  })

  it('a refused exec surfaces the refusal and does not seed the composer', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await openSeededSession(seededRows())
    wrapper.findComponent(Transcript).vm.$emit('editResend', user('r1', 'first prompt'))
    await flushPromises()
    const frame = wire.sent('command.exec').pop()
    wire.socket.fail(-32000, 'rewind_failed', 'the transcript holds no checkpoint that far back', frame?.id)
    await flushPromises()
    expect(useLayoutStore().draft('s1')).toBe('')
    expect(wrapper.find('[data-testid="session-error"]').text()).toContain('checkpoint that far back')
  })
})

describe('session store rewindTo', () => {
  it('execs the host command by name with the --chat scope and refetches on rows', async () => {
    wire = await Wire.connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    wire.answer('session.subscribe', {
      reset: true, throughSeq: 1, pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 1, pendingAsks: [], queue: [], messages: [] },
    })
    await subscribed
    const rewound = session.rewindTo(3)
    await flushPromises()
    expect(wire.sent('command.exec')[0].params).toMatchObject({ sessionId: 's1', name: 'rewind', args: '3 --chat' })
    wire.answer('command.exec', { rows: [{ id: 'x', role: 'notice', content: 'rewound' }], effects: [] })
    // The rows-answer trips a refetch; rewindTo only settles after that
    // subscribe lands, so answer it before awaiting the call.
    await flushPromises()
    expect(wire.sent('session.subscribe')).toHaveLength(2)
    wire.answer('session.subscribe', {
      reset: true, throughSeq: 2, pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 2, pendingAsks: [], queue: [], messages: [] },
    })
    const result = await rewound
    expect(result).toMatchObject({ rows: expect.any(Array) })
  })

  it('a rows-less answer (pure refusal) does not trigger a refetch', async () => {
    wire = await Wire.connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    wire.answer('session.subscribe', {
      reset: true, throughSeq: 1, pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 1, pendingAsks: [], queue: [], messages: [] },
    })
    await subscribed
    const rewound = session.rewindTo(1)
    await flushPromises()
    wire.answer('command.exec', { error: 'usage: /rewind [n] [--chat]', code: -32602 })
    await expect(rewound).resolves.toBeTruthy()
    await flushPromises()
    expect(wire.sent('session.subscribe')).toHaveLength(1)
  })
})
