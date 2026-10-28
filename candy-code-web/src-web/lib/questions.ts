import { nextTick, onScopeDispose, ref, watch, type Ref } from 'vue'
import { sessionLabel } from '../components/approvals/attention'
import { useApprovalsStore, type SessionAsk } from '../stores/approvals'
import { useContextSourcesStore } from '../stores/contextSources'
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
  const context = useContextSourcesStore()
  const announcement = ref('')
  /**
   * WEB-7-P1: a live region only speaks when its text CHANGES. Writing the
   * composed sentence straight onto the ref means two identical asks (the same
   * session, tool and reason — common for a re-asked permission) leave the
   * region's text untouched and the screen reader silent. Clearing then
   * re-filling on the next tick forces a mutation every time, so each arrival
   * — even a byte-identical repeat — is announced.
   */
  function speak(sentence: string): void {
    announcement.value = ''
    void nextTick(() => {
      announcement.value = sentence
    })
  }
  const stop = approvals.onNew((ask: SessionAsk) => {
    speak(questionAnnouncement(ask, sessionLabel(sessions.byId[ask.sessionId], ask.sessionId)))
  })
  // WEB-7-P1 (trust prompts): a `kind:"trust"` ask is filtered out before
  // `approvals.onNew` (stores/approvals.ts), so it never announces there. The
  // Context panel's store shelves them in `pendingTrusts` (C-SEC-4.b, listed
  // per door by C-SEC-5.b); watch that shelf and announce each open row once,
  // keyed by askId — a re-delivery of a row already spoken, or a second door
  // opening beside it, never re-announces the first. This is read-only on the store.
  const announcedTrustAskIds = new Set<string>()
  const stopTrust = watch(
    () => context.pendingTrusts,
    (rows) => {
      for (const trust of rows) {
        if (trust.status !== 'asked') continue
        if (announcedTrustAskIds.has(trust.askId)) continue
        announcedTrustAskIds.add(trust.askId)
        speak(`${sessionLabel(sessions.byId[trust.sessionId], trust.sessionId)} — project trust prompt for ${trust.root} is waiting in the Context panel`)
      }
    },
  )
  onScopeDispose(() => {
    stop()
    stopTrust()
  })
  return announcement
}
