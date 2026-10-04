/** Small display helpers shared by the cards and the status bar. */

/** The one argument that says what a tool call does, for its card header. */
export function keyArgument(args: Record<string, unknown>): string {
  for (const name of ['command', 'file_path', 'path', 'pattern', 'url', 'query', 'description', 'task', 'prompt']) {
    const value = args[name]
    if (typeof value === 'string' && value.trim() !== '') return oneLine(value, 120)
  }
  for (const value of Object.values(args)) {
    if (typeof value === 'string' && value.trim() !== '') return oneLine(value, 120)
  }
  return ''
}

export function oneLine(text: string, max: number): string {
  const flat = text.replace(/\s+/g, ' ').trim()
  return flat.length > max ? `${flat.slice(0, max - 1)}…` : flat
}

export function duration(ms: number | undefined): string {
  if (ms === undefined) return ''
  if (ms < 1000) return `${ms} ms`
  if (ms < 60_000) return `${(ms / 1000).toFixed(1)} s`
  return `${Math.floor(ms / 60_000)} m ${Math.round((ms % 60_000) / 1000)} s`
}

export function money(usd: number | null | undefined): string {
  if (usd === null || usd === undefined) return '$0.00'
  return usd < 0.01 && usd > 0 ? `$${usd.toFixed(4)}` : `$${usd.toFixed(2)}`
}

export function tokens(count: number | null | undefined): string {
  if (count === null || count === undefined) return '—'
  return count >= 1000 ? `${(count / 1000).toFixed(count >= 10_000 ? 0 : 1)}k` : String(count)
}

/** "2h ago" for a server timestamp (`YYYY-MM-DD HH:MM:SS`, UTC) or an ISO string. */
export function ago(stamp: string | null | undefined, now: number = Date.now()): string {
  if (!stamp) return ''
  const parsed = Date.parse(/Z$|[+-]\d\d:?\d\d$/.test(stamp) ? stamp : `${stamp.replace(' ', 'T')}Z`)
  if (Number.isNaN(parsed)) return ''
  const seconds = Math.max(0, Math.round((now - parsed) / 1000))
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86_400) return `${Math.floor(seconds / 3600)}h ago`
  return `${Math.floor(seconds / 86_400)}d ago`
}
