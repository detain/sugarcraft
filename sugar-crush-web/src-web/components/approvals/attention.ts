import { watch } from 'vue'
import { oneLine } from '../../lib/format'
import type { SessionSummary } from '../../protocol/generated'
import { useApprovalsStore, type SessionAsk } from '../../stores/approvals'
import { useLayoutStore } from '../../stores/layout'
import { useSessionsStore } from '../../stores/sessions'
import { useSettingsStore } from '../../stores/settings'

const BRAND = 'SugarCrush'

/** Statuses a session is "working" in; leaving them for `idle` is a finished turn. */
const WORKING = new Set(['busy', 'waiting_permission'])

export function sessionLabel(summary: SessionSummary | undefined, sessionId: string): string {
  if (summary?.name) return summary.name
  if (summary?.preview) return oneLine(summary.preview, 48)
  return sessionId
}

export interface AttentionOptions {
  /** Whether the page is out of sight (a background tab, a minimised window). */
  hidden?: () => boolean
  /** The Notification constructor, when the browser has one (a seam for tests). */
  notification?: () => (typeof Notification) | undefined
}

/**
 * The attention model (Appendix O §7.4): the tab title counts the open
 * questions — `(2) SugarCrush` — and, when the viewer turned notifications
 * on, the browser says so when
 * - a question arrives in a session that is not on screen, or while the page
 *   is out of sight;
 * - a turn finishes (`busy`/`waiting_permission` → `idle`) in a session that
 *   is not on screen, or while the page is out of sight.
 *
 * "On screen" is what the page tells the server it shows (`client.viewing`):
 * the open session in the tabs view, every tile in the grid.
 *
 * @return a function that stops it
 */
export function useAttention(options: AttentionOptions = {}): () => void {
  const approvals = useApprovalsStore()
  const sessions = useSessionsStore()
  const layout = useLayoutStore()
  const settings = useSettingsStore()
  const hidden = options.hidden ?? (() => typeof document !== 'undefined' && document.hidden)
  const notificationApi = options.notification ?? (() => (typeof Notification === 'undefined' ? undefined : Notification))

  function onScreen(sessionId: string): boolean {
    return !hidden() && layout.showing.sessionIds.includes(sessionId)
  }

  function notify(title: string, body: string, tag: string): void {
    const Api = notificationApi()
    if (!settings.notify || Api === undefined || Api.permission !== 'granted') return
    try {
      new Api(title, { body, tag })
    } catch {
      // Some browsers only notify from a service worker; the badge still shows.
    }
  }

  const stopTitle = watch(() => approvals.total, (total) => {
    if (typeof document !== 'undefined') document.title = total > 0 ? `(${total}) ${BRAND}` : BRAND
  }, { immediate: true })

  const stopAsks = approvals.onNew((ask: SessionAsk) => {
    if (onScreen(ask.sessionId)) return
    const where = sessionLabel(sessions.byId[ask.sessionId], ask.sessionId)
    notify(`${ask.tool} is waiting for an answer`, ask.reason ? `${where}: ${ask.reason}` : where, ask.askId)
  })

  // A turn that ends where nobody is looking.
  const seen = new Map<string, string>()
  for (const summary of sessions.items) seen.set(summary.id, summary.status)
  const stopTurns = watch(() => sessions.items.map((summary) => [summary.id, summary.status] as const), (now) => {
    for (const [id, status] of now) {
      const before = seen.get(id)
      seen.set(id, status)
      if (before === undefined || !WORKING.has(before) || status !== 'idle' || onScreen(id)) continue
      notify(`${sessionLabel(sessions.byId[id], id)} finished`, 'The turn is done.', `done:${id}`)
    }
  })

  return () => {
    stopTitle()
    stopAsks()
    stopTurns()
  }
}
