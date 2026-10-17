import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { readJson, writeJson } from '../storage'

export const PANELS = ['agents', 'todo', 'background', 'workflows', 'memory'] as const
export type PanelName = (typeof PANELS)[number]

export const PANEL_LABELS: Record<PanelName, string> = {
  agents: 'Agents',
  todo: 'Todo',
  background: 'Background',
  workflows: 'Workflows',
  memory: 'Memory',
}

/**
 * Which of the session's side panels is showing (roadmap O-6c): the drawer
 * open or shut, its tab, the agent view open in it, and the command palette.
 * The drawer and its tab are per-viewer conveniences kept in localStorage;
 * the session they show is the one on screen.
 */
export const usePanelsStore = defineStore('panels', () => {
  const open = ref<boolean>(readJson<boolean>('panels.open', false))
  const stored = readJson<string>('panels.active', 'agents')
  const active = ref<PanelName>((PANELS as readonly string[]).includes(stored) ? (stored as PanelName) : 'agents')
  /** The session the panels show (the one on screen), set by the drawer. */
  const sessionId = ref<string | null>(null)
  /** The run whose agent view is open in the Agents panel. */
  const agentId = ref<string | null>(null)
  const paletteOpen = ref(false)

  watch(open, (value) => writeJson('panels.open', value))
  watch(active, (value) => writeJson('panels.active', value))
  watch(sessionId, (next, previous) => {
    // A run belongs to its session: another session closes its view.
    if (previous !== null && next !== previous) agentId.value = null
  })

  function toggle(value?: boolean): void {
    open.value = value ?? !open.value
  }

  function show(panel: PanelName): void {
    active.value = panel
    open.value = true
  }

  /** Open run $id's agent view, in the session on screen. */
  function openAgent(id: string): void {
    agentId.value = id
    show('agents')
  }

  function closeAgent(): void {
    agentId.value = null
  }

  function togglePalette(value?: boolean): void {
    paletteOpen.value = value ?? !paletteOpen.value
  }

  return { open, active, sessionId, agentId, paletteOpen, toggle, show, openAgent, closeAgent, togglePalette }
})
