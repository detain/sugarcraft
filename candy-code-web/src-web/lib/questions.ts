import { onScopeDispose, ref, type Ref } from 'vue'
import { sessionLabel } from '../components/approvals/attention'
import { useApprovalsStore, type SessionAsk } from '../stores/approvals'
import { useSessionsStore } from '../stores/sessions'
import { oneLine } from './format'

/**
 * WEB-7a: the ARIA live region for question arrivals. A finished reply is
 * already announced (SessionView's `reply-announcement`, ISS-108) and an
 * out-of-sight question already reaches the desktop (attention.ts) — but a
 * screen reader user looking AT the page heard nothing when a permission
 * prompt or an AskUser question landed. This module composes the sentence;
 * App.vue renders the region so every session's questions announce from the
 * app root, wherever the viewer currently is.
 */

/** What kind of question this is, in words a listener can parse once. */
export function askKind(ask: SessionAsk): string {
  const source = ask.source ?? 'gate'
  if (source.startsWith('tool:')) return 'question'
  if (source.startsWith('hook:')) return 'hook approval'
  return 'permission request'
}

/**
 * The announcement for one newly arrived question: where it came from, which
 * tool asks, which kind it is, and why (the same reason the card shows).
 */
export function questionAnnouncement(ask: SessionAsk, where: string): string {
  const reason = ask.reason !== undefined && ask.reason.trim() !== '' ? `: ${oneLine(ask.reason, 80)}` : ''
  return `${where} — ${ask.tool} ${askKind(ask)} is waiting for an answer${reason}`
}

/**
 * The text of the live region: empty until a question arrives, then the
 * newest one's sentence (an aria-live region announces each replacement).
 * Hangs off `approvals.onNew` — the same edge the desktop notification uses,
 * so event-driven arrivals and `permission.pending` backfills announce alike.
 * Must be called inside a component/effect scope; it unsubscribes on dispose.
 */
export function useQuestionAnnouncements(): Readonly<Ref<string>> {
  const approvals = useApprovalsStore()
  const sessions = useSessionsStore()
  const announcement = ref('')
  const stop = approvals.onNew((ask: SessionAsk) => {
    announcement.value = questionAnnouncement(ask, sessionLabel(sessions.byId[ask.sessionId], ask.sessionId))
  })
  onScopeDispose(stop)
  return announcement
}
