<script setup lang="ts">
import { computed, watch } from 'vue'
import { useTodosStore } from '../../stores/agents/todos'

/**
 * The session's todo list (roadmap 3.C / O-6c): what the agent's `Todo` tool
 * last wrote, followed live through `todo.updated`.
 */
const props = defineProps<{ sessionId: string }>()

const todos = useTodosStore()
const list = computed(() => todos.forSession(props.sessionId))
const done = computed(() => list.value.items.filter((item) => item.status === 'completed').length)

watch(() => props.sessionId, (id) => void todos.load(id), { immediate: true })

function mark(status: string): string {
  return status === 'completed' ? '✔' : status === 'in_progress' ? '▸' : status === 'cancelled' ? '✖' : '○'
}
</script>

<template>
  <div class="todo-panel" data-testid="todo-panel">
    <p v-if="list.error" class="error" role="alert">{{ list.error }}</p>
    <p v-else-if="list.items.length === 0" class="empty">{{ list.loaded ? 'The agent keeps no todo list in this session.' : 'Loading…' }}</p>
    <template v-else>
      <p class="summary">{{ done }} of {{ list.items.length }} done</p>
      <ol class="items">
        <li v-for="(item, i) in list.items" :key="i" :class="item.status" :data-status="item.status" data-testid="todo-item">
          <span class="mark" aria-hidden="true">{{ mark(item.status) }}</span>
          <span class="text">{{ item.content }}</span>
          <span class="sr-only">({{ item.status.replace('_', ' ') }})</span>
        </li>
      </ol>
    </template>
  </div>
</template>

<style scoped>
.todo-panel {
  display: grid;
  gap: 0.5rem;
}
.summary,
.empty {
  margin: 0;
  color: var(--muted);
  font-size: 0.875rem;
}
.error {
  margin: 0;
  color: var(--error);
}
.items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.3rem;
}
li {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.mark {
  width: 1.2em;
  flex: none;
  color: var(--muted);
}
.in_progress .mark,
.in_progress .text {
  color: var(--accent);
  font-weight: 600;
}
.completed .mark {
  color: var(--ok);
}
.completed .text,
.cancelled .text {
  color: var(--muted);
  text-decoration: line-through;
}
.text {
  overflow-wrap: anywhere;
}
</style>
