import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PermissionCard from '../PermissionCard.vue'
import type { PendingAsk } from '../../protocol/generated'

function ask(over: Partial<PendingAsk>): PendingAsk {
  return {
    askId: 'a1',
    toolCallId: 'c1',
    tool: 'Bash',
    arguments: { command: 'git push' },
    reason: 'default mode asks',
    options: ['once', 'reject'],
    ...over,
  } as PendingAsk
}

describe('PermissionCard diff preview (WEB-5a-ii)', () => {
  it('renders an Edit ask as a collapsed unified diff with a stat header', () => {
    const wrapper = mount(PermissionCard, {
      props: {
        ask: ask({
          tool: 'Edit',
          arguments: { file_path: 'src/a.ts', old_string: 'alpha\nbeta\ngamma\n', new_string: 'alpha\nBETA\ngamma\n' },
        }),
        canAnswer: true,
      },
    })
    const box = wrapper.find('[data-testid="ask-diff"]')
    expect(box.exists()).toBe(true)
    expect(box.find('summary').text()).toContain('src/a.ts')
    expect(box.find('summary').text()).toContain('+1')
    expect(box.find('.row.add .text').text()).toBe('BETA')
    expect(box.find('.row.del .text').text()).toBe('beta')
    // The diff replaces the raw JSON dump for recognisable payloads.
    expect(wrapper.find('details:not([data-testid="ask-diff"])').exists()).toBe(false)
  })

  it('renders a Write ask as a new-file preview', () => {
    const wrapper = mount(PermissionCard, {
      props: { ask: ask({ tool: 'Write', arguments: { file_path: 'notes.md', content: '# Title\nbody\n' } }), canAnswer: true },
    })
    expect(wrapper.find('[data-testid="ask-diff"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="ask-diff"] summary').text()).toContain('new file')
  })

  it('keeps the raw arguments fallback for non-diff payloads', () => {
    const wrapper = mount(PermissionCard, { props: { ask: ask({}), canAnswer: true } })
    expect(wrapper.find('[data-testid="ask-diff"]').exists()).toBe(false)
    expect(wrapper.find('pre').text()).toContain('git push')
  })
})
