/**
 * Terminal escape sequences in text meant for a terminal (ISS-109).
 *
 * Tool output arrives verbatim from commands that colour their work; the
 * browser's `pre` would show the escapes as noise. The raw text is never
 * touched — it is what the model re-reads and what the full-output fetch
 * returns — only its display form is stripped here.
 */
const SEQUENCES = /\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\)|[@-Z\\-_])/g

/** Removes CSI/OSC/other escape sequences (SGR included). */
export function stripTerminalSequences(text: string): string {
  return text.replace(SEQUENCES, '')
}

/**
 * One run of characters that share a style — the unit the tail pane paints.
 * `classes` carries the SGR look as browser classes (built by `lookClasses`).
 */
export interface AnsiSegment {
  text: string
  classes: string[]
}

const FG_NAMES = ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white']
const BG_CODE = new Set<number>([...Array.from({ length: 8 }, (_, i) => 40 + i), ...Array.from({ length: 8 }, (_, i) => 100 + i)])

interface Look {
  bold: boolean
  dim: boolean
  italic: boolean
  underline: boolean
  inverse: boolean
  fg: string | null
  bg: string | null
}

function lookClasses(look: Look): string[] {
  const classes: string[] = []
  if (look.bold) classes.push('ansi-bold')
  if (look.dim) classes.push('ansi-dim')
  if (look.italic) classes.push('ansi-italic')
  if (look.underline) classes.push('ansi-underline')
  if (look.inverse) classes.push('ansi-inverse')
  if (look.fg !== null) classes.push(`ansi-fg-${look.fg}`)
  if (look.bg !== null) classes.push(`ansi-bg-${look.bg}`)

  return classes
}

/**
 * SGR state the poller understands: bold, dim, italic, underline, inverse and
 * the 16 palette colours become classes. An unrecognised plain code is
 * ignored, but an extended (38/48) colour the poller cannot name CLEARS that
 * slot back to default — it does not keep a colour it cannot render. Cursor
 * moves and OSC sequences carry no style and are dropped from the render.
 *
 * Line discipline is emulated at the text level only: a lone `\\r` rewrites
 * the current line the way a terminal overwrite spinner would, and a DEL
 * (0x7f) erases the character before the caret (a raw BS 0x08 is not
 * interpreted). This is what makes a
 * polling tail pane readable — it does NOT make the browser a terminal
 * emulator: there is no resize, no alternate screen, no input echo path.
 */
export function ansiSegments(text: string): AnsiSegment[] {
  const look: Look = { bold: false, dim: false, italic: false, underline: false, inverse: false, fg: null, bg: null }
  const cells: { ch: string; classes: string[] }[] = []
  let lineStart = 0
  const re = new RegExp(SEQUENCES.source, 'g')
  let rest = text
  while (rest.length > 0) {
    const hit = re.exec(rest)
    if (hit === null) break
    const before = rest.slice(0, hit.index)
    writeText(before)
    const sequence = hit[0]
    if (sequence.startsWith('\x1b[') && sequence.endsWith('m')) applySgr(sequence.slice(2, -1))
    rest = rest.slice(hit.index + sequence.length)
    re.lastIndex = 0
  }
  writeText(rest)

  // Run-length encode: adjacent cells with equal class lists merge.
  const segments: AnsiSegment[] = []
  for (const cell of cells) {
    const last = segments[segments.length - 1]
    if (last && last.classes.length === cell.classes.length && last.classes.every((name, i) => name === cell.classes[i])) {
      last.text += cell.ch
    } else {
      segments.push({ text: cell.ch, classes: [...cell.classes] })
    }
  }
  return segments

  function writeText(chunk: string): void {
    const chars = Array.from(chunk)
    for (const [index, ch] of chars.entries()) {
      if (ch === '\r') {
        // The pty's own CRLF (ONLCR) is a line END, not an overwrite: a CR
        // followed by an LF just rides the newline. A LONE CR is the
        // terminal-progress idiom — it rewrites the current line.
        if (chars[index + 1] === '\n') continue
        cells.length = lineStart
      } else if (ch === '\x7f') {
        if (cells.length > lineStart) cells.pop()
      } else {
        cells.push({ ch, classes: lookClasses(look) })
        if (ch === '\n') lineStart = cells.length
      }
    }
  }

  function applySgr(params: string): void {
    const codes = (params === '' ? ['0'] : params.split(';')).map((part) => Number.parseInt(part === '' ? '0' : part, 10))
    for (let i = 0; i < codes.length; i++) {
      const code = codes[i]
      if (code === undefined) continue // unreachable: i < codes.length; narrows the flag
      if (code === 0) {
        look.bold = look.dim = look.italic = look.underline = look.inverse = false
        look.fg = look.bg = null
      } else if (code === 1) look.bold = true
      else if (code === 2) look.dim = true
      else if (code === 3) look.italic = true
      else if (code === 4) look.underline = true
      else if (code === 7) look.inverse = true
      else if (code === 22) look.bold = look.dim = false
      else if (code === 23) look.italic = false
      else if (code === 24) look.underline = false
      else if (code === 27) look.inverse = false
      else if (code === 39) look.fg = null
      else if (code === 49) look.bg = null
      else if (code >= 30 && code <= 37) look.fg = FG_NAMES[code - 30] ?? null
      else if (code >= 90 && code <= 97) look.fg = `bright-${FG_NAMES[code - 90]}`
      else if (BG_CODE.has(code)) look.bg = code >= 100 ? `bright-${FG_NAMES[code - 100]}` : FG_NAMES[code - 40] ?? null
      else if (code === 38 || code === 48) {
        // Consume the extended-colour tail. Palette 0-15 still names a class;
        // 256-colour beyond it and truecolor CLEAR the slot (documented limit).
        const mode = codes[i + 1]
        const size = mode === 5 ? 2 : mode === 2 ? 5 : 0
        const value = mode === 5 ? codes[i + 2] : undefined
        const target = code === 38 ? 'fg' : 'bg'
        if (mode === 5 && value !== undefined && value >= 0 && value <= 7) look[target] = FG_NAMES[value] ?? null
        else if (mode === 5 && value !== undefined && value >= 8 && value <= 15) look[target] = `bright-${FG_NAMES[value - 8]}`
        else look[target] = null
        i += size
      }
    }
  }
}
