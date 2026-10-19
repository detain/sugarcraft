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

export function diffStats(lines: DiffLine[]): DiffStats {
  let added = 0
  let removed = 0
  for (const line of lines) {
    if (line.kind === 'add') added++
    else if (line.kind === 'del') removed++
  }
  return { added, removed }
}
