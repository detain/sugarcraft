<script setup lang="ts">
import { money, oneLine, tokens } from '../../lib/format'
import { statusIcon, type AgentNode, type AgentRun } from '../../stores/agents/tree'

/**
 * Delegated runs as a tree (roadmap O-6c, Appendix O §7.4 "sub-agent tree"):
 * one row per run with its live activity — status, what it was asked, its
 * step, tokens and spend, the tool call it is on — and the runs it delegated
 * to below it. Recursive. A row opens the run's agent view.
 */
defineProps<{ nodes: AgentNode[]; selected?: string | null; compact?: boolean }>()
const emit = defineEmits<{ open: [agentId: string] }>()

function what(run: AgentRun): string {
  return oneLine(run.description || run.task, 140)
}

function activity(run: AgentRun): string {
  const call = run.calls[run.calls.length - 1]
  if (run.status === 'running' && call) return oneLine(call.label, 100)
  if (run.error) return oneLine(run.error, 140)
  return oneLine(run.tail.split('\n').filter((line) => line.trim() !== '').pop() ?? '', 100)
}
</script>

<template>
  <ul class="tree" role="tree" data-testid="agent-tree">
    <li v-for="node in nodes" :key="node.run.id" role="treeitem" :aria-expanded="node.children.length > 0 ? true : undefined">
      <button
        type="button"
        class="row"
        :class="[node.run.status, { on: node.run.id === selected }]"
        data-testid="agent-node"
        :data-agent-id="node.run.id"
        :data-status="node.run.status"
        :title="node.run.task || node.run.name"
        @click="emit('open', node.run.id)"
      >
        <span class="icon" aria-hidden="true">{{ statusIcon(node.run.status) }}</span>
        <span class="name">{{ node.run.name }}</span>
        <span class="what">{{ what(node.run) }}</span>
        <span v-if="!compact" class="figures">
          <template v-if="node.run.maxSteps > 0">step {{ node.run.step }}/{{ node.run.maxSteps }} · </template>{{ tokens(node.run.tokens) }} tok<template v-if="node.run.cost > 0"> · {{ money(node.run.cost) }}</template>
        </span>
        <span v-if="activity(node.run)" class="activity" data-testid="agent-activity">{{ activity(node.run) }}</span>
      </button>
      <SubAgentTree v-if="node.children.length > 0" class="nested" :nodes="node.children" :selected="selected" :compact="compact" @open="(id: string) => emit('open', id)" />
    </li>
  </ul>
</template>

<style scoped>
.tree {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}
.nested {
  margin: 0.25rem 0 0 0.9rem;
  padding-left: 0.6rem;
  border-left: 1px solid var(--line);
}
.row {
  display: grid;
  grid-template-columns: 1.2em auto 1fr;
  column-gap: 0.4rem;
  row-gap: 0.1rem;
  width: 100%;
  text-align: left;
  padding: 0.3rem 0.5rem;
  background: var(--bg);
  min-width: 0;
}
.row.on {
  border-color: var(--accent);
}
.icon {
  grid-row: span 2;
}
.running .icon {
  color: var(--accent);
}
.done .icon {
  color: var(--ok);
}
.failed .icon,
.cancelled .icon {
  color: var(--error);
}
.queued .icon,
.empty .icon {
  color: var(--muted);
}
.name {
  font-weight: 600;
  white-space: nowrap;
}
.what {
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.figures,
.activity {
  grid-column: 2 / 4;
  font-size: 0.8125rem;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.activity {
  font-family: var(--mono);
}
</style>
