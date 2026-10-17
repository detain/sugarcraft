<script setup lang="ts">
import { computed, watch } from 'vue'
import { useAgentsStore } from '../../stores/agents/agents'
import { usePanelsStore } from '../../stores/agents/panels'
import { activeCount, buildTree, foldRuns } from '../../stores/agents/tree'
import { useSessionStore } from '../../stores/session'
import AgentView from './AgentView.vue'
import SubAgentTree from './SubAgentTree.vue'

/**
 * The Agents panel (roadmap O-6c): every run the session delegated, as a
 * live tree — the runs the server knows (`agents.subtree`, which includes
 * those finished before this tab followed the session) folded with the beats
 * the session's events are bringing now — and, for the run picked, its agent
 * view.
 */
const props = defineProps<{ sessionId: string }>()

const agents = useAgentsStore()
const panels = usePanelsStore()
const session = computed(() => useSessionStore(props.sessionId))

const runs = computed(() => foldRuns(agents.seed(props.sessionId), Object.values(session.value.state.subagents)))
const tree = computed(() => buildTree(Object.values(runs.value)))
const running = computed(() => activeCount(tree.value))
const picked = computed(() => (panels.agentId === null ? null : runs.value[panels.agentId] ?? null))

watch(() => props.sessionId, (id) => void agents.refresh(id), { immediate: true })
</script>

<template>
  <div class="agents-panel" data-testid="agents-panel">
    <AgentView v-if="picked" :session-id="sessionId" :run="picked" @close="panels.closeAgent()" />
    <template v-else>
      <p class="summary">
        <template v-if="tree.length === 0">No delegated runs in this session yet.</template>
        <template v-else>{{ Object.keys(runs).length }} run{{ Object.keys(runs).length === 1 ? '' : 's' }}<template v-if="running > 0">, {{ running }} going</template></template>
        <button type="button" class="link" @click="agents.refresh(sessionId)">refresh</button>
      </p>
      <p v-if="agents.errors[sessionId]" class="error" role="alert">{{ agents.errors[sessionId] }}</p>
      <SubAgentTree :nodes="tree" :selected="panels.agentId" @open="(id: string) => panels.openAgent(id)" />
    </template>
  </div>
</template>

<style scoped>
.agents-panel {
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.summary {
  margin: 0;
  color: var(--muted);
  font-size: 0.875rem;
  display: flex;
  gap: 0.5rem;
  justify-content: space-between;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
</style>
