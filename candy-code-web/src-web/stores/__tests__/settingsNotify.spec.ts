import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { NOTIFICATION_PROBLEM_TEXT, useSettingsStore } from '../settings'

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

describe('settings store — notification problems (ISS-111)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    stubNotification({ permission: 'default', requestPermission: async () => 'denied' })
    forceSecureContext(true)
  })

  it('names a denied prompt instead of failing silently', async () => {
    const settings = useSettingsStore()
    expect(await settings.enableNotifications()).toBe(false)
    expect(settings.notify).toBe(false)
    expect(settings.notifyProblem).toBe('denied')
    expect(NOTIFICATION_PROBLEM_TEXT.denied).toMatch(/blocked/)
  })

  it('names a plain-HTTP origin without even asking the browser', async () => {
    const request = vi.fn(async () => 'denied' as NotificationPermission)
    stubNotification({ permission: 'default', requestPermission: request })
    forceSecureContext(false)
    const settings = useSettingsStore()
    expect(await settings.enableNotifications()).toBe(false)
    expect(settings.notifyProblem).toBe('insecure')
    expect(request).not.toHaveBeenCalled()
    expect(NOTIFICATION_PROBLEM_TEXT.insecure).toMatch(/HTTPS or localhost/)
  })

  it('names a browser with no Notification object', async () => {
    stubNotification(null)
    const settings = useSettingsStore()
    expect(await settings.enableNotifications()).toBe(false)
    expect(settings.notifyProblem).toBe('unsupported')
  })

  it('clears the problem when the grant lands', async () => {
    const settings = useSettingsStore()
    await settings.enableNotifications()
    expect(settings.notifyProblem).toBe('denied')
    stubNotification({ permission: 'granted', requestPermission: async () => 'granted' })
    expect(await settings.enableNotifications()).toBe(true)
    expect(settings.notifyProblem).toBeNull()
  })
})
