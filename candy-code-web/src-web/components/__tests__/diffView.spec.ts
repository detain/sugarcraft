import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import DiffView from '../DiffView.vue'
import { useSettingsStore } from '../../stores/settings'

const TS_DIFF = '--- a/src/app.ts\n+++ b/src/app.ts\n@@ -1,2 +1,2 @@\n alpha\n-const beta = 1\n+const gamma = 2\n'
const TXT_DIFF = '--- a/notes.txt\n+++ b/notes.txt\n@@ -1,2 +1,2 @@\n alpha\n-beta\n+gamma\n'

function setViewport(width: number): void {
  Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width })
}

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  setViewport(1024)
})

afterEach(() => {
  setActivePinia(undefined)
})

describe('DiffView unified mode stays the default (C-TUI-2.f)', () => {
  it('renders the interleaved stream at a narrow viewport on auto', () => {
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.find('[data-testid="diff"]').classes()).not.toContain('split')
    expect(wrapper.findAll('.row.add')).toHaveLength(1)
    expect(wrapper.findAll('.row.del')).toHaveLength(1)
    expect(wrapper.text()).toContain('beta')
    expect(wrapper.text()).toContain('gamma')
  })

  it('explicit unified keeps the stream even at a wide viewport', async () => {
    useSettingsStore().diffStyle = 'unified'
    setViewport(1600)
    await nextTick()
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.find('[data-testid="diff"]').classes()).not.toContain('split')
  })
})

describe('DiffView split mode (C-TUI-2.f, GAP-UI-05)', () => {
  it('auto splits at 1100 px of viewport width', () => {
    setViewport(1100)
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.find('[data-testid="diff"]').classes()).toContain('split')
  })

  it('a store flip to split widens a narrow viewport, and back', async () => {
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.find('[data-testid="diff"]').classes()).not.toContain('split')
    useSettingsStore().diffStyle = 'split'
    await nextTick()
    expect(wrapper.find('[data-testid="diff"]').classes()).toContain('split')
    useSettingsStore().diffStyle = 'unified'
    await nextTick()
    expect(wrapper.find('[data-testid="diff"]').classes()).not.toContain('split')
  })

  it('a resize across the threshold re-layouts a mounted view', async () => {
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    setViewport(1400)
    window.dispatchEvent(new Event('resize'))
    await nextTick()
    expect(wrapper.find('[data-testid="diff"]').classes()).toContain('split')
    setViewport(900)
    window.dispatchEvent(new Event('resize'))
    await nextTick()
    expect(wrapper.find('[data-testid="diff"]').classes()).not.toContain('split')
  })

  it('pairs the replacement onto one row with its own numbers per half', () => {
    setViewport(1400)
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    const pairRows = wrapper.findAll('.row.pair').filter((row) => row.classes().includes('pair') && !row.classes().includes('spanned'))
    const changed = pairRows.find((row) => row.find('.half.del').exists() && row.find('.half.add').exists())
    expect(changed).toBeDefined()
    expect(changed?.find('.half.del').text()).toBe('beta')
    expect(changed?.find('.half.add').text()).toBe('gamma')
    const numbers = changed?.findAll('.no').map((cell) => cell.text())
    expect(numbers).toEqual(['2', '2'])
  })

  it('a lone addition leaves the left half empty-marked, not dropped', () => {
    setViewport(1400)
    const wrapper = mount(DiffView, { props: { diff: '--- a/f.txt\n+++ b/f.txt\n@@ -1,1 +1,2 @@\n alpha\n+extra\n' } })
    const lone = wrapper.findAll('.row.pair').find((row) => row.find('.half.add').exists())
    expect(lone?.find('.half.empty').exists()).toBe(true)
    expect(wrapper.text()).toContain('alpha')
  })

  it('headers ride full width as spanned rows', () => {
    setViewport(1400)
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    const hunk = wrapper.findAll('.row.spanned').find((row) => row.classes().includes('hunk'))
    expect(hunk?.text()).toBe('@@ -1,2 +1,2 @@')
    expect(wrapper.findAll('.row.spanned.file')).toHaveLength(2)
  })

  it('shows the same texts unified and split — the layout never loses a row', () => {
    const wide = mount(DiffView, { props: { diff: TS_DIFF } })
    setViewport(1400)
    const splitView = mount(DiffView, { props: { diff: TS_DIFF } })
    expect(splitView.text()).toContain('const')
    expect(splitView.text()).toContain('beta')
    expect(splitView.text()).toContain('gamma')
    expect(splitView.text().length).toBeGreaterThanOrEqual(wide.text().length - 10)
  })
})

describe('diffStyle persists through the settings store (client pref)', () => {
  it('writes localStorage under the shared per-viewer key', async () => {
    useSettingsStore().diffStyle = 'split'
    await nextTick()
    expect(localStorage.getItem('candy-code-web:diffStyle')).toBe('"split"')
  })

  it('a fresh store restores it, and cycling walks auto → unified → split → auto', () => {
    localStorage.setItem('candy-code-web:diffStyle', '"unified"')
    setActivePinia(createPinia())
    const store = useSettingsStore()
    expect(store.diffStyle).toBe('unified')
    store.cycleDiffStyle()
    expect(store.diffStyle).toBe('split')
    store.cycleDiffStyle()
    expect(store.diffStyle).toBe('auto')
  })

  it('junk in storage still renders (resolved to unified) and cycles back to auto', () => {
    localStorage.setItem('candy-code-web:diffStyle', '"columns"')
    setActivePinia(createPinia())
    setViewport(1600)
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.find('[data-testid="diff"]').classes()).not.toContain('split')
    useSettingsStore().cycleDiffStyle()
    expect(useSettingsStore().diffStyle).toBe('auto')
  })
})

describe('DiffView highlights diff bodies by file extension (C-TUI-2.f, GAP-UI-04)', () => {
  it('tints a .ts body token while the gutter stays plain', () => {
    const wrapper = mount(DiffView, { props: { diff: TS_DIFF } })
    expect(wrapper.findAll('.hl-keyword').map((span) => span.text())).toContain('const')
    expect(wrapper.findAll('.row.del .hl-keyword').length).toBe(1)
  })

  it('leaves a language-less path plain', () => {
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.findAll('[class*="hl-"]')).toHaveLength(0)
  })

  it('escapes hostile diff text into text, never elements', () => {
    const hostile = '--- a/src/evil.ts\n+++ b/src/evil.ts\n@@ -1,1 +1,1 @@\n-<img src=x onerror=alert(1)>\n+<script>steal()</script>\n'
    const wrapper = mount(DiffView, { props: { diff: hostile } })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>steal()</script>')
  })

  it('split halves are coloured too', () => {
    setViewport(1400)
    const wrapper = mount(DiffView, { props: { diff: TS_DIFF } })
    expect(wrapper.findAll('.half .hl-keyword').length).toBe(2)
  })

  it('the language tracks a rename header to the new side', () => {
    const diff = 'diff --git a/old.py b/new.py\n--- a/old.py\n+++ b/new.py\n@@ -1,1 +1,1 @@\n-def f(): pass\n+def g(): pass\n'
    const wrapper = mount(DiffView, { props: { diff } })
    expect(wrapper.findAll('.hl-keyword').map((span) => span.text())).toContain('def')
  })

  it('mounts without an active pinia by falling back to the stored pref', () => {
    setActivePinia(undefined)
    localStorage.setItem('candy-code-web:diffStyle', '"split"')
    setViewport(800)
    const wrapper = mount(DiffView, { props: { diff: TXT_DIFF } })
    expect(wrapper.find('[data-testid="diff"]').classes()).toContain('split')
    setActivePinia(createPinia())
  })
})
