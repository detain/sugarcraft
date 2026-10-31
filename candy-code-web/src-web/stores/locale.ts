// C-TUI-6.f: one locale fetch per handshake.
//
// The store listens for the handshake and asks `server.strings` for the
// chrome bundle in the browser's language — but ONLY when the server's own
// advertised method roster offers it, so an older server is never dialed
// for an unknown method. A failed fetch keeps English: chrome strings are
// presentation, and nothing here may take the UI down.
//
// App.vue instantiates the store before the first handshake can land (the
// same "created up front" discipline as the sessions store), and the
// overlay in lib/i18n is reactive — a late answer repaints the chrome
// without a reload.

import { defineStore } from 'pinia'
import { resetWebStrings, servedLocale, setWebStrings } from '../lib/i18n'
import { useConnectionStore } from './connection'

function browserLocale(): string {
  const nav = globalThis.navigator as { language?: string } | undefined
  const tag = nav?.language ?? ''
  return tag === '' ? 'en' : tag
}

export const useLocaleStore = defineStore('locale', () => {
  const connection = useConnectionStore()

  const unsubscribe = connection.onHello((hello) => {
    const methods: string[] = hello.features?.methods ?? []
    if (!methods.includes('server.strings')) {
      // Pre-C-TUI-6.f server (or a scope that cannot see the method): the
      // committed English is the right answer.
      return
    }
    void connection
      .request('server.strings', { locale: browserLocale() })
      .then((result) => {
        setWebStrings(result.strings)
        servedLocale.value = result.locale
      })
      .catch(() => {
        // Fail soft toward English, never toward a blank chrome — and the
        // locale label rides the same reset (see lib/i18n).
        resetWebStrings()
      })
  })

  return { unsubscribe }
})
