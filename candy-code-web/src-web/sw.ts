// C-WEB-5.e: the device-push service worker, emitted at the dist root by
// `vite.config.ts` and registered ONLY from `stores/push.ts`, behind the
// consent toggle. It never dials the server itself — the page owns every RPC;
// this file only receives what the push service already delivered.
//
// The payload is UNTRUSTED server data crossing a network service: the shape
// is fixed ({kind, body, sessionId}), every field is coerced or dropped, and
// the notification's heading comes from the committed chrome catalogue, never
// from the wire. A notification body is plain text, so there is no markup
// channel here at all; the control-character sweep keeps a hostile body from
// smuggling escape-adjacent runes into the OS inbox, and the length clamp
// bounds what a buggy sender can shout. No token, endpoint or key is ever
// read or stored on this side.

import { t } from './lib/i18n'

/** The payload `ServerContext::pushNotify()` writes; `title` rides only rows
 *  that opted into `name` (C-WEB-5.d), which this client never requests — the
 *  field is ignored even if an older server or a fork sends it. */
interface PushWirePayload {
  kind: 'question' | 'finished'
  body: string
  sessionId: string
}

/** Structural view of the worker globals. Declared locally rather than
 *  through `/// <reference lib="webworker" />`, which collides with the DOM
 *  lib the rest of the app typechecks against (`self` cannot be both). */
interface MessageDataLike {
  text(): string | null
}

interface WaitableEventLike extends Event {
  waitUntil(settled: Promise<unknown>): void
}

interface PushEventLike extends WaitableEventLike {
  readonly data: MessageDataLike | null
}

interface NotificationLike {
  close(): void
  readonly data?: unknown
}

interface NotificationClickEventLike extends WaitableEventLike {
  readonly notification: NotificationLike
}

interface WindowClientLike {
  focused: boolean
  focus(): Promise<unknown>
  postMessage(message: unknown): void
}

interface ClientsLike {
  matchAll(options?: { type?: string }): Promise<readonly WindowClientLike[]>
  openWindow(url: string): Promise<unknown>
}

interface ShowNotificationOptions {
  body?: string
  tag?: string
  data?: unknown
}

interface ServiceWorkerScopeLike {
  addEventListener(type: 'push', listener: (event: PushEventLike) => void): void
  addEventListener(type: 'notificationclick', listener: (event: NotificationClickEventLike) => void): void
  readonly clients?: ClientsLike
  readonly location: { readonly href: string }
  readonly registration: {
    showNotification(title: string, options?: ShowNotificationOptions): Promise<void>
  }
}

const scope = self as unknown as ServiceWorkerScopeLike

/** Ids reach a URL fragment on click; anything outside the session-route
 *  alphabet is treated as no id at all. Same shape the server mints. */
const SESSION_ID = /^[A-Za-z0-9_-]{1,64}$/

/** C0/C1 controls, DEL and the zero-width + bidi formats a crafted body
 *  could hide escape-adjacent runes or RTL trickery in (202A-202E embeds and
 *  overrides, 2066-2069 the isolates, ZW-family 200B-200F, line/paste breaks
 *  2028-2029, BOM FEFF). */
const INVISIBLES = /[\u0000-\u001f\u007f-\u009f\u200b-\u200f\u2028\u2029\u202a-\u202e\u2066-\u2069\ufeff]/gu

const BODY_MAX = 300

function cleanText(value: unknown): string {
  if (typeof value !== 'string') return ''
  return value.replace(INVISIBLES, ' ').trim().slice(0, BODY_MAX)
}

function payloadOf(raw: string | null): PushWirePayload | null {
  if (raw === null) return null
  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return null
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return null
  const record = parsed as Record<string, unknown>
  const kind = record.kind === 'question' || record.kind === 'finished' ? record.kind : null
  const body = cleanText(record.body)
  const candidate = typeof record.sessionId === 'string' ? record.sessionId : ''
  const sessionId = SESSION_ID.test(candidate) ? candidate : ''
  // A payload that yields nothing typed, nothing bounded and no id has no
  // safe content to show — the callers fall back to the generic heading.
  if (kind === null && body === '' && sessionId === '') return null
  return { kind: kind ?? 'question', body, sessionId }
}

function titleFor(payload: PushWirePayload | null): string {
  if (payload === null) return t('web.chrome.push.generic')
  return payload.kind === 'question' ? t('web.chrome.push.question') : t('web.chrome.push.finished')
}

function sessionHref(payload: PushWirePayload | null): string {
  // Hash router (router.ts): the session route is a fragment, so a push click
  // can never ask the server for a path it has no file for. The id survived
  // the SESSION_ID screen before it was ever woven into the fragment.
  return payload !== null && payload.sessionId !== '' ? `#/s/${payload.sessionId}` : './'
}

scope.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    const payload = payloadOf(event.data?.text() ?? null)
    const tag = payload === null
      ? 'candy-code:generic'
      : payload.sessionId === '' ? `candy-code:${payload.kind}` : `candy-code:${payload.kind}:${payload.sessionId}`
    // `tag` collapses repeats into one card, so a busy server cannot
    // carpet-bomb the inbox; `data` carries the pre-sanitised href the click
    // handler will honour — the raw payload never reaches it.
    await scope.registration.showNotification(titleFor(payload), {
      body: payload?.body ?? '',
      tag,
      data: { href: sessionHref(payload) },
    })
  })())
})

scope.addEventListener('notificationclick', (event) => {
  event.waitUntil((async () => {
    event.notification.close()
    const clients = scope.clients
    if (clients === undefined) return
    const stored = (event.notification.data ?? {}) as { href?: unknown }
    const href = typeof stored.href === 'string' && (stored.href === './' || /^#\/s\/[A-Za-z0-9_-]{1,64}$/.test(stored.href))
      ? stored.href
      : './'
    const focused = (await clients.matchAll({ type: 'window' })).find((client) => client.focused)
    if (focused !== undefined) {
      await focused.focus()
      // CW-6: focus alone leaves the app standing wherever it was, so a click
      // on a "question in session X" card could land on an unrelated view.
      // Hand the already-validated fragment to the page over the client
      // channel — the same route a fresh window gets through the URL. './'
      // means "the app is already in front of you": its current route wins
      // and no message is posted.
      if (href !== './') focused.postMessage({ type: 'navigate', href })
      return
    }
    await clients.openWindow(new URL(href, scope.location.href).href)
  })())
})
