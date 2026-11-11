<script setup lang="ts">
import { computed, onUnmounted, watch } from 'vue'
import { t } from '../../lib/i18n'
import { useAgentsStore } from '../../stores/agents/agents'
import { usePanelsStore } from '../../stores/agents/panels'
import { activeCount, buildTree, foldRuns } from '../../stores/agents/tree'
import { STALL_TICK_MS } from '../../stores/agents/stall'
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

/**
 * CWP-03 (PTV-P01 parity): feed the store's stall detector from this panel's
 * own fold — every beat that lands re-observes at once, and the tick keeps the
 * badge honest through silence, which (unlike the server's per-tick pump) never
 * arrives as an event of its own.
 */
watch(runs, (current) => agents.observeStalls(props.sessionId, current, Date.now()), { immediate: true })
const stallClock = setInterval(() => agents.observeStalls(props.sessionId, runs.value, Date.now()), STALL_TICK_MS)
onUnmounted(() => clearInterval(stallClock))
const stalled = computed(() =>
  Object.entries(agents.stalledRuns[props.sessionId] ?? {}).map(([agentId, seconds]) => ({
    agentId,
    name: runs.value[agentId]?.name || agentId,
    seconds,
  })),
)
/**
 * CW-COVER-B F4: rows the server trimmed off the snapshot roster to hold the
 * display cap. The folded tree below looks complete; this count says it isn't.
 */
const dropped = computed(() => session.value.state.subagentsDropped)
</script>

<template>
  <div class="agents-panel" data-testid="agents-panel">
    <AgentView v-if="picked" :session-id="sessionId" :run="picked" @close="panels.closeAgent()" />
    <template v-else>
      <p class="summary">
        <template v-if="tree.length === 0">{{ t('web.agents.none_yet') }}</template>
        <template v-else>{{ Object.keys(runs).length === 1 ? t('web.agents.runs_one', {n: Object.keys(runs).length}) : t('web.agents.runs_other', {n: Object.keys(runs).length}) }}<template v-if="running > 0">{{ t('web.agents.runs_going', {n: running}) }}</template></template>
        <button type="button" class="link" @click="agents.refresh(sessionId)">{{ t('web.agents.refresh') }}</button>
      </p>
      <p v-if="agents.errors[sessionId]" class="error" role="alert">{{ agents.errors[sessionId] }}</p>
      <p v-if="stalled.length" class="stalled" role="status" data-testid="agent-stall-badges">
        <span v-for="s in stalled" :key="s.agentId" class="stall-chip" data-testid="agent-stall" :title="'⚠ ' + s.name + ' · ' + s.seconds + 's'">⚠ {{ s.name }} · {{ s.seconds }}s</span>
      </p>
      <SubAgentTree :nodes="tree" :selected="panels.agentId" @open="(id: string) => panels.openAgent(id)" />
      <p v-if="dropped > 0" class="dropped" role="status" data-testid="agents-dropped">{{ dropped === 1 ? t('web.agents.dropped_one', { n: dropped }) : t('web.agents.dropped_other', { n: dropped }) }}</p>
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
.stalled {
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  font-size: 0.8125rem;
}
.stall-chip {
  color: var(--error);
  border: 1px solid var(--error);
  border-radius: 999px;
  padding: 0 0.45rem;
}
.dropped {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
}
</style>
