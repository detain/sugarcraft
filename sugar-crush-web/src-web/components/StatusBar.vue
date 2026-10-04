<script setup lang="ts">
import { computed } from 'vue'
import { money, tokens } from '../lib/format'
import type { PermissionMode, SessionSummary } from '../protocol/generated'
import type { SessionState } from '../stores/reducer'

const MODES: PermissionMode[] = ['default', 'accept-edits', 'plan', 'auto', 'dont-ask', 'bypass-permissions']

const props = defineProps<{
  state: SessionState
  summary?: SessionSummary
  contextPct: number | null
  connection: string
}>()
const emit = defineEmits<{ setMode: [mode: PermissionMode] }>()

const statusText = computed(() => {
  switch (props.state.status) {
    case 'busy':
      return props.state.maxSteps > 0 ? `working · step ${props.state.step}/${props.state.maxSteps}` : 'working'
    case 'waiting_permission':
      return 'waiting for you'
    default:
      return 'idle'
  }
})
const spent = computed(() => props.state.spentUsd + (props.state.turnId !== null ? props.state.turnUsage?.costUsd ?? 0 : 0))
const model = computed(() => {
  const provider = props.summary?.provider || ''
  const name = props.summary?.model || ''
  return [provider, name].filter((part) => part !== '').join(' · ') || 'default model'
})
</script>

<template>
  <footer class="status" data-testid="status-bar" :data-status="state.status">
    <span class="item state" :class="state.status">{{ statusText }}</span>
    <span class="item" title="Context used">
      ~{{ tokens(state.contextTokens) }} tok<template v-if="contextPct !== null"> · {{ contextPct }}%</template>
      <span v-if="contextPct !== null" class="meter" aria-hidden="true"><span :style="{ width: `${contextPct}%` }" /></span>
    </span>
    <span class="item" title="Spent this session">{{ money(spent) }}</span>
    <span class="item model" :title="model">{{ model }}</span>
    <label class="item">
      <span class="sr-only">Permission mode</span>
      <select :value="state.permissionMode ?? 'default'" data-testid="mode-select" @change="emit('setMode', ($event.target as HTMLSelectElement).value as PermissionMode)">
        <option v-for="mode in MODES" :key="mode" :value="mode">mode: {{ mode }}</option>
      </select>
    </label>
    <span class="item conn">{{ connection }}</span>
  </footer>
</template>

<style scoped>
.status {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1rem;
  align-items: center;
  font-size: 0.8125rem;
  color: var(--muted);
  padding: 0.35rem 0;
}
.item {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  white-space: nowrap;
}
.state.busy {
  color: var(--accent);
}
.state.waiting_permission {
  color: var(--warn);
}
.model {
  max-width: 16rem;
  overflow: hidden;
  text-overflow: ellipsis;
}
.meter {
  display: inline-block;
  width: 4rem;
  height: 0.4rem;
  border: 1px solid var(--line);
  border-radius: 2px;
  overflow: hidden;
}
.meter span {
  display: block;
  height: 100%;
  background: var(--accent);
}
select {
  font: inherit;
  color: inherit;
  background: transparent;
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 0.1rem 0.25rem;
}
.conn {
  margin-left: auto;
}
</style>
