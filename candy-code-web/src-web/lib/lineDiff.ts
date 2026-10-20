/**
 * WEB-5a-ii: the unified diff an Edit/Write permission card shows.
 *
 * The ask echoes the tool's own arguments — the `old_string`/`new_string` of
 * an Edit, the `content` of a Write — never a diff, so the card mints one
 * here: a line-level LCS over the pair, emitted in the unified shape
 * `DiffView` already parses. The card is the security gate, so every entry
 * point fail-softs to null (the raw JSON dump rides on) instead of throwing,
 * and inputs beyond the work caps are refused as too big to diff quietly.
 */
export interface LineDiffPreview {
  label: string
  text: string
  added: number
  removed: number
}

interface Op {
  kind: 'ctx' | 'del' | 'add'
  text: string
}

/** Lines of context kept around each change; two changes closer than this share a hunk. */
const CONTEXT = 3
/** Either side longer than this is not diffed (the DP is quadratic). */
const MAX_LINES = 1200
/** A rendered diff beyond this many characters is refused. */
const MAX_DIFF_CHARS = 200_000

/**
 * The before/after an ask's arguments carry: one pair per Edit hunk (the
 * batched `edits` form included), a new-file view for Write. Null when the
 * payload is not an edit shape at all — a Bash ask, a missing string — so
 * the caller keeps the raw dump.
 */
export function diffPairsFor(tool: string, args: Record<string, unknown>): { before: string; after: string }[] | null {
  const str = (value: unknown): string | null => (typeof value === 'string' ? value : null)
  if (tool === 'Edit') {
    const oldString = str(args['old_string'])
    const newString = str(args['new_string'])
    if (oldString !== null && newString !== null) return [{ before: oldString, after: newString }]
    const edits = args['edits']
    if (!Array.isArray(edits) || edits.length === 0) return null
    const pairs: { before: string; after: string }[] = []
    for (const edit of edits) {
      if (edit === null || typeof edit !== 'object') return null
      const before = str((edit as Record<string, unknown>)['old_string'])
      const after = str((edit as Record<string, unknown>)['new_string'])
      if (before === null || after === null) return null
      pairs.push({ before, after })
    }
    return pairs
  }
  if (tool === 'Write') {
    // Write carries only the new content: the preview is the file added whole
    // (and says so in the label); its `before` is not in the payload.
    const content = str(args['content'])
    return content === null ? null : [{ before: '', after: content }]
  }
  return null
}

/** The card's diff preview for one ask, or null when it must fall back to raw JSON. */
export function askDiffPreview(tool: string, args: Record<string, unknown>): LineDiffPreview | null {
  try {
    const path = typeof args['file_path'] === 'string' ? (args['file_path'] as string) : tool
    const label =
      tool === 'Write'
        ? `${path} (${args['overwrite'] === true ? 'replaces the file' : 'new file'})`
        : path
    return renderPreview(label, diffPairsFor(tool, args))
  } catch {
    // A payload so malformed it broke the shape read: the raw dump answers.
    return null
  }
}

function renderPreview(label: string, pairs: { before: string; after: string }[] | null): LineDiffPreview | null {
  if (pairs === null) return null
  const chunks: string[] = []
  let added = 0
  let removed = 0
  for (const pair of pairs) {
    const rendered = unifiedDiff(pair.before, pair.after, label)
    if (rendered === null) return null
    chunks.push(rendered.text)
    added += rendered.added
    removed += rendered.removed
  }
  const text = chunks.join('\n')
  if (text.length > MAX_DIFF_CHARS) return null
  return { label, text, added, removed }
}

/** Unified diff of two texts as `DiffView`/`parseUnifiedDiff` expect them. */
export function unifiedDiff(before: string, after: string, label: string): { text: string; added: number; removed: number } | null {
  const oldLines = splitLines(before)
  const newLines = splitLines(after)
  if (oldLines.length > MAX_LINES || newLines.length > MAX_LINES) return null
  const ops = diffOps(oldLines, newLines)
  const lines: string[] = [`--- a/${label}`, `+++ b/${label}`]
  let added = 0
  let removed = 0
  for (const hunk of hunks(ops)) {
    let oldStart = 1
    let newStart = 1
    let oldCount = 0
    let newCount = 0
    for (let i = 0; i < hunk.from; i++) {
      const op = ops[i] as Op
      if (op.kind !== 'add') oldStart++
      if (op.kind !== 'del') newStart++
    }
    for (const op of ops.slice(hunk.from, hunk.to)) {
      if (op.kind !== 'add') oldCount++
      if (op.kind !== 'del') newCount++
    }
    lines.push(`@@ -${oldStart},${oldCount} +${newStart},${newCount} @@`)
    for (const op of ops.slice(hunk.from, hunk.to)) {
      if (op.kind === 'ctx') lines.push(` ${op.text}`)
      else if (op.kind === 'del') {
        lines.push(`-${op.text}`)
        removed++
      } else {
        lines.push(`+${op.text}`)
        added++
      }
    }
  }
  if (ops.every((op) => op.kind === 'ctx')) {
    // Identical before/after: an empty diff is still an honest preview.
    lines.push('@@ -1,0 +1,0 @@')
  }
  return { text: lines.join('\n'), added, removed }
}

/** Split for diffing: one entry per line, a trailing newline not doubled. */
function splitLines(text: string): string[] {
  if (text === '') return []
  const lines = text.split('\n')
  if (lines.length > 0 && lines[lines.length - 1] === '') lines.pop()
  return lines
}

/** Line-level diff by LCS over the trimmed middle; equal head/tail skipped. */
function diffOps(before: string[], after: string[]): Op[] {
  let head = 0
  while (head < before.length && head < after.length && before[head] === after[head]) head++
  let tail = 0
  while (
    tail < before.length - head &&
    tail < after.length - head &&
    before[before.length - 1 - tail] === after[after.length - 1 - tail]
  )
    tail++
  const midOld = before.slice(head, before.length - tail)
  const midNew = after.slice(head, after.length - tail)
  const ops: Op[] = []
  for (let i = 0; i < head; i++) ops.push({ kind: 'ctx', text: before[i] as string })
  // dp[i][j] = LCS length of midOld[i:] against midNew[j:].
  const n = midOld.length
  const m = midNew.length
  const dp = new Int32Array((n + 1) * (m + 1))
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      dp[i * (m + 1) + j] =
        midOld[i] === midNew[j]
          ? (dp[(i + 1) * (m + 1) + j + 1] as number) + 1
          : Math.max(dp[(i + 1) * (m + 1) + j] as number, dp[i * (m + 1) + j + 1] as number)
    }
  }
  let i = 0
  let j = 0
  while (i < n && j < m) {
    if (midOld[i] === midNew[j]) {
      ops.push({ kind: 'ctx', text: midOld[i] as string })
      i++
      j++
    } else if ((dp[(i + 1) * (m + 1) + j] as number) >= (dp[i * (m + 1) + j + 1] as number)) {
      ops.push({ kind: 'del', text: midOld[i] as string })
      i++
    } else {
      ops.push({ kind: 'add', text: midNew[j] as string })
      j++
    }
  }
  while (i < n) ops.push({ kind: 'del', text: midOld[i++] as string })
  while (j < m) ops.push({ kind: 'add', text: midNew[j++] as string })
  for (let k = before.length - tail; k < before.length; k++) ops.push({ kind: 'ctx', text: before[k] as string })
  return ops
}

/** Changed lines grouped with context into hunk ranges [from, to). */
function hunks(ops: Op[]): { from: number; to: number }[] {
  const changed: number[] = []
  ops.forEach((op, index) => {
    if (op.kind !== 'ctx') changed.push(index)
  })
  const out: { from: number; to: number }[] = []
  for (const at of changed) {
    const from = Math.max(0, at - CONTEXT)
    const to = Math.min(ops.length, at + CONTEXT + 1)
    const last = out[out.length - 1]
    if (last !== undefined && from <= last.to) last.to = Math.max(last.to, to)
    else out.push({ from, to })
  }
  return out
}
