import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PermissionCard from '../PermissionCard.vue'
import type { PendingAsk } from '../../protocol/generated'

/**
 * C-SEC-2 (WEB-5-P1): a real batched Edit carries the top-level pair AND an
 * `edits` list; editList() runs every one of them. Before the fix the card
 * previewed only the top pair and hid the raw arguments behind a `v-else`, so
 * an approver could click Allow for `x = 1 → x = 2` while the call also swapped
 * `safe()` for `exfiltrate()`. The scenario is this suite's fixture.
 */
function batchedEdit(): PendingAsk {
  return {
    askId: 'a1',
    toolCallId: 'c1',
    tool: 'Edit',
    arguments: {
      file_path: 'src/a.ts',
      old_string: 'x = 1\n',
      new_string: 'x = 2\n',
      edits: [{ old_string: 'safe()\n', new_string: 'exfiltrate()\n' }],
    },
    reason: 'default mode asks',
    options: ['once', 'reject'],
  } as PendingAsk
}

describe('PermissionCard shows every edit and the raw arguments (C-SEC-2)', () => {
  it('previews the top-level pair and every batched entry, not just the first', () => {
    const wrapper = mount(PermissionCard, { props: { ask: batchedEdit(), canAnswer: true } })
    const diff = wrapper.find('[data-testid="ask-diff"]')
    expect(diff.exists()).toBe(true)
    expect(diff.text()).toContain('x = 1')
    expect(diff.text()).toContain('x = 2')
    expect(diff.text()).toContain('safe()')
    expect(diff.text()).toContain('exfiltrate()')
    expect(diff.find('summary').text()).toContain('src/a.ts')
    expect(diff.find('summary').text()).toContain('2 edits')
  })

  it('renders the raw arguments details beside the ask-diff preview', () => {
    const wrapper = mount(PermissionCard, { props: { ask: batchedEdit(), canAnswer: true } })
    expect(wrapper.find('[data-testid="ask-diff"]').exists()).toBe(true)
    const raw = wrapper.find('[data-testid="ask-arguments"]')
    expect(raw.exists()).toBe(true)
    expect(raw.find('summary').text()).toBe('arguments')
    expect(raw.find('pre').text()).toContain('exfiltrate()')
  })

  it('keeps the raw arguments details when there is no diff preview at all', () => {
    const wrapper = mount(PermissionCard, {
      props: {
        ask: { ...batchedEdit(), tool: 'Bash', arguments: { command: 'git push' } } as PendingAsk,
        canAnswer: true,
      },
    })
    expect(wrapper.find('[data-testid="ask-diff"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="ask-arguments"] pre').text()).toContain('git push')
  })
})

describe('PermissionCard prefers the server-computed diff (C-SEC-2.b)', () => {
  it('renders the ask.diff text with its real counts, not the snippet-relative mint', () => {
    const diff = '--- a/src/a.ts\n+++ b/src/a.ts\n@@ -104,7 +104,7 @@\n l104\n-x = 1\n+x = 2\n safe()\n+exfiltrate()\n'
    const wrapper = mount(PermissionCard, {
      props: { ask: { ...batchedEdit(), diff } as PendingAsk, canAnswer: true },
    })
    const box = wrapper.find('[data-testid="ask-diff"]')
    expect(box.exists()).toBe(true)
    // File-absolute hunk grammar, and the size read off the text itself:
    // two added, one removed — which the client mint of this payload cannot
    // honestly say.
    expect(box.find('summary').text()).toContain('server diff')
    expect(box.find('summary').text()).toContain('(+2 −1)')
    expect(box.find('summary').text()).not.toContain('snippet-relative')
    expect(box.text()).toContain('@@ -104,7 +104,7 @@')
  })

  it('falls back to the client preview when no server diff arrived', () => {
    const wrapper = mount(PermissionCard, {
      props: { ask: { ...batchedEdit(), diff: '' } as PendingAsk, canAnswer: true },
    })
    expect(wrapper.find('[data-testid="ask-diff"] summary').text()).toContain('snippet-relative')
    const absent = mount(PermissionCard, { props: { ask: batchedEdit(), canAnswer: true } })
    expect(absent.find('[data-testid="ask-diff"] summary').text()).toContain('snippet-relative')
  })

  it('still keeps the raw arguments beside the server diff', () => {
    const diff = '--- a/src/a.ts\n+++ b/src/a.ts\n@@ -1,1 +1,1 @@\n-x = 1\n+x = 2\n'
    const wrapper = mount(PermissionCard, {
      props: { ask: { ...batchedEdit(), diff } as PendingAsk, canAnswer: true },
    })
    expect(wrapper.find('[data-testid="ask-diff"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="ask-arguments"] pre').text()).toContain('exfiltrate()')
  })
})
