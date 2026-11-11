import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SettingsView from '../../components/settings/SettingsView.vue'
import { usePushStore } from '../push'

/**
 * C-WEB-5.e: the device-push consent store and the push service worker.
 *
 * Pinned behaviours (design-declared):
 *   1. NO SUBSCRIBE WITHOUT CONSENT — a freshly created store is inert: no
 *      registration, no `push.vapidKey` read, no `push.subscribe` dial.
 *   2. SUBSCRIBE → RPC ORDER — on consent: register → vapidKey → browser
 *      subscribe → push.subscribe; the endpoint can only exist after the
 *      browser call, so the wire dial is necessarily last; `name` is never
 *      sent (R13: redacted payloads).
 *   3. OFF UNSUBSCRIBES BOTH SIDES — browser subscription first, then
 *      `push.unsubscribe` with the local endpoint.
 *   4. HIDDEN INSECURE — outside a secure context nothing dials and the
 *      Settings row is absent; a consent flip rolls back.
 * Plus the bearer hygiene law: the endpoint never reaches localStorage, and
 * the worker's payload handling (heading from the catalogue, hostile text
 * swept, hrefs re-validated) — the sw block dispatches real events.
 *
 * The connection module is replaced wholesale (importActual-spread keeps its
 * other exports for SettingsView's unrelated imports), so every RPC the
 * store attempts is recorded in order without a socket.
 */

interface FakeNotification {
  permission: NotificationPermission
  requestPermission: () => Promise<NotificationPermission>
}

function stubNotification(fake: FakeNotification | null): void {
  if (fake === null) Reflect.deleteProperty(globalThis, 'Notification')
  else Object.defineProperty(globalThis, 'Notification', { value: fake, configurable: true, writable: true })
}

function forceSecureContext(secure: boolean): void {
  Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true })
}

/** Per-process sentinel: the bearer-absence scans must never match a
 *  hard-coded string that could leak into fixtures. */
const ENDPOINT = 'https://push.example/inbox/' + process.pid + '.' + Math.random().toString(36).slice(2, 10)
const PKEY = 'pkey-' + process.pid
const AKEY = 'auth-' + process.pid

/** 65 octets (an uncompressed P-256 point) as base64url, how the server
 *  ships `push.vapidKey`. */
function fakeVapidPublicKey(): string {
  const bytes = Uint8Array.from({ length: 65 }, (_, i) => (i === 0 ? 0x04 : i))
  let binary = ''
  bytes.forEach((b) => { binary += String.fromCharCode(b) })
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

interface Recorder {
  calls: string[]
  rpc: Array<{ method: string; params: Record<string, unknown> }>
  methods: string[]
  vapidPublicKey: string
  subscribedResult: { subscribed: boolean; keyHash: string }
  /** Arm the dial mock to THROW (not return) on this RPC name — the
   *  F-n44 pins need the throw-after-mint edge, not a refusal answer. */
  throwOn: string | null
}

const recorder = vi.hoisted(() => {
  return {
    calls: [] as string[],
    rpc: [] as Array<{ method: string; params: Record<string, unknown> }>,
    methods: ['push.vapidKey', 'push.subscribe', 'push.unsubscribe'] as string[],
    vapidPublicKey: '',
    subscribedResult: { subscribed: true, keyHash: 'kh-test' },
    throwOn: null as string | null,
  } as Recorder
})

vi.mock('../connection', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('../connection')
  const connection = {
    scopes: [] as string[],
    connected: false,
    onHello: () => (): void => {},
    get hello(): { features: { methods: string[] } } | null {
      return { features: { methods: recorder.methods } }
    },
    request: async (method: string, params: Record<string, unknown>) => {
      recorder.calls.push(method)
      recorder.rpc.push({ method, params })
      if (method === recorder.throwOn) throw new Error('push.spec armed throw: ' + method)
      if (method === 'push.vapidKey') return { publicKey: recorder.vapidPublicKey }
      if (method === 'push.subscribe') return recorder.subscribedResult
      if (method === 'push.unsubscribe') return { unsubscribed: true }
      throw new Error('push.spec saw an unexpected RPC: ' + method)
    },
  }
  return { ...actual, useConnectionStore: () => connection }
})

/** Browser-side half of the push runtime (service worker + PushManager).
 *  `runtimeCalls` interleaves with recorder.calls via mark() to pin the
 *  browser→server order the browser itself forces. */
let runtimeCalls: string[] = []
let delivered = false
let registered = false
let registerArgs: { url: string; options?: { type?: string } } | null = null
/** When true the fake subscription's unsubscribe() REJECTS after marking —
 *  the F-n44 teardown-fails edge (the fix must stay fail-soft). */
let unsubscribeRejects = false

function installPushRuntime(): void {
  runtimeCalls = []
  delivered = false
  registered = false
  registerArgs = null
  unsubscribeRejects = false
  const subscription = {
    endpoint: ENDPOINT,
    toJSON: () => ({ endpoint: ENDPOINT, keys: { p256dh: PKEY, auth: AKEY } }),
    unsubscribe: async () => {
      mark('browser-unsubscribe')
      if (unsubscribeRejects) throw new Error('push.spec armed teardown failure')
      return true
    },
  }
  const pushManager = {
    getSubscription: async () => (delivered ? subscription : null),
    subscribe: async (options: { userVisibleOnly: boolean; applicationServerKey?: Uint8Array }) => {
      mark('browser-subscribe')
      keyLength = options.applicationServerKey?.byteLength ?? -1
      delivered = true
      return subscription
    },
  }
  const registration = { pushManager }
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: {
      getRegistration: async () => (registered ? registration : undefined),
      register: async (url: string, options?: { type?: string }) => {
        mark('register')
        registered = true
        registerArgs = { url, options }
        return registration
      },
    },
  })
  Object.defineProperty(window, 'PushManager', { configurable: true, value: class FakePushManager {} })
  stubNotification({ permission: 'granted', requestPermission: async () => 'granted' })
  forceSecureContext(true)
}

let keyLength = -1

function mark(label: string): void {
  // One timeline: RPC names and browser-side events in arrival order.
  recorder.calls.push(label)
}

function allStoredValues(): string {
  let joined = ''
  for (let i = 0; i < globalThis.localStorage.length; i += 1) {
    const key = globalThis.localStorage.key(i)
    joined += (key ?? '') + '=' + (globalThis.localStorage.getItem(key ?? '') ?? '') + ';'
  }
  return joined
}

beforeEach(() => {
  setActivePinia(createPinia())
  globalThis.localStorage.clear()
  recorder.calls = []
  recorder.rpc = []
  recorder.methods = ['push.vapidKey', 'push.subscribe', 'push.unsubscribe']
  recorder.vapidPublicKey = fakeVapidPublicKey()
  recorder.subscribedResult = { subscribed: true, keyHash: 'kh-test' }
  recorder.throwOn = null
  keyLength = -1
})

describe('push store (C-WEB-5.e)', () => {
  it('is inert until consent: creating the store dials nothing', async () => {
    installPushRuntime()
    const push = usePushStore()
    await Promise.resolve()
    expect(push.consent).toBe(false)
    expect(push.visible).toBe(true)
    expect(recorder.calls).toEqual([])
    expect(runtimeCalls).toEqual([])
    expect(registerArgs).toBeNull()
    // Even the persisted record of "never flipped" must not carry secrets.
    expect(allStoredValues()).not.toContain(ENDPOINT)
  })

  it('flips on: register → vapidKey → browser subscribe → push.subscribe, in that order', async () => {
    installPushRuntime()
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(true)
    expect(recorder.calls).toEqual(['register', 'push.vapidKey', 'browser-subscribe', 'push.subscribe'])
    expect(registerArgs?.url).toMatch(/sw\.js$/)
    expect(registerArgs?.options).toEqual({ type: 'module' })
    expect(keyLength).toBe(65)
    const dial = recorder.rpc.find((entry) => entry.method === 'push.subscribe')
    expect(dial?.params).toEqual({ endpoint: ENDPOINT, keys: { p256dh: PKEY, auth: AKEY } })
    // R13: `name` never rides this client — payload stays redacted server-side.
    expect(dial?.params).not.toHaveProperty('name')
    expect(push.consent).toBe(true)
    expect(push.active).toBe(true)
    expect(push.problem).toBeNull()
  })

  it('never re-subscribes a live browser subscription it can reuse', async () => {
    installPushRuntime()
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(true)
    recorder.calls = []
    recorder.rpc = []
    expect(await push.setConsent(true)).toBe(true)
    expect(recorder.calls).toEqual(['push.vapidKey', 'push.subscribe'])
  })

  it('flips off: the browser subscription dies first, then the server row', async () => {
    installPushRuntime()
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(true)
    recorder.calls = []
    recorder.rpc = []
    expect(await push.setConsent(false)).toBe(false)
    expect(recorder.calls).toEqual(['browser-unsubscribe', 'push.unsubscribe'])
    expect(recorder.rpc[0]?.params).toEqual({ endpoint: ENDPOINT })
    expect(push.consent).toBe(false)
    expect(push.active).toBe(false)
    expect(push.problem).toBeNull()
  })

  it('stays hidden-insecure outside a secure context and rolls the flip back', async () => {
    installPushRuntime()
    forceSecureContext(false)
    const push = usePushStore()
    expect(push.visible).toBe(false)
    expect(await push.setConsent(true)).toBe(false)
    expect(push.consent).toBe(false)
    expect(push.problem).toBe('insecure')
    // Nothing was dialled — not even the read-only key — and the browser was
    // never prompted (settings.ts's ISS-111 law, re-applied at the push door).
    expect(recorder.calls).toEqual([])
    expect(runtimeCalls).toEqual([])
    expect(allStoredValues()).toContain('false') // persisted consent follows the rollback
  })

  it('names a server that does not offer push without dialling it', async () => {
    installPushRuntime()
    recorder.methods = ['session.list']
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(false)
    expect(push.problem).toBe('unavailable')
    expect(push.consent).toBe(false)
    expect(recorder.calls).toEqual([])
  })

  it('names a denied permission before any registration happens', async () => {
    installPushRuntime()
    stubNotification({ permission: 'default', requestPermission: async () => 'denied' })
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(false)
    expect(push.problem).toBe('denied')
    expect(recorder.calls).toEqual([])
    expect(runtimeCalls).toEqual([])
  })

  it('names a runtime without push support and hides the row', async () => {
    installPushRuntime()
    Reflect.deleteProperty(window, 'PushManager')
    const push = usePushStore()
    expect(push.visible).toBe(false)
    expect(await push.setConsent(true)).toBe(false)
    expect(push.problem).toBe('unsupported')
    expect(recorder.calls).toEqual([])
  })

  it('tears the browser subscription down when the server refuses the row', async () => {
    installPushRuntime()
    recorder.subscribedResult = { subscribed: false, keyHash: '' }
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(false)
    expect(push.problem).toBe('refused')
    expect(push.consent).toBe(false)
    expect(recorder.calls).toEqual(['register', 'push.vapidKey', 'browser-subscribe', 'push.subscribe', 'browser-unsubscribe'])
  })

  it('tears the browser subscription down when the subscribe dial THROWS after minting', async () => {
    // F-n44 (B-71): the header law — "after a refusal the browser
    // subscription is torn down" — must cover the THROW edge, not just the
    // answered refusal. A rejecting `push.subscribe` previously escaped to a
    // catch that could not name the try-scoped subscription, leaving the
    // browser holding a row the server never accepted.
    installPushRuntime()
    recorder.throwOn = 'push.subscribe'
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(false)
    expect(push.problem).toBe('refused')
    expect(push.consent).toBe(false)
    // Exactly one teardown attempt, after the mint and the failed dial.
    expect(recorder.calls).toEqual(['register', 'push.vapidKey', 'browser-subscribe', 'push.subscribe', 'browser-unsubscribe'])
  })

  it('stays fail-soft when the throw-path teardown itself rejects', async () => {
    // The catch's teardown must not swallow setConsent's consent rollback:
    // a rejected unsubscribe() still resolves the flip to false with the
    // flag rolled back (an escaping error would leave persisted consent ON
    // over a dead handshake — strictly worse than the bounded orphan the
    // core's next 404/410 drop already cleans, C-WEB-5.d).
    installPushRuntime()
    unsubscribeRejects = true
    recorder.throwOn = 'push.subscribe'
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(false)
    expect(push.problem).toBe('refused')
    expect(push.consent).toBe(false)
    expect(recorder.calls.filter((entry) => entry === 'browser-unsubscribe')).toHaveLength(1)
  })

  it('never lets the bearer endpoint reach storage', async () => {
    installPushRuntime()
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(true)
    expect(await push.setConsent(false)).toBe(false)
    const stored = allStoredValues()
    expect(stored).not.toContain(ENDPOINT)
    expect(stored).not.toContain(PKEY)
    expect(stored).not.toContain(AKEY)
  })

  it('reinstates consent from localStorage on the next page', async () => {
    installPushRuntime()
    const first = usePushStore()
    await first.setConsent(true)
    setActivePinia(createPinia())
    const second = usePushStore()
    expect(second.consent).toBe(true)
  })
})

/**
 * CW-DBA-8: the boot path re-validates a persisted consent instead of
 * trusting it. The store instance a fresh page creates reads consent ON from
 * localStorage with `active` false — the revalidation decides whether the ON
 * was still true. (The hello watch that re-runs this when the offer lands
 * cannot be exercised here: the connection mock's `hello` is a plain getter,
 * so the computed never invalidates — the watch's presence is pinned in
 * pwa.spec.ts and its callback body IS reconcile(), tested directly below.
 * The real app's store reads the reactive hello and does fire it.)
 */
describe('boot-time consent re-validation (CW-DBA-8)', () => {
  /** A live consent dialled by the first store, then the pinia is dropped:
   *  the next usePushStore() is what boot sees — persisted ON, nothing live. */
  async function seedPersistedConsent(): Promise<void> {
    installPushRuntime()
    const first = usePushStore()
    expect(await first.setConsent(true)).toBe(true)
    setActivePinia(createPinia())
    recorder.calls = []
    recorder.rpc = []
  }

  it('dials nothing when no consent was ever given', async () => {
    installPushRuntime()
    const push = usePushStore()
    await push.reconcile()
    expect(push.consent).toBe(false)
    expect(recorder.calls).toEqual([])
  })

  it('revalidates a live consent by re-using the browser subscription', async () => {
    await seedPersistedConsent()
    const push = usePushStore()
    expect(push.consent).toBe(true)
    await push.reconcile()
    expect(recorder.calls).toEqual(['push.vapidKey', 'push.subscribe'])
    expect(push.active).toBe(true)
    expect(push.problem).toBeNull()
    expect(push.consent).toBe(true)
  })

  it('rolls a stale consent back without a grant and never prompts at boot', async () => {
    await seedPersistedConsent()
    const prompted: string[] = []
    stubNotification({
      permission: 'default',
      requestPermission: async () => { prompted.push('boot'); return 'granted' },
    })
    const push = usePushStore()
    await push.reconcile()
    expect(prompted).toEqual([])
    expect(push.problem).toBe('denied')
    expect(push.consent).toBe(false)
    expect(recorder.calls).toEqual([])
    await new Promise((resolve) => { setTimeout(resolve, 0) })
    expect(allStoredValues()).toContain('false')
  })

  it('rolls a stale consent back when the permission was revoked', async () => {
    await seedPersistedConsent()
    const prompted: string[] = []
    stubNotification({
      permission: 'denied',
      requestPermission: async () => { prompted.push('boot'); return 'denied' },
    })
    const push = usePushStore()
    await push.reconcile()
    expect(prompted).toEqual([])
    expect(push.problem).toBe('denied')
    expect(push.consent).toBe(false)
    expect(recorder.calls).toEqual([])
  })

  it('keeps consent through a missing runtime — not the user revoking', async () => {
    await seedPersistedConsent()
    Reflect.deleteProperty(window, 'PushManager')
    const push = usePushStore()
    await push.reconcile()
    expect(push.problem).toBe('unsupported')
    expect(push.consent).toBe(true)
    expect(recorder.calls).toEqual([])
  })

  it('keeps consent through an insecure context', async () => {
    await seedPersistedConsent()
    forceSecureContext(false)
    const push = usePushStore()
    await push.reconcile()
    expect(push.problem).toBe('insecure')
    expect(push.consent).toBe(true)
    expect(recorder.calls).toEqual([])
  })

  it('keeps consent while hello has not offered push yet', async () => {
    await seedPersistedConsent()
    recorder.methods = ['session.list']
    const push = usePushStore()
    await push.reconcile()
    expect(push.problem).toBe('unavailable')
    expect(push.consent).toBe(true)
    expect(recorder.calls).toEqual([])
  })

  it('is a no-op once a live subscription exists', async () => {
    installPushRuntime()
    const push = usePushStore()
    expect(await push.setConsent(true)).toBe(true)
    recorder.calls = []
    await push.reconcile()
    expect(recorder.calls).toEqual([])
  })

  it('rolls consent back when the server refuses the row', async () => {
    await seedPersistedConsent()
    recorder.subscribedResult = { subscribed: false, keyHash: '' }
    const push = usePushStore()
    await push.reconcile()
    expect(push.problem).toBe('refused')
    expect(push.consent).toBe(false)
    expect(recorder.calls).toEqual(['push.vapidKey', 'push.subscribe', 'browser-unsubscribe'])
  })
})

describe('SettingsView push row (C-WEB-5.e)', () => {
  it('shows the toggle in a secure context with push support', async () => {
    installPushRuntime()
    const wrapper = mount(SettingsView)
    expect(wrapper.find('[data-testid="push-section"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="push-note"]').text()).toContain('generic text')
    const toggle = wrapper.find<HTMLInputElement>('[data-testid="push-toggle"]')
    expect(toggle.element.checked).toBe(false)
    await toggle.setValue(true)
    await new Promise((resolve) => { setTimeout(resolve, 0) })
    // The toggle IS the gate: nothing was dialled until the change event fired.
    expect(recorder.calls).toContain('push.vapidKey')
    expect(usePushStore().consent).toBe(true)
  })

  it('is absent outside a secure context', () => {
    installPushRuntime()
    forceSecureContext(false)
    const wrapper = mount(SettingsView)
    expect(wrapper.find('[data-testid="push-section"]').exists()).toBe(false)
  })

  it('is absent when the runtime has no push API', () => {
    installPushRuntime()
    Reflect.deleteProperty(window, 'PushManager')
    const wrapper = mount(SettingsView)
    expect(wrapper.find('[data-testid="push-section"]').exists()).toBe(false)
  })
})

/**
 * The worker half: sw.ts attaches its listeners to `self` at module load.
 * In a real browser self===the worker global; under happy-dom the spec pins
 * `self` to window first, then dispatches real events at it — the module's
 * listener bodies run unmodified, which is what makes the sanitising below
 * load-bearing rather than decorative.
 */
describe('push service worker payload handling (C-WEB-5.e)', () => {
  interface ShownOptions { body?: string; tag?: string; data?: unknown }
  interface Shown { title: string; options?: ShownOptions }
  let shown: Shown[] = []
  let opened: string[] = []
  let focusedWindow: { focused: boolean; focus: () => Promise<unknown>; postMessage: (message: unknown) => void } | null = null
  let posted: unknown[] = []
  let closedCount = 0

  async function loadWorker(): Promise<void> {
    if (globalThis.self === undefined) Object.defineProperty(globalThis, 'self', { value: window, configurable: true })
    shown = []
    opened = []
    focusedWindow = null
    posted = []
    closedCount = 0
    Object.defineProperty(window, 'registration', {
      configurable: true,
      value: {
        showNotification: async (title: string, options?: ShownOptions) => {
          shown.push({ title, options })
        },
      },
    })
    Object.defineProperty(window, 'clients', {
      configurable: true,
      value: {
        matchAll: async () => (focusedWindow === null ? [] : [focusedWindow]),
        openWindow: async (url: string) => { opened.push(url); return null },
      },
    })
    await import('../../sw')
  }

  async function dispatchPush(payload: string | null): Promise<void> {
    const event = new Event('push')
    Object.defineProperty(event, 'data', { value: payload === null ? undefined : { text: () => payload } })
    let settled: Promise<unknown> = Promise.resolve()
    Object.defineProperty(event, 'waitUntil', { value: (promise: Promise<unknown>) => { settled = promise } })
    window.dispatchEvent(event)
    await settled
  }

  async function dispatchClick(notificationData: unknown): Promise<void> {
    const event = new Event('notificationclick')
    Object.defineProperty(event, 'notification', {
      value: {
        data: notificationData,
        close: () => { closedCount += 1 },
      },
    })
    let settled: Promise<unknown> = Promise.resolve()
    Object.defineProperty(event, 'waitUntil', { value: (promise: Promise<unknown>) => { settled = promise } })
    window.dispatchEvent(event)
    await settled
  }

  it('raises the catalogued question heading with the body and a routed href', async () => {
    await loadWorker()
    await dispatchPush(JSON.stringify({ kind: 'question', body: 'The run awaits your answer', sessionId: 's-42_x' }))
    expect(shown.length).toBe(1)
    expect(shown[0]?.title).toBe('A question needs you')
    expect(shown[0]?.options?.body).toBe('The run awaits your answer')
    expect(shown[0]?.options?.tag).toBe('candy-code:question:s-42_x')
    expect(shown[0]?.options?.data).toEqual({ href: '#/s/s-42_x' })
  })

  it('ignores a server-sent title and sweeps hostile body text', async () => {
    await loadWorker()
    const hostile = JSON.stringify({
      kind: 'finished',
      body: '\u202e\u0000<i>done</i>\u0000' + 'x'.repeat(400),
      sessionId: '../etc/passwd',
      title: 'SESSION-SECRET-TITLE',
    })
    await dispatchPush(hostile)
    expect(shown.length).toBe(1)
    // R13 redaction law: even a server that sends `title` gets the catalogue.
    expect(shown[0]?.title).toBe('Turn finished')
    expect(shown[0]?.title).not.toContain('SECRET')
    const body = shown[0]?.options?.body ?? ''
    expect(body).not.toMatch(/[\u0000-\u001f\u202a-\u202e]/)
    expect(body.length).toBeLessThanOrEqual(300)
    expect(body).toContain('<i>done</i>') // plain text: markup has no channel to render through
    // A forged id never becomes a URL — it degrades to the app root.
    expect(shown[0]?.options?.data).toEqual({ href: './' })
    expect(shown[0]?.options?.tag).toBe('candy-code:finished')
  })

  it('falls back to the generic heading on malformed payloads', async () => {
    await loadWorker()
    await dispatchPush('not json at all')
    await dispatchPush(JSON.stringify({}))
    await dispatchPush(JSON.stringify(['kind', 'question']))
    await dispatchPush(null)
    expect(shown.length).toBe(4)
    shown.forEach((entry) => {
      expect(entry.title).toBe('CandyCode needs your attention')
      expect(entry.options?.body).toBe('')
      expect(entry.options?.tag).toBe('candy-code:generic')
      expect(entry.options?.data).toEqual({ href: './' })
    })
  })

  it('refuses a bare hostile kind: null payload, generic heading and tag', async () => {
    // FIX-1 (REVIEW-1 MINOR-1): the kind allowlist at sw.ts:105 must be
    // observable. A kind outside {question, finished} that rides no body and
    // no id is the WHOLE payload, so the guard's presence is what makes this
    // dispatch the null-payload shape. Widen the test to any string and the
    // hostile kind survives: the heading flips to the finished fall-through
    // and the raw kind — control character included — rides the dedupe tag
    // `candy-code:${kind}`, splitting one collapse-card into one card per
    // hostile string. These two asserts are the discriminating pair.
    await loadWorker()
    await dispatchPush(JSON.stringify({ kind: 'evil\u0001x' }))
    expect(shown.length).toBe(1)
    expect(shown[0]?.title).toBe('CandyCode needs your attention')
    expect(shown[0]?.options?.tag).toBe('candy-code:generic')
    expect(shown[0]?.options?.tag).not.toContain('evil')
  })

  it('coerces a hostile kind beside a body to the question card', async () => {
    // Same guard, other branch: kind is dropped but the payload still shows
    // (there is safe content to show), so `kind ?? 'question'` decides the
    // heading and the tag. Under a widened kind test this card would be
    // titled 'finished' and tagged with the hostile string; under the real
    // allowlist the tag collapses exactly like a legitimate question row.
    await loadWorker()
    await dispatchPush(JSON.stringify({ kind: 'evil\u0001x', body: 'a body', sessionId: 'sess-7' }))
    expect(shown.length).toBe(1)
    expect(shown[0]?.title).toBe('A question needs you')
    expect(shown[0]?.options?.tag).toBe('candy-code:question:sess-7')
    expect(shown[0]?.options?.body).toBe('a body')
  })

  it('prefers a server-composed localized heading over the EN catalogue (CW-12)', async () => {
    // The worker has no locale channel — its t() is seeded EN — so every push
    // heading read English whatever the session owner spoke. A non-empty
    // `localizedTitle` (the same catalogue heading rendered server-side in the
    // owner's language) now wins; the card's routing power stays id-derived.
    await loadWorker()
    await dispatchPush(JSON.stringify({
      kind: 'question',
      body: 'Die Antwort fehlt',
      sessionId: 's-42_x',
      localizedTitle: 'Eine Frage braucht dich',
    }))
    expect(shown.length).toBe(1)
    expect(shown[0]?.title).toBe('Eine Frage braucht dich')
    expect(shown[0]?.options?.body).toBe('Die Antwort fehlt')
    expect(shown[0]?.options?.tag).toBe('candy-code:question:s-42_x')
    expect(shown[0]?.options?.data).toEqual({ href: '#/s/s-42_x' })
  })

  it('sweeps and clamps a hostile localizedTitle like the body (CW-12)', async () => {
    // The field rides the same untrusted wire as `body`: escape-adjacent runes
    // are out, the heading budget is hard, and what survives is EXACTLY the
    // cleaned value — no fall-through to the catalogue, no silent drop.
    await loadWorker()
    await dispatchPush(JSON.stringify({
      kind: 'finished',
      body: 'ok',
      sessionId: 's1',
      localizedTitle: '\u202e\u0000' + 'x'.repeat(400),
    }))
    expect(shown.length).toBe(1)
    const title = shown[0]?.title ?? ''
    expect(title).not.toMatch(/[\u0000-\u001f\u202a-\u202e]/)
    expect(title.length).toBeLessThanOrEqual(80)
    expect(title).toBe('x'.repeat(80))
  })

  it('falls back to the catalogue when localizedTitle is empty or not a string (CW-12)', async () => {
    // The precedence leg must be gated on a NON-EMPTY cleaned value: a server
    // that sends '' (or 42, or null) may not blank or garble the heading.
    await loadWorker()
    await dispatchPush(JSON.stringify({ kind: 'question', body: 'x', sessionId: 's1', localizedTitle: '' }))
    await dispatchPush(JSON.stringify({ kind: 'question', body: 'x', sessionId: 's1', localizedTitle: 42 }))
    await dispatchPush(JSON.stringify({ kind: 'question', body: 'x', sessionId: 's1', localizedTitle: null }))
    expect(shown.map((entry) => entry.title)).toEqual([
      'A question needs you', 'A question needs you', 'A question needs you',
    ])
  })

  it('opens the stored route on click, and only a route', async () => {
    await loadWorker()
    await dispatchClick({ href: '#/s/s-42_x' })
    expect(closedCount).toBe(1)
    expect(opened.length).toBe(1)
    expect(opened[0]?.endsWith('#/s/s-42_x')).toBe(true)
  })

  it('refuses a forged click href', async () => {
    await loadWorker()
    await dispatchClick({ href: 'javascript:alert(1)' })
    expect(opened.length).toBe(1)
    expect(opened[0]).not.toContain('javascript:')
    expect(opened[0]?.endsWith('/')).toBe(true)
  })

  it('focuses an already-focused window instead of opening another', async () => {
    await loadWorker()
    let focusedCalls = 0
    focusedWindow = {
      focused: true,
      focus: async () => { focusedCalls += 1 },
      postMessage: (message: unknown) => { posted.push(message) },
    }
    await dispatchClick({ href: '#/s/s-42_x' })
    expect(focusedCalls).toBe(1)
    expect(opened.length).toBe(0)
  })

  it('hands the session route to the focused page instead of only focusing it (CW-6)', async () => {
    // The defect: the focused branch focused and returned, so a click on a
    // "question in session X" card left the app standing on whatever route
    // it happened to hold. The validated fragment must cross to the page.
    await loadWorker()
    let focusedCalls = 0
    focusedWindow = {
      focused: true,
      focus: async () => { focusedCalls += 1 },
      postMessage: (message: unknown) => { posted.push(message) },
    }
    await dispatchClick({ href: '#/s/s-42_x' })
    expect(focusedCalls).toBe(1)
    expect(posted).toEqual([{ type: 'navigate', href: '#/s/s-42_x' }])
    expect(opened.length).toBe(0)
  })

  it('posts nothing when the card says "the app is already in front" (CW-6)', async () => {
    // './' is the generic/forged-href landing: the page's current route wins,
    // focus is the whole answer, and a stray navigate would yank a viewer
    // out of a session they deliberately opened.
    await loadWorker()
    focusedWindow = {
      focused: true,
      focus: async () => {},
      postMessage: (message: unknown) => { posted.push(message) },
    }
    await dispatchClick({ href: './' })
    expect(posted).toEqual([])
    await dispatchClick({ href: 'javascript:alert(1)' })
    expect(posted).toEqual([])
  })
})
