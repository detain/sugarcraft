<script setup lang="ts">
import { computed, ref } from 'vue'
import { ago, money, tokens } from '../../lib/format'
import type { BackgroundSession, PermissionMode } from '../../protocol/generated'
import { isSettled, useBackgroundStore } from '../../stores/agents/background'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * The workspace's background (`/bg`) sessions (roadmap O-4b / O-6c): each
 * with its status, live from the `bg.*` events; its output on demand; stop
 * one that runs; send a settled one's answer into the session on screen.
 *
 * Each row also shows the spawn session's permission mode (ISS-004 threads
 * `permissionMode` through the bg rows), and a loud warning for the
 * bypass-class modes that answer permission asks without a human: a /bg run
 * nobody is watching should not be one that nobody can stop.
 */
defineProps<{ sessionId: string | null }>()

/** Modes that never put a question in front of a human. */
const BYPASS_CLASS: PermissionMode[] = ['bypass-permissions', 'auto', 'dont-ask']

function permissionMode(session: BackgroundSession): PermissionMode | null {
  const mode = (session as BackgroundSession & { permissionMode?: unknown }).permissionMode
  return typeof mode === 'string' && mode !== '' ? (mode as PermissionMode) : null
}

function isBypassClass(session: BackgroundSession): boolean {
  const mode = permissionMode(session)
  return mode !== null && BYPASS_CLASS.includes(mode)
}

const background = useBackgroundStore()
const connection = useConnectionStore()
const task = ref('')
const expanded = ref<string | null>(null)
const error = ref<string | null>(null)
const starting = ref(false)
const canWrite = computed(() => connection.scopes.includes('write'))

async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await action()
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function start(): Promise<void> {
  const text = task.value.trim()
  if (text === '') return
  starting.value = true
  await act(async () => {
    await background.spawn(text)
    task.value = ''
  })
  starting.value = false
}

function toggle(session: BackgroundSession): void {
  expanded.value = expanded.value === session.bgId ? null : session.bgId
  if (expanded.value !== null) void background.loadOutput(session.bgId)
}
</script>

<template>
  <div class="background-panel" data-testid="background-panel">
    <form v-if="canWrite" class="spawn" @submit.prevent="start">
      <input v-model="task" type="text" placeholder="Run a task in the background…" aria-label="Background task" data-testid="bg-task">
      <button type="submit" class="primary" :disabled="starting || task.trim() === ''">Start</button>
    </form>
    <p v-if="error || background.error" class="error" role="alert">{{ error || background.error }}</p>
    <p v-if="background.items.length === 0" class="empty">No background sessions.</p>
    <ul class="items">
      <li v-for="session in background.items" :key="session.bgId" :data-status="session.status" data-testid="bg-item">
        <button type="button" class="head" :aria-expanded="expanded === session.bgId" @click="toggle(session)">
          <span class="status" :class="session.status">{{ session.status.replace('_', ' ') }}</span>
          <span class="name">{{ session.name || session.task }}</span>
          <span v-if="permissionMode(session)" class="mode" :class="{ bypass: isBypassClass(session) }" data-testid="bg-mode">{{ permissionMode(session) }}</span>
          <span class="meta">{{ ago(session.createdAt) }}<template v-if="session.tokensUsed"> · {{ tokens(session.tokensUsed) }} tok</template><template v-if="session.costUsd"> · {{ money(session.costUsd) }}</template></span>
        </button>
        <p v-if="isBypassClass(session)" class="bypass-warning" role="alert" data-testid="bg-bypass-warning">
          Permission mode {{ permissionMode(session) }}: nothing will ask a human before this run acts.
        </p>
        <div v-if="expanded === session.bgId" class="body">
          <p class="task">{{ session.task }}</p>
          <p v-if="session.error" class="error">{{ session.error }}</p>
          <pre data-testid="bg-output">{{ background.output(session.bgId).text || (background.output(session.bgId).loading ? 'Loading…' : 'No output yet.') }}</pre>
          <div v-if="canWrite" class="actions">
            <button type="button" @click="background.loadOutput(session.bgId)">Refresh output</button>
            <button v-if="!isSettled(session)" type="button" class="danger" @click="act(() => background.stop(session.bgId))">Stop</button>
            <button v-else-if="sessionId" type="button" data-testid="bg-inject" @click="act(() => background.inject(session.bgId, sessionId!))">Send to this session</button>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.background-panel {
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.spawn {
  display: flex;
  gap: 0.4rem;
}
input {
  flex: 1;
  min-width: 0;
  font: inherit;
  padding: 0.3rem 0.5rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
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
.items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
}
.head {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.1rem 0.5rem;
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
.status.timed_out,
.status.stopped {
  color: var(--error);
}
.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.mode {
  align-self: center;
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  padding: 0.05rem 0.35rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  color: var(--muted);
  white-space: nowrap;
}
.mode.bypass {
  border-color: var(--warn);
  color: var(--warn);
}
.bypass-warning {
  margin: 0;
  padding: 0.2rem 0.5rem;
  font-size: 0.8125rem;
  color: var(--warn);
  background: color-mix(in srgb, var(--warn) 14%, transparent);
  border-radius: 4px;
}
.meta {
  grid-column: 1 / 4;
  font-size: 0.8125rem;
  color: var(--muted);
}
.body {
  display: grid;
  gap: 0.4rem;
  padding: 0.4rem 0.2rem;
}
.task {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
pre {
  margin: 0;
  padding: 0.4rem;
  max-height: 16rem;
  overflow: auto;
  font-family: var(--mono);
  font-size: 0.8125rem;
  background: var(--bg);
  border-radius: 4px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
</style>
