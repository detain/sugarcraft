import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { useGenerateStore } from '../../../stores/generate'
import GeneratePanel from '../GeneratePanel.vue'

/**
 * WEB-1d: the Generate panel over `generate.submit` + `generate.status` —
 * the submit call shape, the polling lifecycle (success and failure), and
 * session-switch cleanup (the watched job belongs to the session on screen).
 *
 * The store arms its poll through setTimeout(pollMs=0 seam); that callback
 * runs AFTER the harness answer's internal flush settles (same-delay timers
 * fire in arming order), so every poll round goes through `tick()` — one
 * macrotask plus a microtask drain — before the next frame is asserted or
 * answered.
 */

let wire: Wire

const MEDIA_HELLO = { features: { methods: [], events: [], media: { image: true, video: false } } }

const ARTIFACT = { artifactId: 'img-1', kind: 'image', path: '/srv/media/s1/img-1.png', bytes: 1234, mime: 'image/png' }

const tick = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 0))
  await flushPromises()
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

// A non-terminal watch re-arms its poll through setTimeout(0); if it is left
// armed it fires during the NEXT test's connect flush, and the store's lazy
// useConnectionStore() call would then mint the next pinia's connection store
// with a premature (media-bearing) hello. Teardown through the store's own
// forget(), exactly as the panel's clear button does.
afterEach(() => {
  useGenerateStore().forget()
})

/** Connect with media offered and mount the panel with a zero poll interval. */
async function open(sessionId = 's1'): Promise<ReturnType<typeof mount>> {
  await wire.connect(() => {}, MEDIA_HELLO)
  useGenerateStore().pollMs = 0
  const wrapper = mount(GeneratePanel, { props: { sessionId } })
  await flushPromises()
  return wrapper
}

/** Submit `prompt` through the form and land the job id answer. */
async function submit(wrapper: ReturnType<typeof mount>, prompt: string, jobId: string): Promise<void> {
  await wrapper.find('[data-testid="generate-prompt"]').setValue(prompt)
  await wrapper.find('[data-testid="generate-form"]').trigger('submit')
  await tick()
  await wire.answer('generate.submit', { jobId })
  await tick()
}

/** The submit params on the wire, with the client's auto-injected retry key split off. */
function submitParams(wrapper: ReturnType<typeof mount>): Record<string, unknown> {
  void wrapper
  const { idempotencyKey, ...rest } = wire.sent('generate.submit').at(-1)!.params as Record<string, unknown>
  // The client keys idempotent-safe sends so a reconnect retry gets the
  // original answer instead of rendering twice (client.ts:162-166).
  expect(typeof idempotencyKey).toBe('string')
  return rest
}

describe('GeneratePanel submit (WEB-1d)', () => {
  it('sends exactly the parameters the server accepts and starts the watch', async () => {
    const wrapper = await open()
    await wrapper.find('[data-testid="generate-prompt"]').setValue('  a red fox  ')
    await wrapper.find('[data-testid="generate-count"]').setValue('2')
    await wrapper.find('[data-testid="generate-form"]').trigger('submit')
    await tick()

    expect(wire.sent('generate.submit')).toHaveLength(1)
    expect(submitParams(wrapper)).toEqual({ sessionId: 's1', prompt: 'a red fox', batch_size: 2 })

    await wire.answer('generate.submit', { jobId: 'j1' })
    expect(wrapper.find('[data-testid="generate-state"]').text()).toBe('queued')
    // The zero-interval seam armed the first status poll; let it fire.
    await tick()
    expect(wire.sent('generate.status').at(-1)?.params).toEqual({ sessionId: 's1', jobId: 'j1' })
  })

  it('keeps an empty prompt refused and carries a non-empty negative', async () => {
    const wrapper = await open()
    expect(wrapper.find('[data-testid="generate-submit"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="generate-prompt"]').setValue('cat')
    await wrapper.find('[data-testid="generate-negative"]').setValue('blurry')
    await wrapper.find('[data-testid="generate-form"]').trigger('submit')
    await tick()
    expect(submitParams(wrapper)).toEqual({ sessionId: 's1', prompt: 'cat', negative_prompt: 'blurry' })
  })

  it('a refused submit fails soft: the line shows, no job, no polls', async () => {
    const wrapper = await open()
    await wrapper.find('[data-testid="generate-prompt"]').setValue('cat')
    await wrapper.find('[data-testid="generate-form"]').trigger('submit')
    await tick()
    await wire.fail('generate.submit', -32003, 'permission_denied', 'consent refused')
    expect(wrapper.find('[data-testid="generate-error"]').text()).toContain('consent refused')
    expect(wrapper.find('[data-testid="generate-job"]').exists()).toBe(false)
    expect(wire.sent('generate.status')).toHaveLength(0)
  })
})

describe('GeneratePanel polling lifecycle (WEB-1d)', () => {
  it('running paints progress, done lands artifacts and stops the dial', async () => {
    const wrapper = await open()
    await submit(wrapper, 'fox', 'j1')

    await wire.answer('generate.status', { state: 'running', progress01: 0.4, etaSeconds: 12.7 })
    expect(wrapper.find('[data-testid="generate-state"]').text()).toBe('running')
    expect(wrapper.find('[data-testid="generate-percent"]').text()).toBe('40%')
    expect(wrapper.find('[data-testid="generate-bar"]').exists()).toBe(true)
    await tick() // the re-armed poll fires

    await wire.answer('generate.status', { state: 'done', progress01: 1, artifacts: [ARTIFACT] })
    expect(wrapper.find('[data-testid="generate-state"]').text()).toBe('done')
    expect(wrapper.find('[data-testid="generate-bar"]').exists()).toBe(false)
    const artifacts = wrapper.findAll('[data-testid="generate-artifact"]')
    expect(artifacts).toHaveLength(1)
    expect(artifacts[0]?.text()).toContain('image') // MediaCard badge — the sanctioned media.fetch surface

    // Terminal: no further status frames, however long we idle.
    const polls = wire.sent('generate.status').length
    await new Promise((resolve) => setTimeout(resolve, 5))
    await flushPromises()
    expect(wire.sent('generate.status')).toHaveLength(polls)
  })

  it('a failed job shows the ledger error and stops polling', async () => {
    const wrapper = await open()
    await submit(wrapper, 'fox', 'j2')
    await wire.answer('generate.status', { state: 'failed', progress01: 0, error: 'sd server is down' })

    expect(wrapper.find('[data-testid="generate-state"]').text()).toBe('failed')
    expect(wrapper.find('[data-testid="generate-job-error"]').text()).toContain('sd server is down')
    const polls = wire.sent('generate.status').length
    await new Promise((resolve) => setTimeout(resolve, 5))
    await flushPromises()
    expect(wire.sent('generate.status')).toHaveLength(polls)
  })

  it('a refused poll shows the wire line, stops the timer, and reload re-arms', async () => {
    const wrapper = await open()
    await submit(wrapper, 'fox', 'j3')
    await wire.fail('generate.status', -32001, 'not_found', 'no such job')

    expect(wrapper.find('[data-testid="generate-error"]').text()).toContain('no such job')
    expect(wrapper.find('[data-testid="generate-reload"]').exists()).toBe(true)
    const polls = wire.sent('generate.status').length
    await new Promise((resolve) => setTimeout(resolve, 5))
    await flushPromises()
    expect(wire.sent('generate.status')).toHaveLength(polls) // the timer stopped, it does not hammer

    await wrapper.find('[data-testid="generate-reload"]').trigger('click')
    await flushPromises()
    expect(wire.sent('generate.status')).toHaveLength(polls + 1)
  })
})

describe('GeneratePanel session switch (WEB-1d)', () => {
  it('navigating away drops the watch; late answers never repaint the other session', async () => {
    const wrapper = await open('s1')
    await submit(wrapper, 'fox', 'j1')
    expect(wrapper.find('[data-testid="generate-job"]').exists()).toBe(true)

    await wrapper.setProps({ sessionId: 's2' })
    await flushPromises()
    expect(wrapper.find('[data-testid="generate-job"]').exists()).toBe(false)

    const polls = wire.sent('generate.status').length
    // A straggler answer for the s1 poll that fired before the switch must
    // not resurrect the dropped job under the new session.
    const straggler = wire.sent('generate.status').at(-1)
    if (straggler !== undefined) {
      wire.socket().answer({ state: 'done', progress01: 1, artifacts: [ARTIFACT] }, straggler.id as string)
      await flushPromises()
      await tick()
    }
    expect(wrapper.find('[data-testid="generate-job"]').exists()).toBe(false)
    await new Promise((resolve) => setTimeout(resolve, 5))
    await flushPromises()
    // The dropped watch armed no new polls.
    expect(wire.sent('generate.status')).toHaveLength(polls)
  })

  it('a second session can run its own job untouched by the first', async () => {
    const wrapper = await open('s1')
    await submit(wrapper, 'fox', 'j1')

    await wrapper.setProps({ sessionId: 's2' })
    await wrapper.find('[data-testid="generate-prompt"]').setValue('owl')
    await wrapper.find('[data-testid="generate-form"]').trigger('submit')
    await tick()
    expect(submitParams(wrapper)).toEqual({ sessionId: 's2', prompt: 'owl' })
    await wire.answer('generate.submit', { jobId: 'j2' })
    await tick()
    expect(wrapper.find('[data-testid="generate-job"]').text()).toContain('j2')

    await wire.answer('generate.status', { state: 'running', progress01: 0.5 })
    expect(wrapper.find('[data-testid="generate-state"]').text()).toBe('running')
    expect(useGenerateStore().job?.jobId).toBe('j2')
  })
})

describe('GeneratePanel capability gate (WEB-1d)', () => {
  it('no media feature in hello — nothing is offered, nothing is guessed', async () => {
    await wire.connect()
    const wrapper = mount(GeneratePanel, { props: { sessionId: 's1' } })
    await flushPromises()
    expect(wrapper.find('[data-testid="generate-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="generate-unoffered"]').text()).toContain('does not report media capability')
  })

  it('media reported but image off — the honest refusal line', async () => {
    await wire.connect(() => {}, { features: { methods: [], events: [], media: { image: false, video: true } } })
    const wrapper = mount(GeneratePanel, { props: { sessionId: 's1' } })
    await flushPromises()
    expect(wrapper.find('[data-testid="generate-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="generate-unoffered"]').text()).toContain('not enabled')
  })
})
