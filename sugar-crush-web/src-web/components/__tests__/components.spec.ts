import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { parseUnifiedDiff } from '../../lib/diff'
import { markdownToHtml } from '../../lib/markdown'
import type { ToolItem } from '../../stores/reducer'
import { createState } from '../../stores/reducer'
import Composer from '../Composer.vue'
import DiffView from '../DiffView.vue'
import PermissionCard from '../PermissionCard.vue'
import StatusBar from '../StatusBar.vue'
import ToolCard from '../ToolCard.vue'
import Transcript from '../Transcript.vue'

const DIFF = '--- a/notes.txt\n+++ b/notes.txt\n@@ -1,2 +1,2 @@\n alpha\n-beta\n+gamma\n'

function tool(overrides: Partial<ToolItem> = {}): ToolItem {
  return { kind: 'tool', key: 'k', toolCallId: 'c1', name: 'Bash', arguments: { command: 'git status' }, status: 'done', content: 'clean', ...overrides }
}

describe('ToolCard', () => {
  it('is collapsed when it succeeded and opens on click', async () => {
    const wrapper = mount(ToolCard, { props: { item: tool({ durationMs: 1500 }) } })
    expect(wrapper.attributes('data-status')).toBe('done')
    expect(wrapper.text()).toContain('git status')
    expect(wrapper.text()).toContain('1.5 s')
    expect(wrapper.find('[data-testid="tool-output"]').exists()).toBe(false)
    await wrapper.find('button.head').trigger('click')
    expect(wrapper.find('[data-testid="tool-output"]').text()).toBe('clean')
  })

  it('opens a refusal and names its kind', () => {
    const wrapper = mount(ToolCard, { props: { item: tool({ status: 'denied', denial: { kind: 'UserRejected', reason: 'no thanks' } }) } })
    expect(wrapper.text()).toContain('Refused (UserRejected): no thanks')
  })

  it('shows the diff with its +/− counts', async () => {
    const wrapper = mount(ToolCard, { props: { item: tool({ name: 'Edit', arguments: { file_path: 'notes.txt' }, diff: DIFF }) } })
    expect(wrapper.find('.stats').text()).toBe('+1 −1')
    await wrapper.find('button.head').trigger('click')
    expect(wrapper.find('[data-testid="diff"]').exists()).toBe(true)
  })

  it('offers the full output when the event was truncated', async () => {
    const item = tool({ status: 'error', truncated: true })
    const wrapper = mount(ToolCard, { props: { item } })
    await wrapper.find('button.more').trigger('click')
    expect(wrapper.emitted('loadFull')?.[0]).toEqual([item])
  })
})

describe('DiffView', () => {
  it('numbers old and new lines', () => {
    const rows = parseUnifiedDiff(DIFF)
    expect(rows.map((r) => [r.kind, r.oldNo, r.newNo])).toEqual([
      ['file', null, null],
      ['file', null, null],
      ['hunk', null, null],
      ['ctx', 1, 1],
      ['del', 2, null],
      ['add', null, 2],
    ])
    const wrapper = mount(DiffView, { props: { diff: DIFF } })
    expect(wrapper.findAll('.row.add')).toHaveLength(1)
    expect(wrapper.findAll('.row.del')).toHaveLength(1)
  })
})

describe('PermissionCard', () => {
  const ask = { askId: 'a1', toolCallId: 'c1', tool: 'Bash', arguments: { command: 'git push' }, reason: 'default mode asks', options: ['once', 'always', 'reject'] as ('once' | 'always' | 'reject')[], alwaysScope: { tool: 'Bash' } }

  it('answers once, always, reject, and reject & stop (cascade)', async () => {
    for (const [id, expected] of [['ask-once', ['once', false]], ['ask-always', ['always', false]], ['ask-reject', ['reject', false]], ['ask-reject-stop', ['reject', true]]] as const) {
      const wrapper = mount(PermissionCard, { props: { ask, canAnswer: true } })
      expect(wrapper.text()).toContain('git push')
      await wrapper.find(`[data-testid="${id}"]`).trigger('click')
      expect(wrapper.emitted('answer')?.[0]).toEqual([...expected, ''])
    }
  })

  it('answers with y / n from the keyboard, once', async () => {
    const wrapper = mount(PermissionCard, { props: { ask, canAnswer: true } })
    await wrapper.trigger('keydown', { key: 'n' })
    await wrapper.trigger('keydown', { key: 'y' })
    expect(wrapper.emitted('answer')).toEqual([['reject', false, '']])
  })

  it('sends the note with a rejection, not with an allow', async () => {
    for (const [id, expected] of [['ask-reject', ['reject', false, 'use rg']], ['ask-once', ['once', false, '']]] as const) {
      const wrapper = mount(PermissionCard, { props: { ask, canAnswer: true } })
      await wrapper.find('[data-testid="ask-note"]').setValue('  use rg ')
      await wrapper.find(`[data-testid="${id}"]`).trigger('click')
      expect(wrapper.emitted('answer')?.[0]).toEqual(expected)
    }
  })

  it('sends a typed answer with Allow once on a question the agent asked', async () => {
    const question = { ...ask, tool: 'AskUser', source: 'tool:AskUser', options: ['once', 'reject'] as ('once' | 'always' | 'reject')[], alwaysScope: undefined }
    const wrapper = mount(PermissionCard, { props: { ask: question, canAnswer: true } })
    await wrapper.find('[data-testid="ask-note"]').setValue('teal')
    await wrapper.find('[data-testid="ask-note"]').trigger('keydown', { key: 'n' })
    expect(wrapper.emitted('answer')).toBeUndefined()
    await wrapper.find('[data-testid="ask-once"]').trigger('click')
    expect(wrapper.emitted('answer')?.[0]).toEqual(['once', false, 'teal'])
  })

  it('cannot answer without the approve scope', async () => {
    const wrapper = mount(PermissionCard, { props: { ask, canAnswer: false } })
    expect(wrapper.find('[data-testid="ask-once"]').attributes('disabled')).toBeDefined()
    await wrapper.trigger('keydown', { key: 'y' })
    expect(wrapper.emitted('answer')).toBeUndefined()
  })
})

describe('Composer', () => {
  const commands = [
    { name: 'clear', description: 'Clear the session', source: 'builtin' as const, runsIn: 'server' as const },
    { name: 'theme', source: 'builtin' as const, runsIn: 'client' as const },
    { name: 'review', source: 'file' as const, runsIn: 'server' as const },
  ]

  it('sends on Enter, not on Shift+Enter', async () => {
    const wrapper = mount(Composer, { props: { modelValue: 'hello', busy: false, commands: [] } })
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true })
    expect(wrapper.emitted('send')).toBeUndefined()
    await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('send')?.[0]).toEqual(['hello', 'queue'])
  })

  it('picks queue, steer or interrupt while a turn runs, and stops on Esc Esc', async () => {
    const wrapper = mount(Composer, { props: { modelValue: 'more', busy: true, commands: [] } })
    await wrapper.find('[data-testid="composer-delivery"]').setValue('steer')
    expect(wrapper.find('[data-testid="composer-send"]').text()).toBe('Steer')
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    expect(wrapper.emitted('send')?.[0]).toEqual(['more', 'steer'])
    await wrapper.find('textarea').trigger('keydown', { key: 'Escape' })
    await wrapper.find('textarea').trigger('keydown', { key: 'Escape' })
    expect(wrapper.emitted('stop')).toHaveLength(1)
  })

  it('completes / commands that can run here', async () => {
    const wrapper = mount(Composer, { props: { modelValue: '/', busy: false, commands } })
    const names = wrapper.findAll('[data-testid="command-suggestions"] .cmd').map((n) => n.text())
    expect(names).toEqual(['/clear', '/review'])
    await wrapper.find('textarea').trigger('keydown', { key: 'Tab' })
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['/clear '])
  })
})

describe('StatusBar', () => {
  it('shows the step, context use and mode', () => {
    const state = { ...createState('s1'), status: 'busy' as const, step: 3, maxSteps: 8, contextTokens: 41000, permissionMode: 'plan' as const }
    const wrapper = mount(StatusBar, { props: { state, contextPct: 41, connection: 'connected' } })
    expect(wrapper.text()).toContain('step 3/8')
    expect(wrapper.text()).toContain('~41k tok · 41%')
    expect((wrapper.find('[data-testid="mode-select"]').element as HTMLSelectElement).value).toBe('plan')
  })

  it('asks for a mode change', async () => {
    const wrapper = mount(StatusBar, { props: { state: createState('s1'), contextPct: null, connection: 'connected' } })
    await wrapper.find('[data-testid="mode-select"]').setValue('accept-edits')
    expect(wrapper.emitted('setMode')?.[0]).toEqual(['accept-edits'])
  })
})

describe('Transcript', () => {
  it('renders rows through the virtualiser', () => {
    const items = [
      { kind: 'user' as const, key: 'u', content: 'hello' },
      { kind: 'assistant' as const, key: 'a', content: 'You said:\n\n> hello' },
    ]
    const wrapper = mount(Transcript, { props: { items } })
    const rows = wrapper.findAll('[data-testid="transcript-item"]')
    expect(rows.map((r) => r.attributes('data-kind'))).toEqual(['user', 'assistant'])
    expect(wrapper.find('blockquote').text()).toBe('hello')
  })

  it('keeps only a window of a long transcript in the DOM', () => {
    const items = Array.from({ length: 2000 }, (_, i) => ({ kind: 'user' as const, key: `u${i}`, content: `row ${i}` }))
    const wrapper = mount(Transcript, { props: { items } })
    expect(wrapper.findAll('[data-testid="transcript-item"]').length).toBeLessThan(100)
  })
})

// DOMPurify needs a real DOM (happy-dom's parser mangles its walk), so the
// sanitiser itself is exercised by the e2e suite in Chromium; these pin the
// parser stage, which already refuses raw HTML and script URLs.
describe('markdown', () => {
  it('renders markdown and code blocks', () => {
    const html = markdownToHtml('**bold** and `code`\n\n```php\necho 1;\n```')
    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('<span class="code-lang">php</span>')
  })

  it('never lets markup through: raw HTML is text, and javascript: is no link', () => {
    const html = markdownToHtml('<script>alert(1)</script><img src=x onerror=alert(1)> [x](javascript:alert(1))')
    expect(html).not.toContain('<script')
    expect(html).not.toContain('<img')
    expect(html).not.toMatch(/href="javascript:/)
  })

  it('opens links in a new tab without an opener', () => {
    expect(markdownToHtml('[docs](https://example.com)')).toContain('target="_blank" rel="noopener noreferrer"')
  })
})
