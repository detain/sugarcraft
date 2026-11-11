// C-WEB-5.e: the device-push consent store. The SettingsView toggle is the
// only caller of the consent handshake (`dormantRoster`'s `push.*` rows drop
// with it): permission → service-worker registration →
// `pushManager.subscribe(vapidKey)` → `push.subscribe`, and OFF unwinds both
// sides (browser, then server). The module's other exit,
// `closeNotificationInbox()`, is called from the logout chain in
// `protocol/auth.ts` — no consent, no handshake, only the OS-inbox sweep.
//
// The laws this file keeps:
//   * CONSENT GATES EVERYTHING. No push call — not even the read-only
//     `push.vapidKey` — happens before the toggle was flipped; a fresh store
//     is inert. The toggle's persisted flag rides localStorage `push`; the
//     handshake only runs when the flag flips on.
//   * THE ENDPOINT IS A BEARER SECRET. `push.subscribe` rows let anyone who
//     holds the endpoint+keys POST ciphertext the server can decrypt the
//     response to. The endpoint and `p256dh`/`auth` live in local variables
//     for the duration of the calls and are never written to storage or
//     logged. After a refusal the browser subscription is torn down so no
//     unrecognised row survives on either side.
//   * NO TITLES OPT-IN. `name` is deliberately NOT sent (R13 default): every
//     notification stays the redacted generic text the server composes.
//   * BOUNDED, NEVER RETRIED (E646). One attempt per flip; a failure names
//     its problem and the caller rolls the consent back. A dangling server row
//     after a failed `push.unsubscribe` is self-healing: the welded core drops
//     it at the next 404/410 (C-WEB-5.d law).
//   * HELLO ROSTER GATE (C-TUI-6.f shape): an older server that does not
//     advertise `push.subscribe` is never dialled for it.

import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { useConnectionStore } from './connection'
import { readJson, writeJson } from './storage'

export type PushProblem = 'insecure' | 'unsupported' | 'denied' | 'unavailable' | 'refused'

/** i18n catalogue keys for each problem — rendered through `t()` by the
 *  settings view (chrome strings only; the catalogue lives in candy-code's
 *  lang/en.php and is mirrored by `npm run gen:strings`). */
export const PUSH_PROBLEM_KEYS: Record<PushProblem, string> = {
  insecure: 'web.settings.push_problem_insecure',
  unsupported: 'web.settings.push_problem_unsupported',
  denied: 'web.settings.push_problem_denied',
  unavailable: 'web.settings.push_problem_unavailable',
  refused: 'web.settings.push_problem_refused',
}

function pushRuntimePresent(): boolean {
  return typeof navigator !== 'undefined'
    && 'serviceWorker' in navigator
    && typeof window !== 'undefined'
    && 'PushManager' in window
    && typeof Notification !== 'undefined'
}

/** RFC 8292: the VAPID public key is base64url of the 65-octet P-256 point;
 *  `subscribe()` wants the raw octets. */
function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const normalised = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalised + '='.repeat((4 - (normalised.length % 4)) % 4)
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i)
  return bytes
}

/** Every notification card the app's worker shows carries this tag prefix
 *  (sw.ts composes `candy-code:question|finished|generic[:<sessionId>]`).
 *  Paired by comment with sw.ts, not by import: pulling this module's pinia
 *  graph into the worker bundle would be absurd. The worker-half pins in
 *  `stores/__tests__/push.spec.ts` fix the three shapes this sweep must
 *  recognise. */
const NOTIFICATION_TAG_PREFIX = 'candy-code:'

/**
 * Logout sweep (OWED-CLOSEOUT (ii) of the L-DBA lane): an OS notification
 * inbox outlives the tab that minted it, so after sign-out queued cards can
 * still show the previous principal's session bodies. Close every card this
 * app owns under EVERY service-worker registration of this origin — all of
 * them, because a stale registration from an older deploy path would keep
 * its own inbox otherwise.
 *
 * Best-effort by contract (the surrounding logout chain's idiom): the
 * function never rejects, a broken registration cannot strand the others
 * (per-registration fan-out through allSettled), and cards whose tag is not
 * ours are left alone — a co-registered but unrelated notification is not
 * this logout's to close. Runs at LOGOUT only: login-side rotation
 * (`rotateCacheScope`) has nothing to sweep, since cards are minted by the
 * server for a session the new login does not yet have, and unwinding on
 * sign-in would burn cards the fresh principal may legitimately be handed.
 */
export async function closeNotificationInbox(): Promise<void> {
  try {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return
    const registrations = await navigator.serviceWorker.getRegistrations()
    await Promise.allSettled(registrations.map(async (registration) => {
      const inbox = await registration.getNotifications()
      await Promise.allSettled(inbox
        .filter((card) => typeof card.tag === 'string' && card.tag.startsWith(NOTIFICATION_TAG_PREFIX))
        .map((card) => card.close()))
    }))
  } catch {
    // Fail-soft: logout must never block on the push surface. The consent
    // flag stays as the user left it; the next logout sweeps again.
  }
}

export const usePushStore = defineStore('push', () => {
  const connection = useConnectionStore()

  const consent = ref<boolean>(readJson<boolean>('push', false))
  const problem = ref<PushProblem | null>(null)
  const active = ref(false)

  watch(consent, (on) => writeJson('push', on))

  /** The row is HIDDEN outside a secure context (localhost or C-WEB-5.a TLS)
   *  and on runtimes with no push API at all — mirroring the settings store's
   *  notification guard, but deciding visibility so the toggle is never shown
   *  as an empty promise. */
  const visible = computed(() => pushRuntimePresent()
    && typeof window !== 'undefined' && window.isSecureContext !== false)

  const serverOffersPush = computed(() =>
    (connection.hello?.features?.methods ?? []).includes('push.subscribe'))

  async function registration(): Promise<ServiceWorkerRegistration> {
    const found = await navigator.serviceWorker.getRegistration()
    if (found !== undefined) return found
    // dist ships sw.js at the ROOT next to index.html (vite.config.ts names
    // the chunk sw.js precisely to keep the URL out of the hashed assets).
    // Resolve against the PAGE URL, not import.meta.url: Vite rewrites the
    // `new URL(spec, import.meta.url)` form into an asset reference, which
    // would point the registration at a hashed chunk or a dev source path.
    // Hash routing never changes the pathname, so './sw.js' sits beside the
    // shell the server actually served. Module type: the worker shares the
    // app's i18n bundle for its headings (see sw.ts).
    const url = new URL('./sw.js', window.location.href).href
    return navigator.serviceWorker.register(url, { type: 'module' })
  }

  /** The consented handshake, in the order the browser forces: the VAPID
   *  key must be in hand before `subscribe`, and the endpoint only exists
   *  after it — so `push.subscribe` is necessarily the LAST call. Returns
   *  whether a row is live on the server. */
  async function enable(): Promise<boolean> {
    if (!consent.value) return false // defense-in-depth: the toggle is the gate
    if (!pushRuntimePresent()) {
      problem.value = 'unsupported'
      return false
    }
    if (window.isSecureContext === false) {
      // Never prompt outside a secure context (settings.ts's ISS-111 law).
      problem.value = 'insecure'
      return false
    }
    if (Notification.permission === 'default') await Notification.requestPermission()
    if (Notification.permission !== 'granted') {
      problem.value = 'denied'
      return false
    }
    if (!serverOffersPush.value) {
      problem.value = 'unavailable'
      return false
    }
    problem.value = null
    // Hoisted above the try: the catch below must be able to NAME the
    // subscription even when the throw lands mid-mint. The header law —
    // the browser row is torn down after a refusal — covers the throw
    // edge, not only the explicit-answer edge, and it cannot hold if the
    // handle lives only inside the block that just failed.
    let subscription: PushSubscription | null = null
    try {
      const worker = await registration()
      const { publicKey } = await connection.request('push.vapidKey', {})
      const existing = await worker.pushManager.getSubscription()
      subscription = existing ?? await worker.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64UrlToBytes(publicKey),
      })
      const wire = subscription.toJSON()
      // endpoint/keys: bearer secrets, locals only — never persisted, never
      // logged. A subscription without them cannot dial; fall to the refusal
      // path rather than send an empty-key row the server would reject.
      const endpoint = typeof wire.endpoint === 'string' ? wire.endpoint : ''
      const p256dh = typeof wire.keys?.p256dh === 'string' ? wire.keys.p256dh : ''
      const auth = typeof wire.keys?.auth === 'string' ? wire.keys.auth : ''
      if (endpoint === '' || p256dh === '' || auth === '') {
        await subscription.unsubscribe()
        problem.value = 'refused'
        return false
      }
      const stored = await connection.request('push.subscribe', {
        endpoint,
        keys: { p256dh, auth },
      })
      if (!stored.subscribed) {
        await subscription.unsubscribe()
        problem.value = 'refused'
        return false
      }
      active.value = true
      return true
    } catch {
      // A throw between the mint and the stored answer leaves the browser
      // holding a row the server never accepted: tear it down for the same
      // bearer-hygiene reason as the explicit refusal above. Fail-soft on
      // purpose — a rejection escaping here would skip setConsent's rollback,
      // leaving persisted consent ON over a dead handshake, which is worse
      // than a bounded orphan (the server row self-heals via the core
      // 404/410 drop, C-WEB-5.d). A null handle means the throw landed
      // before any mint, so there is nothing to tear down.
      if (subscription !== null) {
        await subscription.unsubscribe().catch(() => undefined)
      }
      problem.value = 'refused'
      return false
    }
  }

  /** OFF unwinds both sides — browser first (delivery stops immediately),
   *  then the server row. A failed server call is not retried and not
   *  swallowed in silence by consent: the row dies at the core's next
   *  404/410 drop (C-WEB-5.d), which is the bounded endgame. */
  async function disable(): Promise<void> {
    active.value = false
    try {
      const worker = await navigator.serviceWorker.getRegistration()
      const subscription = worker === undefined ? null : await worker.pushManager.getSubscription()
      if (subscription === null) return
      const endpoint = subscription.endpoint // bearer: local only
      await subscription.unsubscribe()
      await connection.request('push.unsubscribe', { endpoint }).catch(() => ({ unsubscribed: false }))
    } catch {
      // Nothing to retry; consent is already off and the UI says so.
    }
  }

  /** The toggle's single entry point. Flipping on runs `enable()` and ROLLS
   *  the flag back when any gate refuses, so the persisted consent always
   *  matches a live subscription. */
  async function setConsent(on: boolean): Promise<boolean> {
    consent.value = on
    if (!on) {
      await disable()
      problem.value = null
      return false
    }
    const ok = await enable()
    if (!ok) consent.value = false
    return ok
  }

  /**
   * CW-DBA-8: boot-time RE-VALIDATION of a persisted consent. The toggle can
   * read ON from storage while nothing behind it is live — permission
   * revoked, browser profile wiped, or simply a reload that outlived the
   * server-side row. The flip law says "persisted consent always matches a
   * live subscription"; this keeps that promise across reloads.
   *
   * The boot path NEVER prompts for permission (enable()'s requestPermission
   * line stays unreachable from here — a prompt on load is the nag the
   * header forbids). A missing grant means the stored consent is a stale
   * promise: roll it back to OFF so the toggle tells the truth and the next
   * flip is a fresh user act. The environmental gates (no runtime, insecure,
   * hello not yet offering push) KEEP consent — they are not the user's
   * revocation; the watch below spends exactly one deferred attempt when the
   * offer lands. One attempt per trigger, never silently retried (E646, the
   * header law).
   */
  async function reconcile(): Promise<void> {
    if (!consent.value || active.value) return
    if (!pushRuntimePresent()) {
      problem.value = 'unsupported'
      return
    }
    if (window.isSecureContext === false) {
      problem.value = 'insecure'
      return
    }
    if (Notification.permission !== 'granted') {
      problem.value = 'denied'
      consent.value = false
      return
    }
    if (!serverOffersPush.value) {
      problem.value = 'unavailable'
      return
    }
    const ok = await enable()
    if (!ok) consent.value = false
  }

  // CW-DBA-8: when hello first offers push.subscribe, a consent that survived
  // the boot gates gets its one deferred attempt — no polling, no retry loop.
  watch(serverOffersPush, (on) => {
    if (on && consent.value && !active.value) void reconcile()
  })

  return { consent, problem, active, visible, serverOffersPush, setConsent, reconcile }
})
