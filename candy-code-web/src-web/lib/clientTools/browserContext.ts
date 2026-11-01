import type { ClientToolImplementation } from '../../stores/clientTools'

/**
 * The one client-hosted tool the web app ships by default (C-TOOL-6.i): the
 * model's view of the browser it is being talked to from.
 *
 * Everything here is observable from any page without asking — the same facts
 * `navigator` hands out to any script. Nothing private, nothing stored: the
 * point is that the server cannot see the viewport, the OS time zone or the
 * colour-scheme preference, and those are exactly what a UI-aware assistant
 * needs. The gate (`server.clientTools`) plus the per-connection registration
 * is what lets it through; the user's tab decides nothing beyond that.
 */
export const browserContext: ClientToolImplementation = {
  name: 'browser_context',
  description: 'this browser\'s language, time zone, viewport size, device pixel ratio, colour-scheme preference and online state — nothing private',
  inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  timeoutSeconds: 10,
  run: () => {
    let timeZone: string | undefined
    try {
      timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone
    } catch {
      timeZone = undefined
    }
    return {
      locale: navigator.language,
      timeZone: timeZone ?? null,
      viewport: { width: window.innerWidth, height: window.innerHeight },
      devicePixelRatio: window.devicePixelRatio,
      colorScheme: window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
      online: navigator.onLine,
    }
  },
}
