/**
 * A unified diff (`tool.finished.diff`) as rows to render: the file
 * headers, each hunk header, and every line with its old and new line
 * numbers — the gutter semantics of candy-code's own TUI diff
 * (`Tui/DiffGutter.php`).
 */
export type DiffLineKind = 'file' | 'hunk' | 'add' | 'del' | 'ctx' | 'meta'

export interface DiffLine {
  kind: DiffLineKind
  text: string
  oldNo: number | null
  newNo: number | null
}

export interface DiffStats {
  added: number
  removed: number
}

export function parseUnifiedDiff(diff: string): DiffLine[] {
  const lines: DiffLine[] = []
  let oldNo = 0
  let newNo = 0
  let inHunk = false
  const raw = diff.replace(/\n$/, '').split('\n')

  for (const line of raw) {
    const hunk = /^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/.exec(line)
    if (hunk) {
      oldNo = Number(hunk[1])
      newNo = Number(hunk[2])
      inHunk = true
      lines.push({ kind: 'hunk', text: line, oldNo: null, newNo: null })
    } else if (!inHunk && (line.startsWith('--- ') || line.startsWith('+++ ') || line.startsWith('diff '))) {
      lines.push({ kind: 'file', text: line, oldNo: null, newNo: null })
    } else if (inHunk && line.startsWith('+')) {
      lines.push({ kind: 'add', text: line.slice(1), oldNo: null, newNo: newNo++ })
    } else if (inHunk && line.startsWith('-')) {
      lines.push({ kind: 'del', text: line.slice(1), oldNo: oldNo++, newNo: null })
    } else if (inHunk && line.startsWith(' ')) {
      lines.push({ kind: 'ctx', text: line.slice(1), oldNo: oldNo++, newNo: newNo++ })
    } else if (line.startsWith('diff ') || line.startsWith('--- ') || line.startsWith('+++ ')) {
      inHunk = false
      lines.push({ kind: 'file', text: line, oldNo: null, newNo: null })
    } else {
      lines.push({ kind: 'meta', text: line, oldNo: null, newNo: null })
    }
  }
  return lines
}

/**
 * The section of a multi-file unified diff that belongs to one path — the
 * `diff --git a/… b/…` header and everything up to the next file header, or
 * null when the diff has no section for it (an untracked file never does).
 * Matches either side of the header so a rename answers to both names.
 */
export function diffSectionForPath(diff: string, path: string): string | null {
  if (diff === '' || path === '') return null
  const lines = diff.split('\n')
  let header: string | null = null
  let body: string[] = []
  let found = false
  const flush = (): string | null => {
    if (!found || header === null) return null
    return [header, ...body].join('\n').replace(/\n+$/, '')
  }
  for (const line of lines) {
    if (line.startsWith('diff --git ')) {
      const match = /^diff --git a\/(.*) b\/(.*)$/.exec(line)
      if (found) break
      header = line
      body = []
      found = match !== null && (match[1] === path || match[2] === path)
    } else if (header !== null) {
      body.push(line)
    }
  }
  return flush()
}

/**
 * One row of the side-by-side (split) layout. `span` carries a row that is
 * never paired — a file header, a `@@` hunk header, or other meta text —
 * which renders full width; paired rows carry at most one line per half.
 *
 * The pairing rule is the canonical one of sugar-diff's `SideBySideFormat`
 * (C-TUI-2.b): within one changed region removals queue in order and an
 * addition takes the oldest queued partner (line-by-line, the way split
 * viewers align a replacement block); leftovers stand alone on their own
 * side; context pairs with itself; nothing pairs across a hunk or file
 * boundary. The `\ No newline at end of file` annotation is carried as a
 * span row without cutting the pending run — unlike the PHP pairer, this
 * viewer is total: no input row ever vanishes from the layout.
 */
export type DiffSplitRow =
  | { type: 'span'; span: DiffLine }
  | { type: 'pair'; left: DiffLine | null; right: DiffLine | null }

export function splitDiffRows(lines: DiffLine[]): DiffSplitRow[] {
  const rows: DiffSplitRow[] = []
  let pending: DiffLine[] = []
  const flushPending = (): void => {
    for (const orphan of pending) rows.push({ type: 'pair', left: orphan, right: null })
    pending = []
  }
  for (const line of lines) {
    if (line.kind === 'del') {
      pending.push(line)
    } else if (line.kind === 'add') {
      rows.push({ type: 'pair', left: pending.shift() ?? null, right: line })
    } else if (line.kind === 'ctx') {
      flushPending()
      rows.push({ type: 'pair', left: line, right: line })
    } else {
      if (!(line.kind === 'meta' && line.text.startsWith('\\'))) flushPending()
      rows.push({ type: 'span', span: line })
    }
  }
  flushPending()
  return rows
}

/**
 * Viewport width in px at which `diffStyle: auto` switches from the unified
 * layout to side-by-side (C-TUI-2.f) — the web twin of candy-code's
 * `Renderer::DIFF_SPLIT_MIN_COLS`: below it each half would be narrower than
 * a readable source column; at or above, both halves keep real measure.
 */
export const DIFF_SPLIT_MIN_PX = 1100

/**
 * Resolve the client's `diffStyle` preference (`auto` | `unified` | `split`)
 * against the current viewport width. Anything unrecognised (junk restored
 * from localStorage) resolves to `unified` — the layout that shows every row
 * without trusting a column budget.
 */
export function resolveDiffStyle(style: string, widthPx: number): 'unified' | 'split' {
  if (style === 'split') return 'split'
  if (style === 'auto') return widthPx >= DIFF_SPLIT_MIN_PX ? 'split' : 'unified'
  return 'unified'
}

/**
 * The file path a diff header row names — the new-side name of a
 * `diff --git a/… b/…`, or the path of a `--- ` / `+++ ` line, with git's
 * trailing timestamp dropped and the `a/` `b/` prefix stripped. '' for any
 * other row shape; `+++ /dev/null` keeps its path and is declined by the
 * caller's language lookup, which knows no extension for it.
 */
export function diffPathForHeader(text: string): string {
  let path = ''
  if (text.startsWith('+++ ') || text.startsWith('--- ')) {
    path = text.slice(4)
  } else if (text.startsWith('diff --git ')) {
    for (const token of text.slice(11).trim().split(/\s+/)) {
      if (token.startsWith('b/')) path = token
    }
  }
  path = (path.trim().split(/\s+/)[0] ?? '').replace(/^a\//, '').replace(/^b\//, '')
  return path
}

export function diffStats(lines: DiffLine[]): DiffStats {
  let added = 0
  let removed = 0
  for (const line of lines) {
    if (line.kind === 'add') added++
    else if (line.kind === 'del') removed++
  }
  return { added, removed }
}
