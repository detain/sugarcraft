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

describe('PermissionCard AskUser choice buttons (WEB-5a-iii)', () => {
  const askUser = ask({
    tool: 'AskUser',
    source: 'tool:AskUser',
    arguments: { description: 'pick one', question: 'Favorite color?', options: ['Blue', 'Green'] },
    options: ['once', 'always', 'reject'],
  })

  it('offers one button per choice and answers with the exact option string', async () => {
    const wrapper = mount(PermissionCard, { props: { ask: askUser, canAnswer: true } })
    const buttons = wrapper.findAll('[data-testid="ask-choice"]')
    expect(buttons).toHaveLength(2)
    expect(buttons[0]?.text()).toBe('1. Blue (recommended)')
    await buttons[1]?.trigger('click')
    const call = wrapper.emitted('answer')?.[0]
    expect(call?.slice(0, 3)).toEqual(['once', false, 'Green'])
  })

  it('falls back to the free-text note when there are too many choices', () => {
    const many = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']
    const wrapper = mount(PermissionCard, {
      props: {
        ask: ask({ ...askUser, arguments: { question: '?', options: many } }),
        canAnswer: true,
      },
    })
    expect(wrapper.findAll('[data-testid="ask-choice"]')).toHaveLength(0)
  })

  it('ignores embedded option lists on tools that are not AskUser', () => {
    const wrapper = mount(PermissionCard, {
      props: { ask: ask({ arguments: { command: 'ls', options: ['Blue'] } }), canAnswer: true },
    })
    expect(wrapper.findAll('[data-testid="ask-choice"]')).toHaveLength(0)
  })

  it('leaves the verdict buttons untouched alongside the choices', () => {
    const wrapper = mount(PermissionCard, { props: { ask: askUser, canAnswer: true } })
    expect(wrapper.find('[data-testid="ask-once"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="ask-always"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="ask-reject"]').exists()).toBe(true)
  })
})
