/**
 * Syntax colouring for fenced code blocks, loaded on demand (a dynamic
 * `import()` from MessageMarkdown — its own chunk, fetched the first time a
 * reply holds a fenced block with a language). Deliberately small and
 * dependency-free: a handful of language families, each a list of token
 * rules tried in order, enough to tell comments, strings, numbers, keywords
 * and tags apart. Never a parser.
 *
 * SAFE BY CONSTRUCTION: it reads the block's text (never its HTML) and writes
 * escaped text inside `<span class="hl-…">` — no markup from the source can
 * survive into the page.
 */

type Kind = 'comment' | 'string' | 'number' | 'keyword' | 'literal' | 'variable' | 'tag' | 'attr' | 'meta' | 'add' | 'del' | 'key'

interface Rule {
  kind: Kind
  pattern: RegExp
}

interface Language {
  rules: Rule[]
}

/** Past this, a block is left plain: colouring is a nicety, never a stall. */
export const MAX_HIGHLIGHT_CHARS = 20_000

const words = (list: string): RegExp => new RegExp(`\\b(?:${list.trim().split(/\s+/).join('|')})\\b`)

const DQ = /"(?:[^"\\\n]|\\.)*"?/
const SQ = /'(?:[^'\\\n]|\\.)*'?/
const BT = /`(?:[^`\\]|\\.)*`?/
const NUMBER = /\b(?:0x[\da-fA-F_]+|0b[01_]+|\d[\d_]*(?:\.\d[\d_]*)?(?:[eE][+-]?\d+)?)\b/
const LINE_SLASH = /\/\/[^\n]*/
const BLOCK = /\/\*[\s\S]*?(?:\*\/|$)/
const LINE_HASH = /#[^\n]*/

const C_LITERALS = words('true false null undefined nil None True False NaN Infinity this self super')

const C_LIKE: Language = {
  rules: [
    { kind: 'comment', pattern: BLOCK },
    { kind: 'comment', pattern: LINE_SLASH },
    { kind: 'string', pattern: DQ },
    { kind: 'string', pattern: SQ },
    { kind: 'string', pattern: BT },
    { kind: 'variable', pattern: /\$[A-Za-z_]\w*/ },
    { kind: 'meta', pattern: /^\s*#\s*\w+[^\n]*/m },
    { kind: 'meta', pattern: /@[A-Za-z_]\w*/ },
    { kind: 'literal', pattern: C_LITERALS },
    {
      kind: 'keyword',
      pattern: words(`
        abstract as async await break case catch class const continue declare default defer delete do else enum
        export extends final finally fn for foreach from func function go goto if impl implements import in
        include instanceof interface let loop match mod module mut namespace new package private protected
        public pub readonly return static struct switch throw throws trait try type typeof use using var
        void volatile where while with yield echo require require_once elseif endif endforeach unset isset
        int float double char bool boolean string long short byte unsigned signed auto override virtual
      `),
    },
    { kind: 'number', pattern: NUMBER },
  ],
}

const HASH: Language = {
  rules: [
    { kind: 'comment', pattern: LINE_HASH },
    { kind: 'string', pattern: /"""[\s\S]*?(?:"""|$)|'''[\s\S]*?(?:'''|$)/ },
    { kind: 'string', pattern: DQ },
    { kind: 'string', pattern: SQ },
    { kind: 'variable', pattern: /\$\{?[A-Za-z_]\w*\}?|\$[0-9@#?*!$-]/ },
    { kind: 'meta', pattern: /@[A-Za-z_][\w.]*/ },
    { kind: 'literal', pattern: words('true false null None True False nil yes no on off') },
    {
      kind: 'keyword',
      pattern: words(`
        and as assert async await break case class continue def del do done elif else end esac except exec
        export fi finally for from function global if import in is lambda local module nonlocal not or pass
        raise readonly rescue return select then until unless when while with yield begin ensure require
        source alias set unset shift trap eval
      `),
    },
    { kind: 'number', pattern: NUMBER },
  ],
}

const SQL: Language = {
  rules: [
    { kind: 'comment', pattern: /--[^\n]*/ },
    { kind: 'comment', pattern: BLOCK },
    { kind: 'string', pattern: SQ },
    { kind: 'string', pattern: DQ },
    { kind: 'literal', pattern: /\b(?:null|true|false)\b/i },
    {
      kind: 'keyword',
      pattern: new RegExp(`\\b(?:${[
        'select', 'from', 'where', 'and', 'or', 'not', 'insert', 'into', 'values', 'update', 'set', 'delete',
        'create', 'table', 'index', 'view', 'drop', 'alter', 'add', 'column', 'primary', 'key', 'foreign',
        'references', 'join', 'left', 'right', 'inner', 'outer', 'on', 'as', 'group', 'by', 'order', 'having',
        'limit', 'offset', 'distinct', 'union', 'all', 'case', 'when', 'then', 'else', 'end', 'in', 'is',
        'like', 'between', 'exists', 'default', 'unique', 'constraint', 'begin', 'commit', 'rollback',
        'with', 'returning', 'asc', 'desc', 'integer', 'text', 'varchar', 'int', 'if',
      ].join('|')})\\b`, 'i'),
    },
    { kind: 'number', pattern: NUMBER },
  ],
}

const MARKUP: Language = {
  rules: [
    { kind: 'comment', pattern: /<!--[\s\S]*?(?:-->|$)/ },
    { kind: 'meta', pattern: /<![^>]*>|<\?[\s\S]*?(?:\?>|$)/ },
    { kind: 'tag', pattern: /<\/?[A-Za-z][\w:.-]*|\/?>/ },
    { kind: 'attr', pattern: /\b[A-Za-z_:@#.-][\w:.-]*(?==)/ },
    { kind: 'string', pattern: DQ },
    { kind: 'string', pattern: SQ },
  ],
}

const JSON_LANG: Language = {
  rules: [
    { kind: 'key', pattern: /"(?:[^"\\\n]|\\.)*"(?=\s*:)/ },
    { kind: 'string', pattern: DQ },
    { kind: 'literal', pattern: words('true false null') },
    { kind: 'number', pattern: /-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/ },
  ],
}

const CSS: Language = {
  rules: [
    { kind: 'comment', pattern: BLOCK },
    { kind: 'string', pattern: DQ },
    { kind: 'string', pattern: SQ },
    { kind: 'meta', pattern: /@[\w-]+/ },
    { kind: 'variable', pattern: /--[\w-]+|\$[\w-]+/ },
    { kind: 'attr', pattern: /[\w-]+(?=\s*:(?!:))/ },
    { kind: 'number', pattern: /-?\b\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw|s|ms|fr|deg)?\b|#[\da-fA-F]{3,8}\b/ },
  ],
}

const DIFF: Language = {
  rules: [
    { kind: 'meta', pattern: /^(?:@@[^\n]*|diff [^\n]*|index [^\n]*|--- [^\n]*|\+\+\+ [^\n]*)/m },
    { kind: 'add', pattern: /^\+[^\n]*/m },
    { kind: 'del', pattern: /^-[^\n]*/m },
  ],
}

const ALIASES: Record<string, Language> = {}
for (const [language, names] of [
  [C_LIKE, 'js javascript mjs cjs jsx ts typescript tsx java c h cpp cc cxx hpp c++ cs csharp go golang rust rs php kotlin kt swift scala dart groovy zig'],
  [HASH, 'py python python3 rb ruby sh bash shell zsh fish console shellsession yaml yml toml ini conf perl pl r makefile make dockerfile docker nix elixir ex exs'],
  [SQL, 'sql mysql postgres postgresql pgsql sqlite plsql'],
  [MARKUP, 'html htm xml svg vue svelte xhtml'],
  [JSON_LANG, 'json jsonc json5 jsonl ndjson'],
  [CSS, 'css scss sass less'],
  [DIFF, 'diff patch'],
] as const) {
  for (const name of names.split(' ')) ALIASES[name] = language
}

/** Whether $lang has a colouring here. */
export function supports(lang: string): boolean {
  return ALIASES[lang.toLowerCase()] !== undefined
}

function escape(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

const compiled = new Map<Language, RegExp>()

function scanner(language: Language): RegExp {
  let regex = compiled.get(language)
  if (regex === undefined) {
    const flags = new Set(['g', 'm'])
    for (const rule of language.rules) if (rule.pattern.flags.includes('i')) flags.add('i')
    // One alternation, a capture group per rule: the first rule that matches at a spot wins.
    regex = new RegExp(language.rules.map((rule) => `(${rule.pattern.source})`).join('|'), [...flags].join(''))
    compiled.set(language, regex)
  }
  return regex
}

/**
 * $code as escaped HTML with its tokens wrapped in `<span class="hl-…">`;
 * null when $lang is not one this knows, or the code is too long.
 */
export function highlight(code: string, lang: string): string | null {
  const language = ALIASES[lang.toLowerCase()]
  if (language === undefined || code.length > MAX_HIGHLIGHT_CHARS) return null
  const regex = scanner(language)
  regex.lastIndex = 0
  let out = ''
  let at = 0
  for (let match = regex.exec(code); match !== null; match = regex.exec(code)) {
    if (match[0] === '') {
      regex.lastIndex++
      continue
    }
    const rule = language.rules[match.slice(1).findIndex((group) => group !== undefined)]
    out += escape(code.slice(at, match.index))
    out += rule ? `<span class="hl-${rule.kind}">${escape(match[0])}</span>` : escape(match[0])
    at = match.index + match[0].length
  }
  return out + escape(code.slice(at))
}

/** Colour every fenced block under $root whose language this knows (`<code class="language-…">`). */
export function highlightIn(root: ParentNode): number {
  let done = 0
  for (const code of root.querySelectorAll<HTMLElement>('pre > code[class*="language-"]')) {
    if (code.dataset.highlighted === 'true') continue
    const lang = /(?:^|\s)language-([\w+#.-]+)/.exec(code.className)?.[1] ?? ''
    const html = highlight(code.textContent ?? '', lang)
    if (html === null) continue
    code.innerHTML = html
    code.dataset.highlighted = 'true'
    done++
  }
  return done
}
