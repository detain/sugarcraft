<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import PermissionCard, { type Reply } from '../PermissionCard.vue'
import { sessionLabel } from '../approvals/attention'
import { keyArgument, money, oneLine } from '../../lib/format'
import { useApprovalsStore } from '../../stores/approvals'
import { useConnectionStore } from '../../stores/connection'
import type { TranscriptItem } from '../../stores/reducer'
import { describe, useSessionStore, type SessionStore } from '../../stores/session'
import { useSessionsStore } from '../../stores/sessions'

/**
 * One session in the grid (Appendix O §7.4): its status, step and spend, the
 * last few things that happened, the reply so far — a narrated tail unless
 * this tile is in focus — its open questions, answerable here, and a line to
 * type into: a prompt when the session is idle, a steer while a turn runs.
 */
const props = defineProps<{ id: string; focused: boolean }>()
const emit = defineEmits<{ focus: []; maximize: []; close: [] }>()

const RECENT = 4
const TAIL_CHARS = 600

const connection = useConnectionStore()
const sessions = useSessionsStore()
const approvals = useApprovalsStore()
const text = ref('')
const sending = ref(false)
const error = ref<string | null>(null)

const session = computed<SessionStore>(() => useSessionStore(props.id))
const state = computed(() => session.value.state)
const summary = computed(() => sessions.byId[props.id])
const title = computed(() => sessionLabel(summary.value, props.id))
const canAnswer = computed(() => connection.scopes.includes('approve'))
const asks = computed(() => approvals.countFor(props.id))
const status = computed(() => state.value.status)
const statusText = computed(() => {
  if (status.value === 'waiting_permission') return 'waiting for you'
  if (status.value === 'busy') return state.value.maxSteps > 0 ? `step ${state.value.step}/${state.value.maxSteps}` : 'working'
  return state.value.queue.length > 0 ? `queued (${state.value.queue.length})` : 'idle'
})
const spent = computed(() => state.value.spentUsd + (state.value.turnId !== null ? state.value.turnUsage?.costUsd ?? 0 : 0))
const live = computed(() => {
  const value = state.value.live?.text ?? ''
  return value.length > TAIL_CHARS ? `…${value.slice(-TAIL_CHARS)}` : value
})

function line(item: TranscriptItem): string {
  switch (item.kind) {
    case 'user':
      return `you ▸ ${oneLine(item.content, 140)}`
    case 'assistant':
      return oneLine(item.content, 160)
    case 'notice':
      return oneLine(item.content, 140)
    case 'tool': {
      const mark = item.status === 'done' ? '✔' : item.status === 'running' ? '◌' : '✖'
      return `${mark} ${item.name} ${keyArgument(item.arguments)}`.trim()
    }
  }
}

const recent = computed(() => state.value.items.slice(-RECENT).map((item) => ({ key: item.key, kind: item.kind, text: line(item) })))

watch(() => props.id, (id, previous) => {
  if (previous !== undefined) useSessionStore(previous).release()
  error.value = null
  useSessionStore(id).retain()
}, { immediate: true })

onBeforeUnmount(() => session.value.release())

// The first connect (or a reconnect after a sign-in) after this tile opened.
watch(() => connection.connected, (connected) => {
  if (connected && session.value.phase === 'idle') void session.value.subscribe()
})

async function send(): Promise<void> {
  const prompt = text.value.trim()
  if (prompt === '' || sending.value) return
  sending.value = true
  error.value = null
  try {
    await session.value.send(prompt, 'steer')
    text.value = ''
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    sending.value = false
  }
}

async function answer(askId: string, reply: Reply, cascade: boolean, note = ''): Promise<void> {
  try {
    await session.value.respond(askId, reply, { ...(cascade ? { cascade: true } : {}), ...(note !== '' ? { note } : {}) })
  } catch (failure) {
    error.value = describe(failure)
  }
}
</script>

<template>
  <article
    class="tile"
    :class="{ focused }"
    data-testid="grid-tile"
    :data-session-id="id"
    :data-focused="focused ? 'true' : 'false'"
    :data-status="status"
    :data-phase="session.phase"
    :aria-label="title"
    @mousedown="emit('focus')"
    @focusin="emit('focus')"
  >
    <header>
      <span class="dot" :class="status" aria-hidden="true" />
      <strong class="name" :title="id">{{ title }}</strong>
      <span class="meta">{{ statusText }} · {{ money(spent) }}</span>
      <span v-if="asks > 0" class="badge" :title="`${asks} waiting for an answer`" data-testid="tile-ask-badge">{{ asks }}</span>
      <button type="button" class="link" title="Open in a tab" aria-label="Open in a tab" data-testid="tile-maximize" @click.stop="emit('maximize')">&#10530;</button>
      <button type="button" class="link" title="Close the tile" aria-label="Close the tile" data-testid="tile-close" @click.stop="emit('close')">&#10005;</button>
    </header>
    <div class="body">
      <p v-if="session.error" class="error">{{ session.error }}</p>
      <p v-if="recent.length === 0 && !live" class="empty">Nothing yet.</p>
      <ul class="recent">
        <li v-for="item in recent" :key="item.key" :class="item.kind">{{ item.text }}</li>
      </ul>
      <p v-if="live" class="live" data-testid="tile-live" aria-live="polite">{{ live }}</p>
      <PermissionCard
        v-for="ask in state.asks"
        :key="ask.askId"
        :ask="ask"
        :can-answer="canAnswer"
        @answer="(reply, cascade, note) => answer(ask.askId, reply, cascade, note)"
      />
    </div>
    <form class="composer" @submit.prevent="send">
      <input
        v-model="text"
        type="text"
        :placeholder="session.busy ? 'type to steer…' : 'type a prompt…'"
        :disabled="sending || !connection.connected"
        :aria-label="`Prompt for ${title}`"
        data-testid="tile-input"
      >
    </form>
    <p v-if="error" class="error" role="alert" data-testid="tile-error">{{ error }}</p>
  </article>
</template>

<style scoped>
.tile {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--panel);
  overflow: hidden;
}
.tile.focused {
  border-color: var(--accent);
  box-shadow: 0 0 0 1px var(--accent);
}
header {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.6rem;
  border-bottom: 1px solid var(--line);
  min-width: 0;
}
.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  flex: 0 1 auto;
}
.meta {
  flex: 1;
  color: var(--muted);
  font-size: 0.75rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dot {
  flex: none;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  border: 1px solid var(--muted);
  background: var(--muted);
}
.dot.busy {
  background: var(--accent);
  border-color: var(--accent);
}
.dot.waiting_permission {
  background: var(--warn);
  border-color: var(--warn);
}
.badge {
  background: var(--warn);
  color: var(--bg);
  border-radius: 999px;
  padding: 0 0.4rem;
  font-size: 0.75rem;
  font-weight: 700;
}
.link {
  padding: 0 0.25rem;
}
.body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 0.4rem 0.6rem;
  font-size: 0.8125rem;
}
.recent {
  list-style: none;
  margin: 0 0 0.3rem;
  padding: 0;
}
.recent li {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--muted);
}
.recent li.user {
  color: var(--text);
}
.recent li.tool {
  font-family: var(--mono);
  font-size: 0.75rem;
}
.live {
  margin: 0 0 0.4rem;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.empty {
  margin: 0;
  color: var(--muted);
}
.composer {
  padding: 0.35rem 0.6rem 0.5rem;
  border-top: 1px solid var(--line);
}
.composer input {
  width: 100%;
  font: inherit;
  font-size: 0.8125rem;
  padding: 0.3rem 0.5rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
}
.error {
  margin: 0;
  padding: 0 0.6rem 0.4rem;
  color: var(--error);
  font-size: 0.8125rem;
}
.body .error {
  padding: 0 0 0.3rem;
}
</style>
