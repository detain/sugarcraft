import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MessageMarkdown from '../MessageMarkdown.vue'

/**
 * C-TUI-3.b: per-code-block copy buttons on rendered markdown.
 *
 * The buttons are injected as real DOM after the sanitised `v-html` paint
 * (DOMPurify's FORBID_TAGS drops any `button` that rode the HTML, by
 * design), they appear once per block and survive a re-render without
 * duplicating, the click copies that block's code without the fence's
 * trailing newline, and outside a secure context no button is injected at
 * all. Styling cannot be computed by happy-dom, so the placement rule is
 * pinned from the component's own source (the house rule for visual
 * contracts).
 */

let secure = true
const clip = vi.fn()

beforeEach(() => {
  secure = true
  Object.defineProperty(window, 'isSecureContext', {
    get: () => secure,
    configurable: true,
  })
  Object.defineProperty(navigator, 'clipboard', { value: { writeText: clip }, configurable: true })
  // The component chains `.then` on the write, so the default stand-in must
  // hand back a promise.
  clip.mockResolvedValue(undefined)
})

afterEach(() => {
  clip.mockReset()
})

function flushMicrotasks(): Promise<void> {
  return new Promise((resolve) => { queueMicrotask(resolve) })
}

const ONE = '```bash\necho hi\n```'
const TWO = 'First:\n\n```js\nconst a = 1;\n```\n\nSecond:\n\n```\nbare block\n```\n'

describe('code-block copy buttons', () => {
  it('a fenced block gets exactly one copy button', async () => {
    const view = mount(MessageMarkdown, { props: { text: ONE } })
    const buttons = view.findAll('[data-testid="copy-code-block"]')
    expect(buttons).toHaveLength(1)
    // `?.` under noUncheckedIndexedAccess: an absent button answers
    // undefined, and every assertion below still fails on undefined —
    // identical failure power, no type error.
    expect(buttons[0]?.classes()).toContain('code-copy')
    expect(buttons[0]?.text()).toBe('copy')
  })

  it('two blocks get one button each, and each copies exactly its own code', async () => {
    const view = mount(MessageMarkdown, { props: { text: TWO } })
    // Key on `pre`, not the wrapper: under happy-dom a fragment that STARTS
    // with the .code-block div loses the div to sanitiser round-tripping
    // (documented in MessageMarkdown.decorate), so only pre counts are
    // environment-stable. Buttons anchor to the wrapper when it survives.
    expect(view.findAll('pre')).toHaveLength(2)
    const buttons = view.findAll('[data-testid="copy-code-block"]')
    expect(buttons).toHaveLength(2)

    await buttons[1]?.trigger('click')
    await flushMicrotasks()
    expect(clip).toHaveBeenCalledTimes(1)
    expect(clip).toHaveBeenCalledWith('bare block')

    await buttons[0]?.trigger('click')
    await flushMicrotasks()
    expect(clip).toHaveBeenCalledTimes(2)
    expect(clip).toHaveBeenLastCalledWith('const a = 1;')
    // Each button remembers only its own success: labels move one at a time.
    expect(buttons[0]?.text()).toBe('copied')
    expect(buttons[1]?.text()).toBe('copied')
  })

  it('a re-render re-decorates without duplicating buttons', async () => {
    const view = mount(MessageMarkdown, { props: { text: ONE } })
    expect(view.findAll('[data-testid="copy-code-block"]')).toHaveLength(1)
    await view.setProps({ text: `${ONE}\n\nand another:\n\n${TWO}` })
    expect(view.findAll('pre')).toHaveLength(3)
    expect(view.findAll('[data-testid="copy-code-block"]')).toHaveLength(3)
    await view.setProps({ text: ONE })
    expect(view.findAll('[data-testid="copy-code-block"]')).toHaveLength(1)
  })

  it('the click reads the code on screen, not the paint-time snapshot', async () => {
    const view = mount(MessageMarkdown, { props: { text: ONE } })
    const code = view.element.querySelector('pre > code')
    expect(code).not.toBeNull()
    code!.textContent = 'echo changed\n'
    await view.get('[data-testid="copy-code-block"]').trigger('click')
    await flushMicrotasks()
    expect(clip).toHaveBeenCalledWith('echo changed')
  })

  it('outside a secure context no button is injected', () => {
    secure = false
    const view = mount(MessageMarkdown, { props: { text: TWO } })
    expect(view.findAll('pre')).toHaveLength(2)
    expect(view.findAll('[data-testid="copy-code-block"]')).toHaveLength(0)
  })

  it('a refused clipboard says so on the button instead of lying', async () => {
    clip.mockRejectedValueOnce(new Error('NotAllowedError'))
    const view = mount(MessageMarkdown, { props: { text: ONE } })
    await view.get('[data-testid="copy-code-block"]').trigger('click')
    await flushMicrotasks()
    expect(view.get('[data-testid="copy-code-block"]').text()).toBe("couldn't copy")
  })

  it('the sanitiser keeps hostile markup from minting buttons of its own', () => {
    // Negative control for the whole design: a reply that tries to ship
    // its own <button> through markdown lands none — every button in the
    // tree is one this component injected next to a real code block.
    const view = mount(MessageMarkdown, { props: { text: 'Attack: <button onclick="steal()">click</button> and [x](javascript:1)' } })
    expect(view.findAll('button')).toHaveLength(0)
  })

  it('placement styling lives in the component source (happy-dom computes none)', () => {
    // vitest runs from the package root (the generated.spec.ts source-scan pattern).
    const source = readFileSync(resolve(process.cwd(), 'src-web/components/MessageMarkdown.vue'), 'utf8')
    // The rule must be a :deep selector — scoped CSS cannot reach the
    // injected DOM nodes any other way — and must position inside .code-block.
    expect(source).toMatch(/\.markdown :deep\(\.code-copy\)\s*\{[^}]*position:\s*absolute/)
    expect(source).toMatch(/\.markdown :deep\(\.code-block\)\s*\{[^}]*position:\s*relative/)
  })
})
