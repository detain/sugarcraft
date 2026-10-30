import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { readJson, writeJson } from './storage'

export type ThemeChoice = 'auto' | 'dark' | 'light'

/**
 * Diff layout preference (C-TUI-2.f, GAP-UI-05): `auto` splits side-by-side
 * once the viewport is wide enough (the threshold is `lib/diff.ts`'s
 * `DIFF_SPLIT_MIN_PX`), `unified` always shows the interleaved stream, `split`
 * always shows two columns. Same vocabulary as the server's own `diffStyle`
 * setting — but this one is the *viewer's*: a client localStorage pref beside
 * the theme, never a settings-schema key.
 */
export type DiffStyleChoice = 'auto' | 'unified' | 'split'

/** Why the last notify attempt failed (ISS-111), so the UI can say so. */
export type NotificationProblem = 'insecure' | 'denied' | 'unsupported'

export const NOTIFICATION_PROBLEM_TEXT: Record<NotificationProblem, string> = {
  insecure: 'notifications need HTTPS or localhost',
  denied: 'notifications blocked by the browser',
  unsupported: 'this browser has no desktop notifications',
}

/**
 * This viewer's UI preferences: the theme and opt-in desktop notifications
 * for questions that arrive while the tab is in the background. The
 * server's own settings (`settings.schema` / `settings.get`) are a separate,
 * schema-driven form: `stores/settings/serverSettings.ts`.
 */
export const useSettingsStore = defineStore('settings', () => {
  const theme = ref<ThemeChoice>(readJson<ThemeChoice>('theme', 'auto'))
  const notify = ref<boolean>(readJson<boolean>('notify', false))
  const diffStyle = ref<DiffStyleChoice>(readJson<DiffStyleChoice>('diffStyle', 'auto'))

  function applyTheme(choice: ThemeChoice): void {
    if (typeof document === 'undefined') return
    if (choice === 'auto') delete document.documentElement.dataset.theme
    else document.documentElement.dataset.theme = choice
  }

  watch(theme, (choice) => {
    writeJson('theme', choice)
    applyTheme(choice)
  }, { immediate: true })

  watch(notify, (on) => writeJson('notify', on))
  watch(diffStyle, (style) => writeJson('diffStyle', style))

  function cycleDiffStyle(): void {
    // An unrecognised restored value takes the first step back to the default.
    diffStyle.value = diffStyle.value === 'auto' ? 'unified' : diffStyle.value === 'unified' ? 'split' : 'auto'
  }

  function cycleTheme(): void {
    theme.value = theme.value === 'auto' ? 'dark' : theme.value === 'dark' ? 'light' : 'auto'
  }

  const notifyProblem = ref<NotificationProblem | null>(null)

  /** Turn notifications on, asking the browser first; false when it refuses —
   *  and `notifyProblem` says why (ISS-111), because a silent no was indistinguishable
   *  from a click that did nothing on a plain-HTTP bind. */
  async function enableNotifications(): Promise<boolean> {
    if (typeof Notification === 'undefined') {
      notifyProblem.value = 'unsupported'
      return false
    }
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      notifyProblem.value = 'insecure'
      return false
    }
    const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
    notify.value = permission === 'granted'
    // A dismissed prompt leaves `default`; in a secure context that is still
    // the browser not saying yes — ISS-111's whole point is naming the reason.
    notifyProblem.value = notify.value ? null : 'denied'
    return notify.value
  }

  return { theme, notify, notifyProblem, cycleTheme, enableNotifications, diffStyle, cycleDiffStyle }
})
