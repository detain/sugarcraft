import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { readJson, writeJson } from './storage'

const DRAFT_LIMIT = 50

/**
 * How this viewer arranged the page: the sidebar drawer (narrow screens), the
 * last session opened, and an unsent draft per session. Persisted per viewer
 * in localStorage (Appendix O §7.5); multi-pane layouts arrive with O-6a.
 */
export const useLayoutStore = defineStore('layout', () => {
  const sidebarOpen = ref(false)
  const lastSessionId = ref<string | null>(readJson<string | null>('lastSession', null))
  const drafts = ref<Record<string, string>>(readJson<Record<string, string>>('drafts', {}))

  watch(lastSessionId, (id) => writeJson('lastSession', id))

  function draft(sessionId: string): string {
    return drafts.value[sessionId] ?? ''
  }

  function setDraft(sessionId: string, text: string): void {
    const next = { ...drafts.value }
    if (text === '') delete next[sessionId]
    else next[sessionId] = text
    // Bound the map: the oldest drafts go first.
    const keys = Object.keys(next)
    for (const key of keys.slice(0, Math.max(0, keys.length - DRAFT_LIMIT))) delete next[key]
    drafts.value = next
    writeJson('drafts', next)
  }

  function toggleSidebar(open?: boolean): void {
    sidebarOpen.value = open ?? !sidebarOpen.value
  }

  return { sidebarOpen, lastSessionId, drafts, draft, setDraft, toggleSidebar }
})
