import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { readJson, writeJson } from './storage'

/**
 * How this viewer reads the transcript (C-WEB-4, GAP-UI-22/GAP-UI-32):
 * whether settled thinking folds are opened, and whether every tool card is
 * expanded by default. Kept apart from `stores/settings.ts` on purpose —
 * these are per-view reading switches, not the settings form. Persisted per
 * viewer in localStorage through `stores/storage.ts`, so a remounted (or
 * virtualised-and-recycled) row reads the same answer from this store, and a
 * page reload starts from the stored one.
 */
export const useTranscriptViewStore = defineStore('transcriptView', () => {
  // Strict read: anything but a stored `true` means off, so a corrupt or
  // foreign value falls back to the compact default rather than opening all.
  const showThinking = ref<boolean>(readJson<unknown>('transcriptView.showThinking', false) === true)
  const expandTools = ref<boolean>(readJson<unknown>('transcriptView.expandTools', false) === true)

  watch(showThinking, (on) => writeJson('transcriptView.showThinking', on))
  watch(expandTools, (on) => writeJson('transcriptView.expandTools', on))

  function toggleThinking(): void {
    showThinking.value = !showThinking.value
  }

  function toggleExpandTools(): void {
    expandTools.value = !expandTools.value
  }

  return { showThinking, expandTools, toggleThinking, toggleExpandTools }
})
