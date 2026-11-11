/**
 * MA-17 reverse leg: raw English copy hiding in component code.
 *
 * The i18nCoverage spec proves every t('web.*') KEY exists; nothing proved
 * the OTHER direction — that user-facing sentences actually GO through t().
 * Decades of "temporary" literals drift exactly that way: reducer stop-texts,
 * dictation notices, panel empty-states and confirm() prompts froze English
 * into code while the chrome catalogue translated everything around them.
 *
 * This guard derives the raw-copy set from the shipped tree (template text
 * nodes, static title/placeholder attrs, interpolation string literals,
 * script-block literals, and plain .ts literals — with the documented shape
 * thresholds below) and demands set-equality with the frozen roster. Two
 * directions, both loud: NEW raw copy reddens (add t() instead, or justify a
 * roster entry), and a SHRINKING set reddens too (when copy moves into the
 * catalogue, delete its roster line — the ratchet only tightens with intent).
 *
 * Shape rules (deliberately heuristic; see PROGRESS.md limitations):
 *  - template shapes (attr/text/interp): >= 2 words starting with a capital,
 *    or >= 4 words any case;
 *  - code shapes (script/ts): >= 3 words starting with a capital, or >= 5
 *    words any case — wire-shaped lowercase errors below the line are
 *    disclosed below rather than rostered;
 *  - string holes (${'{...}'} / interpolation) are flattened before counting;
 *  - keycap-glyph tables and code-bracket strings are excluded; line and
 *    block comments are stripped first, so prose in comments never counts.
 *
 * Known limitation, stated not hidden: a few genuinely user-facing strings
 * sit just under the word thresholds ('Loading…', 'MCP', single-word states)
 * or carry arrow glyphs ('History compacted … a → b tokens'); the roster is
 * a floor on awareness, not a parser of intent.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, test } from 'vitest'

const ROOT = resolve(process.cwd(), 'src-web')

/** Frozen offenders: `file|shape|flattened text`, sorted. */
const KNOWN_RAWS = [
  "src-web/components/panels/BackgroundPanel.vue|text|Permission mode : nothing will ask a human before this run acts.",
  "src-web/components/panels/ChangesPanel.vue|text|is untracked — no diff for it in the workspace.",
  "src-web/components/panels/ContextPanel.vue|script|The file on disk no longer holds the bytes this listing measured — reopen Edit to save against the current file.",
  "src-web/components/panels/GeneratePanel.vue|script|Image generation and video generation are not enabled on this server.",
  "src-web/components/panels/GeneratePanel.vue|script|This server does not report media capability, so generation is not offered.",
  "src-web/components/panels/MemoryPanel.vue|interp|No note matches.",
  "src-web/components/panels/MemoryPanel.vue|interp|No notes.",
  "src-web/components/panels/MemoryPanel.vue|script|Delete this note?",
  "src-web/components/panels/MemoryPanel.vue|script|This project note could not be written into the repository; it was saved to your home memory instead, where teammates will not see it.",
  "src-web/components/panels/MemoryPanel.vue|script|Undo the memory store to (\" \")? The home store's notes are replaced by that commit's state.",
  "src-web/components/panels/ProcessesSection.vue|script|No output yet.",
  "src-web/components/panels/UsagePanel.vue|text|Sessions kept day",
  "src-web/components/panels/WorkflowPanel.vue|interp|Workflow tool",
  "src-web/components/settings/SettingsView.vue|script|Saved setting to",
  "src-web/lib/dictation.ts|ts|Dictation is already running.",
  "src-web/lib/dictation.ts|ts|Dictation needs the microphone — allow it in the browser, then try again.",
  "src-web/lib/dictation.ts|ts|Heard nothing — dictation stopped.",
  "src-web/lib/dictation.ts|ts|No microphone was found to dictate with.",
  "src-web/lib/dictation.ts|ts|The browser blocked its speech service for this page.",
  "src-web/lib/dictation.ts|ts|The browser speech service was unreachable; check the network.",
  "src-web/lib/media.ts|ts|artifact exceeds the -slice fetch budget",
  "src-web/lib/media.ts|ts|media.fetch answered at offset , asked at",
  "src-web/lib/media.ts|ts|media.fetch delivered of bytes",
  "src-web/lib/media.ts|ts|media.fetch ended an empty slice without eof",
  "src-web/lib/paste.ts|ts|pasted skipped: the browser reported no type, and only images can be sent.",
  "src-web/lib/questions.ts|ts|— is waiting for an answer",
  "src-web/lib/questions.ts|ts|— project trust prompt for is waiting in the Context panel",
  "src-web/lib/recorder.ts|ts|the take was already abandoned",
  "src-web/protocol/auth.ts|ts|the server answered no canvas link",
  "src-web/protocol/auth.ts|ts|the server answered no ticket",
  "src-web/protocol/auth.ts|ts|the server cannot be reached",
  "src-web/protocol/client.ts|ts|server.hello returned a malformed result",
  "src-web/protocol/client.ts|ts|signed out by the server",
  "src-web/protocol/client.ts|ts|the server did not answer after tries",
  "src-web/protocol/jsonrpc.ts|ts|the connection to the server was lost",
  "src-web/stores/clientTools.ts|ts|the call had already reached its deadline when it arrived here",
  "src-web/stores/clientTools.ts|ts|the tool did not finish before its deadline",
  "src-web/stores/clientTools.ts|ts|this tab no longer implements that tool",
  "src-web/stores/clientTools.ts|ts|tools.client.register answered without a registration id",
  "src-web/stores/reducer.ts|ts|Events dropped while this tab fell behind ( ) — resynchronising from a fresh snapshot.",
  "src-web/stores/reducer.ts|ts|Spend cap reached: $ of $",
  "src-web/stores/reducer.ts|ts|Turn stopped: it reached its step limit.",
  "src-web/stores/reducer.ts|ts|Turn stopped: the loop guard cut a repeating tool call.",
  "src-web/stores/reducer.ts|ts|Turn stopped: the reply hit the output limit.",
  "src-web/stores/reducer.ts|ts|Turn stopped: the spend cap was reached.",
  "src-web/stores/session.ts|ts|/ runs only in the terminal UI",
  "src-web/stores/session.ts|ts|documents ride a prose prompt; a /command or !bang takes none",
  "src-web/stores/session.ts|ts|images ride a prose prompt; a /command or !bang takes none",
  "src-web/stores/session.ts|ts|the older page carried no rows",
  "src-web/stores/sessions.ts|ts|session.export answered `more` without advancing its offset",
  "src-web/stores/settings.ts|ts|notifications blocked by the browser",
  "src-web/stores/settings.ts|ts|notifications need HTTPS or localhost",
  "src-web/stores/settings.ts|ts|this browser has no desktop notifications",
  "src-web/stores/settings/fields.ts|ts|enter JSON, or reset the setting",
  "src-web/stores/settings/fields.ts|ts|locked by the environment or a flag",
  "src-web/stores/settings/fields.ts|ts|not writable over the wire",
  "src-web/stores/settings/fields.ts|ts|this tier cannot be written",
  "src-web/stores/settings/fields.ts|ts|user-tier only: a project may not set it",
]

const WORD = /[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu
function words(s: string): number {
  return (s.match(WORD) || []).filter((w) => w.length >= 2).length
}
function holeless(s: string): string {
  return s.replace(/\$\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/g, ' ').replace(/\s+/g, ' ').trim()
}
function flaggable(lit: string, templateShape: boolean): string | null {
  const t = holeless(lit)
  if (!t || /[\\^|<>{}[\]]/.test(t)) return null
  if (/[←-⇿⌀-⏿·⌘⌥⇧⌃]/u.test(t)) return null // keycap glyph tables, not prose
  if (!/[ ]/.test(t)) return null
  const w = words(t)
  const startsUpper = /^[\p{Lu}]/u.test(t)
  if (templateShape && startsUpper && w >= 2) return t
  if (templateShape && w >= 4) return t
  if (startsUpper && w >= 3) return t
  if (w >= 5) return t
  return null
}
function literals(src: string): string[] {
  return [...src.matchAll(/'([^'\n]*)'|"([^"\n]*)"|`([^`]*)`/g)].map((m) => m[1] ?? m[2] ?? m[3] ?? '')
}
function sourcesUnder(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (entry === '__tests__' || entry === 'node_modules') continue
    if (statSync(full).isDirectory()) out.push(...sourcesUnder(full))
    else if ((/\.ts$/.test(entry) || entry.endsWith('.vue')) && !entry.endsWith('.generated.ts')) out.push(full)
  }
  return out
}
function derivedRaws(): string[] {
  const out: string[] = []
  const here = resolve(process.cwd())
  const push = (file: string, kind: string, lit: string): void => {
    const tpl = kind === 'attr' || kind === 'text' || kind === 'interp'
    const hit = flaggable(lit, tpl)
    if (hit) out.push(`${relative(here, file)}|${kind}|${hit}`)
  }
  for (const file of sourcesUnder(ROOT)) {
    const src = readFileSync(file, 'utf8')
    if (file.endsWith('.vue')) {
      const tpl = /^<template>\r?\n([\s\S]*?)\r?\n<\/template>/m.exec(src)
      if (tpl) {
        const body = (tpl[1] ?? '').replace(/<!--[\s\S]*?-->/g, '\n')
        for (const m of body.matchAll(/(?:^|[^\w:.-])(?:title|placeholder)="([^"{}]+)"/g)) push(file, 'attr', m[1] ?? '')
        for (const m of body.matchAll(/\{\{[\s\S]*?\}\}/g)) for (const lit of literals(m[0] ?? '')) push(file, 'interp', lit)
        const text = body.replace(/\{\{[\s\S]*?\}\}/g, ' ').replace(/<[^>]*>/g, '\n')
        for (const line of text.split('\n')) {
          const t = line.trim().replace(/&[a-z]+;/g, ' ')
          if (t && !/[{}<>|`"'=]/.test(t)) push(file, 'text', t)
        }
      }
      const script = /^<script[^>]*>\r?\n([\s\S]*?)\r?\n<\/script>/m.exec(src)
      if (script) for (const lit of literals((script[1] ?? '').replace(/\/\*[\s\S]*?\*\//g, '\n').replace(/\s+\/\/.*$/gm, ''))) push(file, 'script', lit)
    } else {
      for (const lit of literals(src.replace(/\/\*[\s\S]*?\*\//g, '\n').replace(/\s+\/\/.*$/gm, ''))) push(file, 'ts', lit)
    }
  }
  return [...new Set(out)].sort()
}

describe('raw-copy census (MA-17)', () => {
  test('the roster is sorted, unique, and at its measured floor', () => {
    expect(KNOWN_RAWS).toEqual([...new Set(KNOWN_RAWS)].sort())
    // F-w1 2026-11-11: the web i18n leak wave took the roster 64 -> 58
    // (CanvasCard, attention.ts and the Composer/StatusBar/Banner copy all moved
    // behind web.* keys). The floor is the MEASURED figure, re-derived — the
    // set-equality leg above stays exact, so this tightens the ratchet, never loosens it.
    expect(KNOWN_RAWS.length).toBeGreaterThanOrEqual(58)
  })

  test('the shipped tree matches the frozen offender roster exactly', () => {
    const derived = derivedRaws()
    const added = derived.filter((r) => !KNOWN_RAWS.includes(r))
    const removed = KNOWN_RAWS.filter((r) => !derived.includes(r))
    expect(added, `new raw English (move it through t() — the catalogue chain is README "Adding a user-facing string"): ${JSON.stringify(added, null, 1)}`).toEqual([])
    expect(removed, `roster entries that no longer derive (deliberate? delete the lines; the census only tightens on purpose): ${JSON.stringify(removed, null, 1)}`).toEqual([])
  })
})
