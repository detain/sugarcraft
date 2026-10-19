<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { MethodResult } from '../../protocol/generated'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * WEB-1c: the delegation catalog (`agents.list`) — every agent this server
 * can run a sub-session as, with its model, provider and permission mode.
 * The Agents panel shows the runs of the session on screen; this shows the
 * roster the runs are drawn from, so picking a delegation can be informed
 * by what actually exists.
 */
type AgentRow = MethodResult<'agents.list'>['items'][number]

const connection = useConnectionStore()
const items = ref<AgentRow[]>([])
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const result = await connection.request('agents.list', {})
    items.value = result.items
    loaded.value = true
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    loading.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="catalog" data-testid="agent-catalog">
    <div class="head">
      <span class="count" data-testid="catalog-count">{{ loaded ? `${items.length} agents` : '' }}</span>
      <button type="button" class="link" data-testid="catalog-reload" :disabled="loading" @click="load">reload</button>
    </div>
    <p v-if="error" class="error" role="alert" data-testid="catalog-error">{{ error }}</p>
    <p v-else-if="loading && !loaded" class="empty">Loading the roster…</p>
    <p v-else-if="loaded && items.length === 0" class="empty" data-testid="catalog-none">This server defines no named agents.</p>
    <ul v-else-if="loaded">
      <li v-for="agent in items" :key="agent.name" class="row" :data-testid="`catalog-${agent.name}`">
        <span class="name">{{ agent.name }}<span v-if="agent.active === true" class="on" title="Delegations run as this agent by default">default</span></span>
        <span class="meta">{{ [agent.provider, agent.model].filter((part) => typeof part === 'string' && part !== '').join(' · ') }}<template v-if="agent.permissionMode"> · mode: {{ agent.permissionMode }}</template></span>
        <p v-if="agent.description" class="desc">{{ agent.description }}</p>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.count {
  font-size: 0.75rem;
  color: var(--muted);
}
.head button {
  margin-left: auto;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.4rem;
}
.row {
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.35rem 0.5rem;
  font-size: 0.8125rem;
}
.name {
  font-weight: 600;
}
.on {
  margin-left: 0.4rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: var(--accent);
  color: var(--bg);
  font-size: 0.7rem;
  font-weight: 400;
}
.meta {
  margin-left: 0.4rem;
  color: var(--muted);
}
.desc {
  margin: 0.15rem 0 0;
  color: var(--muted);
}
.empty {
  color: var(--muted);
  font-size: 0.875rem;
}
.error {
  color: var(--error);
  font-size: 0.875rem;
}
</style>
