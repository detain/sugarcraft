/**
 * A unified diff (`tool.finished.diff`) as rows to render: the file
 * headers, each hunk header, and every line with its old and new line
 * numbers — the gutter semantics of sugar-crush's own TUI diff
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

export function diffStats(lines: DiffLine[]): DiffStats {
  let added = 0
  let removed = 0
  for (const line of lines) {
    if (line.kind === 'add') added++
    else if (line.kind === 'del') removed++
  }
  return { added, removed }
}
