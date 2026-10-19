import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import SidePanels from '../SidePanels.vue'
import ChangesPanel from '../ChangesPanel.vue'

/**
 * WEB-1b: the Changes tab — `files.changed` for the list and `files.diff`
 * for the text, with the per-file view carved out of the whole-workspace
 * diff on the client (the wire takes a revision, not a path).
 */

let wire: Wire

const CHANGED = {
  items: [
    { path: 'src/a.ts', status: 'M' },
    { path: 'x/y.ts', status: 'M' },
    { path: 'notes.md', status: '??' },
  ],
  truncated: false,
}

const DIFF = [
  'diff --git a/src/a.ts b/src/a.ts',
  'index 1111111..2222222 100644',
  '--- a/src/a.ts',
  '+++ b/src/a.ts',
  '@@ -1,2 +1,2 @@',
  ' keep',
  '-old',
  '+new',
  'diff --git a/x/y.ts b/x/y.ts',
  'index 3333333..4444444 100644',
  '--- a/x/y.ts',
  '+++ b/x/y.ts',
  '@@ -1 +1 @@',
  '-p',
  '+p2',
].join('\n')

async function openPanel(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(ChangesPanel)
  await flushPromises()
  await wire.answer('files.changed', CHANGED)
  await wire.answer('files.diff', { ref: 'HEAD', diff: DIFF, truncated: false })
  await flushPromises()
  return wrapper
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

describe('ChangesPanel (WEB-1b)', () => {
  it('asks for the list and the diff on mount, lists the rows, shows the whole diff', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wire.sent('files.changed').at(-1)?.params).toEqual({})
    expect(wire.sent('files.diff').at(-1)?.params).toEqual({})
    expect(wrapper.find('[data-testid="changes-count"]').text()).toBe('3 changed')
    expect(wrapper.findAll('[data-testid^="changed-row-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('all files')
    expect(wrapper.find('[data-testid="diff"]').text()).toContain('keep')
  })

  it('narrows the diff to the clicked file and widens back on the second click', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="changed-row-x/y.ts"]').trigger('click')
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('x/y.ts')
    const diff = wrapper.find('[data-testid="diff"]').text()
    expect(diff).toContain('p2')
    expect(diff).not.toContain('keep')
    await wrapper.find('[data-testid="changed-row-x/y.ts"]').trigger('click')
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('all files')
    expect(wrapper.find('[data-testid="diff"]').text()).toContain('keep')
  })

  it('names untracked selections as having no diff instead of showing an empty block', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="changed-row-notes.md"]').trigger('click')
    expect(wrapper.find('[data-testid="changes-untracked"]').text()).toContain('notes.md is untracked')
    expect(wrapper.find('[data-testid="diff"]').exists()).toBe(false)
  })

  it('shows the rename source beside the path', async () => {
    await wire.connect()
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', { items: [{ path: 'b/new.ts', status: 'R', from: 'b/old.ts' }], truncated: false })
    await wire.answer('files.diff', { ref: 'HEAD', diff: DIFF, truncated: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="changed-row-b/new.ts"]').text()).toContain('b/old.ts')
  })

  it('says so when either feed is truncated', async () => {
    await wire.connect()
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', { ...CHANGED, truncated: true })
    await wire.answer('files.diff', { ref: 'HEAD', diff: DIFF, truncated: true })
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-truncated"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="diff-truncated"]').exists()).toBe(true)
  })

  it('fails soft per side: a diff refusal keeps the list and shows a stub; reload tries both again', async () => {
    await wire.connect()
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.fail('files.diff', -32002, 'git_unavailable', 'git is not available here')
    await flushPromises()
    // w4j: Promise.allSettled — the fetched changed-list survives its sibling's refusal.
    expect(wrapper.find('[data-testid="changes-count"]').text()).toBe('3 changed')
    expect(wrapper.findAll('[data-testid^="changed-row-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="changes-diff-error"]').text()).toContain('git is not available here')
    expect(wrapper.find('[data-testid="changes-error"]').exists()).toBe(false)

    await wrapper.find('[data-testid="changes-reload"]').trigger('click')
    await flushPromises()
    expect(wire.sent('files.changed')).toHaveLength(2)
    expect(wire.sent('files.diff')).toHaveLength(2)
  })

  it('does not call a refused diff "untracked": the stub answers the selected row', async () => {
    await wire.connect()
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.fail('files.diff', -32002, 'git_unavailable', 'git is not available here')
    await flushPromises()
    await wrapper.find('[data-testid="changed-row-src/a.ts"]').trigger('click')
    expect(wrapper.find('[data-testid="changes-untracked"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="changes-diff-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="diff"]').exists()).toBe(false)
  })

  it('keeps a selection that survives a reload and drops one that does not', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="changed-row-src/a.ts"]').trigger('click')
    await wrapper.find('[data-testid="changes-reload"]').trigger('click')
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', { ref: 'HEAD', diff: DIFF, truncated: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('src/a.ts')

    await wrapper.find('[data-testid="changes-reload"]').trigger('click')
    await flushPromises()
    await wire.answer('files.changed', { items: [{ path: 'only.ts', status: 'M' }], truncated: false })
    await wire.answer('files.diff', { ref: 'HEAD', diff: DIFF, truncated: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('all files')
  })

  it('mounts in the drawer under a Changes tab', async () => {
    await wire.connect()
    const wrapper = mount(SidePanels, { props: { sessionId: null } })
    await wrapper.find('[data-testid="panels-toggle"]').trigger('click')
    await wrapper.find('[data-testid="panel-tab-changes"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-panel"]').exists()).toBe(true)
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', { ref: 'HEAD', diff: DIFF, truncated: false })
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="changed-row-"]')).toHaveLength(3)
  })
})
