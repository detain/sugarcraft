<script setup lang="ts">
import { computed } from 'vue'
import { oneLine } from '../../lib/format'
import { useAgentsStore } from '../../stores/agents/agents'
import { usePanelsStore } from '../../stores/agents/panels'
import { buildTree, foldRuns, type AgentRun } from '../../stores/agents/tree'
import { useSessionStore } from '../../stores/session'

/**
 * The working line (P-AGENT.d, OpenClaw's "N agents running" countdown and
 * launch rows): while the session has delegated runs going, one line under
 * the transcript counts them and lists each one's live activity. A row opens
 * that run's agent view, exactly like the tree's rows do. Sits in the
 * transcript's `#after` slot, so it reads as the tail of the conversation.
 */
const props = defineProps<{ sessionId: string }>()

const agents = useAgentsStore()
const panels = usePanelsStore()
const session = computed(() => useSessionStore(props.sessionId))

/** Every run still going, at any depth, in tree order. */
const running = computed<AgentRun[]>(() => {
  const runs = foldRuns(agents.seed(props.sessionId), Object.values(session.value.state.subagents))
  const found: AgentRun[] = []
  const walk = (nodes: ReturnType<typeof buildTree>): void => {
    for (const node of nodes) {
      if (node.run.status === 'running' || node.run.status === 'queued') found.push(node.run)
      walk(node.children)
    }
  }
  walk(buildTree(Object.values(runs)))
  return found
})

function activity(run: AgentRun): string {
  const call = run.calls[run.calls.length - 1]
  if (call && run.status === 'running') return oneLine(call.label, 100)
  return oneLine(run.task || run.description, 100)
}
</script>

<template>
  <section v-if="running.length > 0" class="running-line" data-testid="running-agents-line">
    <p class="head" data-testid="running-agents-count">⠋ {{ running.length }} agent{{ running.length === 1 ? '' : 's' }} running</p>
    <ul class="rows">
      <li v-for="run in running" :key="run.id">
        <button
          type="button"
          class="row"
          :title="run.task || run.name"
          data-testid="running-agents-row"
          :data-agent-id="run.id"
          @click="panels.openAgent(run.id)"
        >
          <span class="name">{{ run.name }}</span>
          <span class="activity">{{ activity(run) }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.running-line {
  display: grid;
  gap: 0.2rem;
  padding: 0.35rem 0.5rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--bg);
  min-width: 0;
}
.head {
  margin: 0;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--accent);
}
.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.1rem;
}
.row {
  display: flex;
  gap: 0.5rem;
  width: 100%;
  text-align: left;
  padding: 0.1rem 0.2rem;
  background: none;
  border: none;
  font: inherit;
  font-size: 0.8125rem;
  color: var(--text);
  min-width: 0;
  cursor: pointer;
}
.row:hover {
  color: var(--accent);
}
.name {
  font-weight: 600;
  white-space: nowrap;
}
.activity {
  color: var(--muted);
  font-family: var(--mono);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
</style>
