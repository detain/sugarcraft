<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import type { MethodResult, PermissionMode } from '../../protocol/generated'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * WEB-1c: the permission policy in the open (`permission.rules`) — the mode
 * in force and where it came from, the configured allow/ask/deny rules, and
 * the grants the session picked up as it ran. Read-only by design: the mode
 * moves through the status bar's select (`session.setMode`), and the rules
 * are the settings' doing; this panel is the audit view, not the dial.
 * With no session on screen it shows the server-wide picture.
 */
const props = defineProps<{ sessionId: string | null }>()
const connection = useConnectionStore()

const mode = ref<PermissionMode | null>(null)
const modeSource = ref<string | null>(null)
const rules = ref<MethodResult<'permission.rules'>['rules']>([])
const sessionGrants = ref<string[]>([])
const loading = ref(false)
const loaded = ref(false)
const error = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const result = await connection.request('permission.rules', props.sessionId === null ? {} : { sessionId: props.sessionId })
    mode.value = result.mode
    modeSource.value = result.modeSource ?? null
    rules.value = result.rules
    sessionGrants.value = result.sessionGrants
    loaded.value = true
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    loading.value = false
  }
}

watch(() => props.sessionId, () => void load())
onMounted(() => void load())
</script>

<template>
  <div class="rules-panel" data-testid="rules-panel">
    <div class="head">
      <span class="mode" data-testid="rules-mode">mode: {{ mode ?? 'unknown' }}</span>
      <span v-if="modeSource" class="src" data-testid="rules-source" :title="modeSource">{{ modeSource }}</span>
      <button type="button" class="link" data-testid="rules-reload" :disabled="loading" @click="load">reload</button>
    </div>
    <p v-if="error" class="error" role="alert" data-testid="rules-error">{{ error }}</p>
    <p v-else-if="loading && !loaded" class="empty">Loading the policy…</p>
    <template v-else-if="loaded">
      <p v-if="rules.length === 0" class="empty" data-testid="rules-none">The server configured no allow/ask/deny rules.</p>
      <table v-else class="list" data-testid="rules-list">
        <thead>
          <tr><th>Pattern</th><th>Action</th></tr>
        </thead>
        <tbody>
          <tr v-for="rule in rules" :key="`${rule.pattern} ${rule.action}`" data-testid="rule-row">
            <td class="pattern">{{ rule.pattern }}</td>
            <td class="action" :data-action="rule.action">{{ rule.action }}</td>
          </tr>
        </tbody>
      </table>
      <h3>Grants made this session</h3>
      <p v-if="sessionGrants.length === 0" class="empty" data-testid="grants-none">Nothing has been granted while running.</p>
      <ul v-else data-testid="grants-list">
        <li v-for="grant in sessionGrants" :key="grant" data-testid="grant-row">{{ grant }}</li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
}
.mode {
  font-weight: 600;
}
.src {
  color: var(--muted);
  font-size: 0.75rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.head button {
  margin-left: auto;
}
h3 {
  margin: 0.75rem 0 0.25rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
}
.list {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}
.list th {
  text-align: left;
  color: var(--muted);
  font-weight: 500;
  padding: 0.15rem 0.5rem 0.15rem 0;
  border-bottom: 1px solid var(--line);
}
.list td {
  padding: 0.2rem 0.5rem 0.2rem 0;
  border-bottom: 1px solid var(--line);
  word-break: break-all;
}
.action {
  white-space: nowrap;
}
.action[data-action='deny'] {
  color: var(--error);
}
.action[data-action='ask'] {
  color: var(--warn);
}
ul {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.8125rem;
  word-break: break-all;
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
