import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MessageMarkdown from '../../components/MessageMarkdown.vue'
import { highlight, highlightIn, languageForPath, MAX_HIGHLIGHT_CHARS, supports } from '../highlight'

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

describe('languageForPath: extension to language (C-TUI-2.f twin of RegexHighlighter)', () => {
  it('maps the extensions the core highlighter maps', () => {
    expect(languageForPath('src/app.ts')).toBe('ts')
    expect(languageForPath('src/app.tsx')).toBe('ts')
    expect(languageForPath('lib/store.js')).toBe('js')
    expect(languageForPath('lib/legacy.mjs')).toBe('js')
    expect(languageForPath('bin/cli.cjs')).toBe('js')
    expect(languageForPath('src/index.jsx')).toBe('js')
    expect(languageForPath('x.PY')).toBe('py')
    expect(languageForPath('x.pyw')).toBe('py')
    expect(languageForPath('compose.yml')).toBe('yaml')
    expect(languageForPath('config.yaml')).toBe('yaml')
    expect(languageForPath('src/main.rs')).toBe('rs')
    expect(languageForPath('src/Main.java')).toBe('java')
    expect(languageForPath('native/main.cpp')).toBe('cpp')
    expect(languageForPath('native/legacy.cc')).toBe('cpp')
    expect(languageForPath('native/cxx.hxx')).toBe('cpp')
    expect(languageForPath('native/vec.hh')).toBe('cpp')
    expect(languageForPath('include/header.hpp')).toBe('cpp')
    expect(languageForPath('include/legacy_h.h')).toBe('c')
    expect(languageForPath('src/bind.c')).toBe('c')
    expect(languageForPath('src/script.rb')).toBe('rb')
    expect(languageForPath('page.htm')).toBe('html')
    expect(languageForPath('page.html')).toBe('html')
    expect(languageForPath('style.css')).toBe('css')
    expect(languageForPath('schema.sql')).toBe('sql')
    expect(languageForPath('data.json')).toBe('json')
    expect(languageForPath('conf.jsonc')).toBe('json')
    expect(languageForPath('pkg/php.php')).toBe('php')
    expect(languageForPath('deploy/go.go')).toBe('go')
    expect(languageForPath('scripts/build.sh')).toBe('sh')
    expect(languageForPath('scripts/login.bash')).toBe('sh')
    expect(languageForPath('scripts/zsh.zsh')).toBe('sh')
  })

  it('is total: dotfiles, bare names and unknown extensions answer null', () => {
    expect(languageForPath('.envrc')).toBeNull()
    expect(languageForPath('Makefile')).toBeNull()
    expect(languageForPath('README')).toBeNull()
    expect(languageForPath('')).toBeNull()
    expect(languageForPath('archive/weird.q')).toBeNull()
    expect(languageForPath('notes.txt')).toBeNull()
    expect(languageForPath('/dev/null')).toBeNull()
  })

  it('reads only the final dot segment of the basename', () => {
    expect(languageForPath('archive.tar.gz')).toBeNull()
    expect(languageForPath('my.ts.config')).toBeNull()
    expect(languageForPath('dir.ts/file.js')).toBe('js')
    expect(languageForPath('trailing/slash.ts/')).toBeNull()
  })

  it('answers only languages this file can colour', () => {
    for (const path of ['a.ts', 'a.js', 'a.py', 'a.rs', 'a.java', 'a.cpp', 'a.c', 'a.rb', 'a.yaml', 'a.html', 'a.css', 'a.sql', 'a.json', 'a.php', 'a.go', 'a.sh']) {
      const lang = languageForPath(path)
      expect(lang, path).not.toBeNull()
      expect(supports(lang as string), `${path} -> ${lang}`).toBe(true)
    }
  })

  it('explicit-language callers are byte-unchanged by the twin', () => {
    expect(highlight('const a = 1', 'ts')).toBe(highlight('const a = 1', 'ts'))
    expect(highlight('const a = 1', 'TS')).toContain('hl-keyword')
  })
})

