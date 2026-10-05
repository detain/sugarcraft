import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MessageMarkdown from '../../components/MessageMarkdown.vue'
import { highlight, highlightIn, MAX_HIGHLIGHT_CHARS, supports } from '../highlight'

/** The spans of $html as [kind, text] pairs. */
function spans(html: string): [string, string][] {
  const box = document.createElement('div')
  box.innerHTML = html
  return [...box.querySelectorAll('span')].map((span) => [span.className.replace('hl-', ''), span.textContent ?? ''])
}

describe('syntax colouring', () => {
  it('tells comments, strings, numbers and keywords apart in a C-like language', () => {
    const html = highlight('const n = 42 // the answer\nreturn "hi"', 'ts') ?? ''
    expect(spans(html)).toEqual([
      ['keyword', 'const'],
      ['number', '42'],
      ['comment', '// the answer'],
      ['keyword', 'return'],
      ['string', '"hi"'],
    ])
  })

  it('knows the shell, SQL, JSON, markup and diffs', () => {
    expect(spans(highlight('echo "$HOME" # where', 'bash') ?? '')).toEqual([['string', '"$HOME"'], ['comment', '# where']])
    expect(spans(highlight('SELECT id FROM t WHERE x = 1', 'sql') ?? '').map(([kind]) => kind)).toEqual(['keyword', 'keyword', 'keyword', 'number'])
    expect(spans(highlight('{"a": true}', 'json') ?? '')).toEqual([['key', '"a"'], ['literal', 'true']])
    expect(spans(highlight('<a href="x">', 'html') ?? '')).toEqual([['tag', '<a'], ['attr', 'href'], ['string', '"x"'], ['tag', '>']])
    expect(spans(highlight('@@ -1 +1 @@\n-old\n+new', 'diff') ?? '').map(([kind]) => kind)).toEqual(['meta', 'del', 'add'])
  })

  it('escapes everything: source markup never becomes page markup', () => {
    const html = highlight('"<img src=x onerror=alert(1)>" <script>', 'js') ?? ''
    expect(html).not.toContain('<img')
    expect(html).not.toContain('<script')
    expect(html).toContain('&lt;img')
  })

  it('leaves unknown languages and huge blocks alone', () => {
    expect(supports('brainfuck')).toBe(false)
    expect(highlight('+++.', 'brainfuck')).toBeNull()
    expect(highlight('x'.repeat(MAX_HIGHLIGHT_CHARS + 1), 'js')).toBeNull()
  })

  it('colours the fenced blocks under a node, once', () => {
    const box = document.createElement('div')
    box.innerHTML = '<pre><code class="language-py">def f(): pass</code></pre><pre><code>plain</code></pre>'
    expect(highlightIn(box)).toBe(1)
    expect(highlightIn(box)).toBe(0)
    expect(box.querySelector('.hl-keyword')?.textContent).toBe('def')
  })

  it('a reply with a fenced block is coloured once the chunk loads', async () => {
    const wrapper = mount(MessageMarkdown, { props: { text: '```php\n$x = 1;\n```' } })
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
    await flushPromises()
    expect(wrapper.find('.hl-variable').text()).toBe('$x')
    expect(wrapper.find('.hl-number').text()).toBe('1')
  })
})
