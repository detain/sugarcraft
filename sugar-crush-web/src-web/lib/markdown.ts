import DOMPurify from 'dompurify'
import MarkdownIt from 'markdown-it'

/**
 * Markdown for model and tool text. Both are untrusted (Appendix O §7.2):
 * raw HTML is off in the parser, and the output still goes through DOMPurify
 * before it reaches `v-html`, so neither a crafted reply nor a file a tool
 * read can put script, styles or event handlers on the page. Links open in a
 * new tab without a referrer or an opener.
 */
const md = new MarkdownIt({ html: false, linkify: true, breaks: false, typographer: false })

const fence = md.renderer.rules.fence
md.renderer.rules.fence = (tokens, idx, options, env, self) => {
  const token = tokens[idx]
  const lang = token?.info.trim().split(/\s+/)[0] ?? ''
  const body = fence ? fence(tokens, idx, options, env, self) : self.renderToken(tokens, idx, options)
  const label = lang !== '' ? `<span class="code-lang">${md.utils.escapeHtml(lang)}</span>` : ''
  return `<div class="code-block">${label}${body}</div>`
}

md.renderer.rules.link_open = (tokens, idx, options, _env, self) => {
  const token = tokens[idx]
  token?.attrSet('target', '_blank')
  token?.attrSet('rel', 'noopener noreferrer')
  return self.renderToken(tokens, idx, options)
}

/** The parser's HTML, before sanitising (what the unit tests pin). */
export function markdownToHtml(text: string): string {
  return md.render(text)
}

export function renderMarkdown(text: string): string {
  return DOMPurify.sanitize(markdownToHtml(text), {
    USE_PROFILES: { html: true },
    FORBID_TAGS: ['style', 'form', 'input', 'button'],
    ADD_ATTR: ['target'],
  })
}
