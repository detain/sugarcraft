<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { useBackgroundStore } from '../../stores/agents/background'
import { PANEL_LABELS, PANELS, usePanelsStore, type PanelName } from '../../stores/agents/panels'
import { activeCount, buildTree, foldRuns } from '../../stores/agents/tree'
import { useAgentsStore } from '../../stores/agents/agents'
import { useSessionStore } from '../../stores/session'
import AgentsPanel from '../agents/AgentsPanel.vue'
import BackgroundPanel from './BackgroundPanel.vue'
import MemoryPanel from './MemoryPanel.vue'
import TodoPanel from './TodoPanel.vue'
import WorkflowPanel from './WorkflowPanel.vue'

/**
 * The session's side panels (roadmap O-6c, Appendix O §7.4): a drawer over
 * the right edge with a tab each for the delegated runs, the todo list, the
 * background sessions, workflows and memory. The panels that belong to a
 * session show the one on screen; background sessions and memory belong to
 * the workspace and show anywhere.
 */
const props = defineProps<{ sessionId: string | null }>()

const panels = usePanelsStore()
const background = useBackgroundStore()
const agents = useAgentsStore()
const SESSION_PANELS: PanelName[] = ['agents', 'todo', 'workflows']

watch(() => props.sessionId, (id) => {
  panels.sessionId = id
}, { immediate: true })

const needsSession = computed(() => SESSION_PANELS.includes(panels.active) && props.sessionId === null)

/** Runs still going in the session on screen, for the Agents tab's badge. */
const agentsGoing = computed(() => {
  if (props.sessionId === null) return 0
  const session = useSessionStore(props.sessionId)
  return activeCount(buildTree(Object.values(foldRuns(agents.seed(props.sessionId), Object.values(session.state.subagents)))))
})

function badge(panel: PanelName): number {
  if (panel === 'agents') return agentsGoing.value
  if (panel === 'background') return background.running
  return 0
}

// On a wide screen the drawer takes a column of its own instead of covering
// the transcript and the composer (see the unscoped style below).
watch(() => panels.open, (open) => {
  if (typeof document !== 'undefined') document.documentElement.classList.toggle('sc-panels-open', open)
}, { immediate: true })
onBeforeUnmount(() => {
  if (typeof document !== 'undefined') document.documentElement.classList.remove('sc-panels-open')
})

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && panels.open) {
    if (panels.agentId !== null) panels.closeAgent()
    else panels.toggle(false)
  }
}
</script>

<template>
  <button
    v-if="!panels.open"
    type="button"
    class="opener"
    data-testid="panels-toggle"
    title="Agents, todo, background, workflows, memory"
    @click="panels.toggle(true)"
  >panels<span v-if="agentsGoing + background.running > 0" class="dot">{{ agentsGoing + background.running }}</span></button>
  <aside v-else class="drawer" aria-label="Session panels" data-testid="side-panels" :data-panel="panels.active" @keydown="onKey">
    <div class="tabs" role="tablist">
      <button
        v-for="panel in PANELS"
        :key="panel"
        type="button"
        role="tab"
        :aria-selected="panels.active === panel"
        :class="{ on: panels.active === panel }"
        :data-testid="`panel-tab-${panel}`"
        @click="panels.show(panel)"
      >{{ PANEL_LABELS[panel] }}<span v-if="badge(panel) > 0" class="dot">{{ badge(panel) }}</span></button>
      <button type="button" class="close link" aria-label="Close the panels" data-testid="panels-close" @click="panels.toggle(false)">✕</button>
    </div>
    <div class="body" role="tabpanel">
      <p v-if="needsSession" class="empty">Open a session to see its {{ PANEL_LABELS[panels.active].toLowerCase() }}.</p>
      <AgentsPanel v-else-if="panels.active === 'agents' && sessionId" :session-id="sessionId" />
      <TodoPanel v-else-if="panels.active === 'todo' && sessionId" :session-id="sessionId" />
      <WorkflowPanel v-else-if="panels.active === 'workflows' && sessionId" :session-id="sessionId" />
      <BackgroundPanel v-else-if="panels.active === 'background'" :session-id="sessionId" />
      <MemoryPanel v-else-if="panels.active === 'memory'" />
    </div>
  </aside>
</template>

<style scoped>
.opener {
  position: fixed;
  top: 3.4rem;
  right: 0.75rem;
  z-index: 20;
  font-size: 0.8125rem;
  padding: 0.2rem 0.6rem;
  box-shadow: 0 0.2rem 0.6rem rgb(0 0 0 / 0.25);
}
.drawer {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  width: var(--panels-width);
  display: flex;
  flex-direction: column;
  background: var(--panel);
  border-left: 1px solid var(--line);
  box-shadow: -0.4rem 0 1rem rgb(0 0 0 / 0.3);
}
.tabs {
  display: flex;
  gap: 0.25rem;
  padding: 0.5rem;
  border-bottom: 1px solid var(--line);
  overflow-x: auto;
  flex: none;
}
.tabs button {
  font-size: 0.8125rem;
  padding: 0.2rem 0.5rem;
  white-space: nowrap;
}
.tabs .on {
  border-color: var(--accent);
  color: var(--accent);
}
.close {
  margin-left: auto;
  padding: 0 0.3rem;
  text-decoration: none;
}
.body {
  flex: 1;
  overflow-y: auto;
  padding: 0.75rem;
  min-height: 0;
}
.empty {
  margin: 0;
  color: var(--muted);
}
.dot {
  display: inline-block;
  margin-left: 0.35rem;
  min-width: 1.2em;
  padding: 0 0.3em;
  border-radius: 999px;
  background: var(--accent);
  color: var(--bg);
  font-size: 0.75rem;
  text-align: center;
}
</style>

<style>
:root {
  --panels-width: min(28rem, 100vw);
}
@media (min-width: 1100px) {
  html.sc-panels-open body {
    padding-right: var(--panels-width);
  }
}
</style>
