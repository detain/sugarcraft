import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { usePanelsStore } from '../../../stores/agents/panels'
import SidePanels from '../SidePanels.vue'
import ChangesPanel from '../ChangesPanel.vue'

/**
 * WEB-1b: the Changes tab — `files.changed` for the list and `files.diff`
 * for the text, with the per-file view carved out of the whole-workspace
 * diff on the client (the wire takes a revision, not a path).
 *
 * C-TUI-2.g adds the base selector: with a session in view the panel names
 * it to `files.diff`, and the answer's `checkpoints` count fills the same
 * newest-first choice `/diff n` offers on the terminal. Without a session
 * the panel asks exactly what it always did — `{}` — and shows no selector.
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

/** The C-TUI-2.g result shape: every `files.diff` answer carries the base count and the bound. */
function headDiff(over: Record<string, unknown> = {}) {
  return { ref: 'HEAD', diff: DIFF, truncated: false, checkpoints: 0, omittedLines: 0, ...over }
}

const CHECKPOINT_DIFF = [
  'diff --git a/file.txt b/file.txt',
  'index 1111111..2222222 100644',
  '--- a/file.txt',
  '+++ b/file.txt',
  '@@ -1 +1 @@',
  '-two',
  '+three',
].join('\n')

async function openPanel(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(ChangesPanel)
  await flushPromises()
  await wire.answer('files.changed', CHANGED)
  await wire.answer('files.diff', headDiff())
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
    await wire.answer('files.diff', headDiff())
    await flushPromises()
    expect(wrapper.find('[data-testid="changed-row-b/new.ts"]').text()).toContain('b/old.ts')
  })

  it('says so when either feed is truncated', async () => {
    await wire.connect()
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', { ...CHANGED, truncated: true })
    await wire.answer('files.diff', headDiff({ truncated: true }))
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
    await wire.answer('files.diff', headDiff())
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('src/a.ts')

    await wrapper.find('[data-testid="changes-reload"]').trigger('click')
    await flushPromises()
    await wire.answer('files.changed', { items: [{ path: 'only.ts', status: 'M' }], truncated: false })
    await wire.answer('files.diff', headDiff())
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-selected"]').text()).toBe('all files')
  })

  it('opens the shared viewer for that path from a row\'s "view" (C-WEB-3.b)', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    await wrapper.find('[data-testid="changed-view-src/a.ts"]').trigger('click')
    expect(wrapper.find('[data-testid="file-viewer"]').exists()).toBe(true)
    expect(wire.sent('files.read').at(-1)?.params).toEqual({ path: 'src/a.ts', offset: 0, limit: 32_768 })
    await wire.answer('files.read', { path: 'src/a.ts', size: 6, offset: 0, binary: false, content: 'keep\nnew', truncated: false })
    expect(wrapper.find('[data-testid="file-viewer-path"]').text()).toBe('src/a.ts')
    expect(wrapper.find('[data-testid="file-viewer-line-1"]').text()).toBe('1keep')
    await wrapper.find('[data-testid="file-viewer-close"]').trigger('click')
    expect(wrapper.find('[data-testid="file-viewer"]').exists()).toBe(false)
  })

  it('mounts in the drawer under a Changes tab', async () => {
    await wire.connect()
    const wrapper = mount(SidePanels, { props: { sessionId: null } })
    await wrapper.find('[data-testid="panels-toggle"]').trigger('click')
    await wrapper.find('[data-testid="panel-tab-changes"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-panel"]').exists()).toBe(true)
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', headDiff())
    await flushPromises()
    expect(wrapper.findAll('[data-testid^="changed-row-"]')).toHaveLength(3)
  })
})

describe('ChangesPanel base selector (C-TUI-2.g)', () => {
  function viewing(sessionId: string) {
    usePanelsStore().sessionId = sessionId
  }

  it('names the session to files.diff and offers the bases the answer counts', async () => {
    await wire.connect()
    viewing('s-1')
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    expect(wire.sent('files.diff').at(-1)?.params).toEqual({ sessionId: 's-1' })
    expect(wire.sent('files.changed').at(-1)?.params).toEqual({}) // the changed-list never takes a base
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', headDiff({ checkpoints: 2 }))
    await flushPromises()
    const select = wrapper.find<HTMLSelectElement>('[data-testid="changes-base"]')
    expect(select.exists()).toBe(true)
    const options = select.findAll('option').map((option) => option.text())
    expect(options).toEqual(['HEAD', '1 turn ago', '2 turns ago'])
    expect(select.element.value).toBe('0')
  })

  it('choosing a base re-fetches against it and shows which ref answered', async () => {
    await wire.connect()
    viewing('s-1')
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', headDiff({ checkpoints: 2 }))
    await flushPromises()

    await wrapper.find('[data-testid="changes-base"]').setValue('2')
    await flushPromises()
    expect(wire.sent('files.diff').at(-1)?.params).toEqual({ sessionId: 's-1', checkpoint: 2 })
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', { ref: 'checkpoint:11', diff: CHECKPOINT_DIFF, truncated: false, checkpoints: 2, omittedLines: 0 })
    await flushPromises()

    const diff = wrapper.find('[data-testid="diff"]').text()
    expect(diff).toContain('three')
    expect(diff).not.toContain('keep') // the checkpoint diff replaced the HEAD text — no mixing of bases
    expect(wrapper.find('[data-testid="changes-diff-ref"]').text()).toBe('against checkpoint:11')
  })

  it('degrades to HEAD alone when the session has no bases', async () => {
    await wire.connect()
    viewing('s-1')
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', headDiff({ checkpoints: 0 }))
    await flushPromises()
    const select = wrapper.find('[data-testid="changes-base"]')
    expect(select.findAll('option').map((option) => option.text())).toEqual(['HEAD'])
  })

  it('shows no selector and sends the old bare request without a session', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wrapper.find('[data-testid="changes-base"]').exists()).toBe(false)
    expect(wire.sent('files.diff').at(-1)?.params).toEqual({})
  })

  it('a refused checkpoint is a stub in the panel, never a silent HEAD diff', async () => {
    await wire.connect()
    viewing('s-1')
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', headDiff({ checkpoints: 2 }))
    await flushPromises()

    await wrapper.find('[data-testid="changes-base"]').setValue('1')
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.fail('files.diff', -32009, 'no_checkpoints', 'this session has no workspace checkpoints')
    await flushPromises()
    expect(wrapper.find('[data-testid="changes-diff-error"]').text()).toContain('no workspace checkpoints')
    expect(wrapper.find('[data-testid="diff"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="changes-count"]').text()).toBe('3 changed') // the list survives its sibling refusal
  })

  it('switching sessions drops back to HEAD and re-asks', async () => {
    await wire.connect()
    viewing('s-1')
    const wrapper = mount(ChangesPanel)
    await flushPromises()
    await wire.answer('files.changed', CHANGED)
    await wire.answer('files.diff', headDiff({ checkpoints: 2 }))
    await flushPromises()
    await wrapper.find('[data-testid="changes-base"]').setValue('2')
    await flushPromises()
    await wire.answer('files.diff', { ref: 'checkpoint:11', diff: CHECKPOINT_DIFF, truncated: false, checkpoints: 2, omittedLines: 0 })
    await flushPromises()

    usePanelsStore().sessionId = 's-2'
    await nextTick()
    await flushPromises()
    expect(wire.sent('files.diff').at(-1)?.params).toEqual({ sessionId: 's-2' }) // a new session starts on HEAD, not the old base
    expect(wrapper.find<HTMLSelectElement>('[data-testid="changes-base"]').element.value).toBe('0')
  })
})
