import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { readJson, writeJson } from './storage'

export type ThemeChoice = 'auto' | 'dark' | 'light'

/**
 * This viewer's UI preferences: the theme and opt-in desktop notifications
 * for questions that arrive while the tab is in the background. The
 * server's own settings (`settings.schema` / `settings.get`) are a separate,
 * schema-driven form that lands with O-6b.
 */
export const useSettingsStore = defineStore('settings', () => {
  const theme = ref<ThemeChoice>(readJson<ThemeChoice>('theme', 'auto'))
  const notify = ref<boolean>(readJson<boolean>('notify', false))

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

  function cycleTheme(): void {
    theme.value = theme.value === 'auto' ? 'dark' : theme.value === 'dark' ? 'light' : 'auto'
  }

  /** Turn notifications on, asking the browser first; false when it refuses. */
  async function enableNotifications(): Promise<boolean> {
    if (typeof Notification === 'undefined') return false
    const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
    notify.value = permission === 'granted'
    return notify.value
  }

  return { theme, notify, cycleTheme, enableNotifications }
})
