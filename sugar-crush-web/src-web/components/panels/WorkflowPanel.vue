<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useWorkflowsStore, parseVars, type WorkflowRun } from '../../stores/agents/workflows'
import { useConnectionStore } from '../../stores/connection'
import { describe, useSessionStore } from '../../stores/session'
import MessageMarkdown from '../MessageMarkdown.vue'

/**
 * Workflows (roadmap O-6c): start one in the session on screen — a run is
 * the session's turn, exactly as `/workflow run` — and the session's runs,
 * the `/workflow` ones and the model's own `Workflow` tool calls alike,
 * newest first, each with its report; a paused one resumes from here, a
 * running one pauses before its next stage.
 */
const props = defineProps<{ sessionId: string }>()

const workflows = useWorkflowsStore()
const connection = useConnectionStore()
const name = ref('')
const vars = ref('')
const error = ref<string | null>(null)
const opened = ref<number | null>(null)
const busy = computed(() => useSessionStore(props.sessionId).busy)
const canWrite = computed(() => connection.scopes.includes('write'))
const runs = computed(() => [...workflows.runsFor(props.sessionId)].reverse())

watch(() => props.sessionId, (id) => void workflows.loadRuns(id), { immediate: true })
watch(() => workflows.names, (names) => {
  if (name.value === '' && names.length > 0) name.value = names[0] ?? ''
}, { immediate: true })

async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await action()
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function start(): Promise<void> {
  const parsed = parseVars(vars.value)
  if (typeof parsed === 'string') {
    error.value = parsed
    return
  }
  await act(async () => {
    await workflows.run(props.sessionId, name.value, parsed)
    vars.value = ''
  })
}

function label(run: WorkflowRun): string {
  return run.name || (run.source === 'tool' ? 'model plan' : 'workflow')
}
</script>

<template>
  <div class="workflow-panel" data-testid="workflow-panel">
    <p v-if="!workflows.available" class="empty">No workflow engine in this workspace.</p>
    <form v-else-if="canWrite" class="start" @submit.prevent="start">
      <select v-model="name" aria-label="Workflow" data-testid="workflow-name" :disabled="workflows.names.length === 0">
        <option v-if="workflows.names.length === 0" value="">no workflows defined</option>
        <option v-for="workflow in workflows.names" :key="workflow" :value="workflow">{{ workflow }}</option>
      </select>
      <input v-model="vars" type="text" placeholder="key=value …" aria-label="Context" data-testid="workflow-vars">
      <button type="submit" class="primary" :disabled="name === '' || busy" :title="busy ? 'A turn is running in this session' : ''" data-testid="workflow-run">Run</button>
    </form>
    <p v-if="error || workflows.error" class="error" role="alert" data-testid="workflow-error">{{ error || workflows.error }}</p>
    <p v-if="runs.length === 0" class="empty">No workflow runs in this session.</p>
    <ul class="runs">
      <li v-for="(run, i) in runs" :key="run.toolCallId ?? run.workflowId ?? i" :data-status="run.status" :data-source="run.source" data-testid="workflow-runitem">
        <button type="button" class="head" :aria-expanded="opened === i" @click="opened = opened === i ? null : i">
          <span class="status" :class="run.status">{{ run.status }}</span>
          <span class="name">{{ label(run) }}</span>
          <span class="source">{{ run.source === 'tool' ? 'Workflow tool' : '/workflow' }}</span>
        </button>
        <div v-if="opened === i" class="body">
          <MessageMarkdown v-if="run.report" :text="run.report" />
          <p v-else class="empty">Running…</p>
          <div v-if="canWrite && run.workflowId" class="actions">
            <button v-if="run.status === 'paused'" type="button" :disabled="busy" @click="act(() => workflows.resume(sessionId, run.workflowId!))">Resume</button>
          </div>
        </div>
        <div v-if="canWrite && run.running && run.workflowId" class="actions">
          <button type="button" @click="act(() => workflows.pause(run.workflowId!))">Pause</button>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.workflow-panel {
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.start {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
select,
input {
  font: inherit;
  padding: 0.3rem 0.5rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
  min-width: 0;
}
input {
  flex: 1;
}
.empty {
  margin: 0;
  color: var(--muted);
  font-size: 0.875rem;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
.runs {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
}
.head {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.5rem;
  width: 100%;
  text-align: left;
  background: var(--bg);
}
.status {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: var(--accent);
}
.status.completed {
  color: var(--ok);
}
.status.failed,
.status.cancelled {
  color: var(--error);
}
.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.source {
  font-size: 0.75rem;
  color: var(--muted);
}
.body {
  padding: 0.4rem 0.2rem;
  font-size: 0.875rem;
}
.actions {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.3rem;
}
</style>
