<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Composer from '../components/Composer.vue'
import MessageMarkdown from '../components/MessageMarkdown.vue'
import PermissionCard, { type Reply } from '../components/PermissionCard.vue'
import QueueStrip from '../components/QueueStrip.vue'
import ReasoningFold from '../components/ReasoningFold.vue'
import StatusBar from '../components/StatusBar.vue'
import Transcript from '../components/Transcript.vue'
import type { PermissionMode } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { useLayoutStore } from '../stores/layout'
import type { ToolItem } from '../stores/reducer'
import { describe, useSessionStore, type Delivery, type SessionStore } from '../stores/session'
import { useSessionsStore } from '../stores/sessions'

const props = defineProps<{ id: string }>()

const connection = useConnectionStore()
const sessions = useSessionsStore()
const layout = useLayoutStore()
const error = ref<string | null>(null)
const sending = ref(false)

const session = computed<SessionStore>(() => useSessionStore(props.id))
const state = computed(() => session.value.state)
const summary = computed(() => sessions.byId[props.id])
const title = computed(() => summary.value?.name || summary.value?.preview?.slice(0, 80) || props.id)
const canAnswer = computed(() => connection.scopes.includes('approve'))
const liveText = computed(() => state.value.live?.text ?? '')
const liveReasoning = computed(() => state.value.reasoning?.text ?? '')
const draft = computed({
  get: () => layout.draft(props.id),
  set: (value: string) => layout.setDraft(props.id, value),
})

watch(() => props.id, (id, previous) => {
  if (previous !== undefined) useSessionStore(previous).release()
  error.value = null
  layout.lastSessionId = id
  useSessionStore(id).retain()
}, { immediate: true })

onBeforeUnmount(() => session.value.release())

// The first connect (or a sign-in) after this view opened.
watch(() => connection.connected, (connected) => {
  if (connected && session.value.phase === 'idle') void session.value.subscribe()
})

async function send(text: string, delivery: Delivery): Promise<void> {
  sending.value = true
  error.value = null
  try {
    await session.value.send(text, delivery)
    draft.value = ''
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    sending.value = false
  }
}

async function stop(): Promise<void> {
  try {
    await session.value.cancel('hard')
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function answer(askId: string, reply: Reply, cascade: boolean, note = ''): Promise<void> {
  try {
    await session.value.respond(askId, reply, { ...(cascade ? { cascade: true } : {}), ...(note !== '' ? { note } : {}) })
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function dequeue(queueId: string): Promise<void> {
  try {
    await session.value.dequeue(queueId)
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function setMode(mode: PermissionMode): Promise<void> {
  try {
    await session.value.setMode(mode)
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function loadFull(item: ToolItem): Promise<void> {
  try {
    await session.value.loadFullOutput(item)
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function rename(): Promise<void> {
  const name = window.prompt('Rename the session', summary.value?.name ?? '')
  if (name === null || name.trim() === '') return
  try {
    await sessions.rename(props.id, name.trim())
  } catch (failure) {
    error.value = describe(failure)
  }
}
</script>

<template>
  <section class="session-view" data-testid="session-view" :data-session-id="id" :data-phase="session.phase">
    <header class="head">
      <h1 :title="id">{{ title }}</h1>
      <button type="button" class="link" @click="rename">rename</button>
    </header>
    <p v-if="session.error" class="error" role="alert">{{ session.error }}</p>
    <Transcript :items="state.items" :subagents="state.subagents" :tick="liveText.length + liveReasoning.length + state.asks.length" @load-full="loadFull">
      <template #after>
        <ReasoningFold v-if="liveReasoning" :text="liveReasoning" live />
        <div v-if="liveText" class="live" data-testid="live-reply" aria-live="polite">
          <p v-if="state.live?.gap" class="gap">…</p>
          <MessageMarkdown :text="liveText" />
        </div>
        <PermissionCard
          v-for="ask in state.asks"
          :key="ask.askId"
          :ask="ask"
          :can-answer="canAnswer"
          @answer="(reply, cascade, note) => answer(ask.askId, reply, cascade, note)"
        />
      </template>
    </Transcript>
    <div class="dock">
      <QueueStrip :queue="state.queue" @remove="dequeue" />
      <p v-if="error" class="error" role="alert" data-testid="session-error">{{ error }}</p>
      <Composer
        v-model="draft"
        :busy="session.busy"
        :disabled="sending || !connection.connected"
        :commands="session.commands"
        :default-delivery="connection.defaultDelivery"
        @send="send"
        @stop="stop"
      />
      <StatusBar :state="state" :summary="summary" :context-pct="session.contextPct" :connection="connection.statusLabel" @set-mode="setMode" />
    </div>
  </section>
</template>

<style scoped>
.session-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}
.head {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--line);
  min-width: 0;
}
h1 {
  margin: 0;
  font-size: 1rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.live {
  padding: 0.35rem 0;
}
.gap {
  margin: 0;
  color: var(--muted);
}
.dock {
  padding: 0.5rem 1rem 0.25rem;
  border-top: 1px solid var(--line);
  background: var(--bg);
}
.error {
  margin: 0 0 0.4rem;
  color: var(--error);
  font-size: 0.875rem;
  padding: 0 1rem;
}
.dock .error {
  padding: 0;
}
</style>
