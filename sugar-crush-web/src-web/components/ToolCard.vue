<script setup lang="ts">
import { computed, ref } from 'vue'
import { diffStats, parseUnifiedDiff } from '../lib/diff'
import { duration, keyArgument } from '../lib/format'
import type { SubAgent, ToolItem } from '../stores/reducer'
import DiffView from './DiffView.vue'
import ReasoningFold from './ReasoningFold.vue'

const props = defineProps<{ item: ToolItem; subagents?: SubAgent[] }>()
const emit = defineEmits<{ loadFull: [item: ToolItem] }>()

// Collapsed when it succeeded, as the TUI shows it; a failure or a refusal opens.
const open = ref(props.item.status === 'error' || props.item.status === 'denied')

const summary = computed(() => keyArgument(props.item.arguments) || props.item.description || '')
const stats = computed(() => (props.item.diff ? diffStats(parseUnifiedDiff(props.item.diff)) : null))
const icon = computed(() => {
  switch (props.item.status) {
    case 'running':
      return '◌'
    case 'done':
      return '✔'
    case 'denied':
      return '⊘'
    case 'interrupted':
      return '■'
    default:
      return '✖'
  }
})
const argsJson = computed(() => JSON.stringify(props.item.arguments, null, 2))
</script>

<template>
  <article class="tool" :class="item.status" :data-status="item.status" data-testid="tool-card">
    <button type="button" class="head" :aria-expanded="open" @click="open = !open">
      <span class="icon" aria-hidden="true">{{ icon }}</span>
      <span class="name">{{ item.name }}</span>
      <span class="arg">{{ summary }}</span>
      <span v-if="stats" class="stats"><span class="plus">+{{ stats.added }}</span> <span class="minus">−{{ stats.removed }}</span></span>
      <span class="time">{{ duration(item.durationMs) }}</span>
    </button>
    <ReasoningFold v-if="item.reasoning" :text="item.reasoning" />
    <div v-if="open" class="body">
      <p v-if="item.denial" class="denial">Refused ({{ item.denial.kind }}){{ item.denial.reason ? `: ${item.denial.reason}` : '' }}</p>
      <details class="args">
        <summary>arguments</summary>
        <pre>{{ argsJson }}</pre>
      </details>
      <DiffView v-if="item.diff" :diff="item.diff" />
      <pre v-if="item.content !== undefined && item.content !== ''" class="output" data-testid="tool-output">{{ item.content }}</pre>
      <button v-if="item.truncated" type="button" class="more" @click="emit('loadFull', item)">Load the full output</button>
      <ul v-if="subagents && subagents.length > 0" class="agents">
        <li v-for="agent in subagents" :key="agent.id">
          <strong>{{ agent.name ?? agent.id }}</strong> · {{ agent.op }}<span v-if="agent.task"> · {{ agent.task }}</span>
        </li>
      </ul>
    </div>
  </article>
</template>

<style scoped>
.tool {
  border: 1px solid var(--line);
  border-radius: 6px;
  margin: 0 0 0.5rem;
  background: var(--bg);
}
.head {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  width: 100%;
  padding: 0.4rem 0.6rem;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  min-width: 0;
}
.icon {
  width: 1.2em;
  flex: none;
}
.done .icon {
  color: var(--ok);
}
.error .icon,
.denied .icon {
  color: var(--error);
}
.running .icon {
  color: var(--accent);
  animation: spin 1.2s linear infinite;
  display: inline-block;
}
.name {
  font-weight: 600;
  flex: none;
}
.arg {
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  flex: 1;
}
.stats {
  font-family: var(--mono);
  font-size: 0.8125rem;
  flex: none;
}
.plus {
  color: var(--ok);
}
.minus {
  color: var(--error);
}
.time {
  color: var(--muted);
  font-size: 0.8125rem;
  flex: none;
}
.body {
  padding: 0 0.6rem 0.6rem;
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.denial {
  margin: 0;
  color: var(--error);
  font-weight: 600;
}
.denied {
  border-color: var(--error);
}
.args summary {
  cursor: pointer;
  color: var(--muted);
  font-size: 0.8125rem;
}
pre {
  margin: 0;
  padding: 0.5rem;
  max-height: 24rem;
  overflow: auto;
  font-family: var(--mono);
  font-size: 0.8125rem;
  background: var(--panel);
  border-radius: 4px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.more {
  justify-self: start;
}
.agents {
  margin: 0;
  padding-left: 1.2rem;
  font-size: 0.875rem;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
