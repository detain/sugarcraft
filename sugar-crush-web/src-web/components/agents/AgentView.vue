<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { money, tokens } from '../../lib/format'
import { useAgentsStore, type ControlVerb } from '../../stores/agents/agents'
import { statusIcon, type AgentRun } from '../../stores/agents/tree'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'
import AgentTranscript from './AgentTranscript.vue'

/**
 * One delegated run, up close (roadmap O-6c; the TUI's Agent View, Appendix P
 * §5): its own transcript, followed while it runs; a box to message it —
 * read at its next step while it runs, a follow-up once it finished — and its
 * controls (pause, resume, cancel), all through the run's signed mailbox.
 */
const props = defineProps<{ sessionId: string; run: AgentRun }>()
const emit = defineEmits<{ close: [] }>()

/** How often the transcript is re-read while the run is going. */
const POLL_MS = 1500

const agents = useAgentsStore()
const connection = useConnectionStore()
const draft = ref('')
const error = ref<string | null>(null)
const sending = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

const view = computed(() => agents.transcript(props.sessionId, props.run.id))
const live = computed(() => props.run.status === 'running' || props.run.status === 'queued')
const canWrite = computed(() => connection.scopes.includes('write'))
const canContinue = computed(() => !live.value && props.run.resumeId !== null)

function stopPolling(): void {
  if (timer !== null) clearInterval(timer)
  timer = null
}

watch(() => [props.sessionId, props.run.id] as const, ([sessionId, agentId]) => {
  error.value = null
  void agents.loadTranscript(sessionId, agentId, true)
}, { immediate: true })

watch(live, (running) => {
  stopPolling()
  if (running) {
    timer = setInterval(() => void agents.loadTranscript(props.sessionId, props.run.id), POLL_MS)
  } else {
    // One last read: the run's end and its last words.
    void agents.loadTranscript(props.sessionId, props.run.id)
  }
}, { immediate: true })

onBeforeUnmount(stopPolling)

async function send(): Promise<void> {
  const text = draft.value.trim()
  if (text === '' || sending.value) return
  sending.value = true
  error.value = null
  const result = await agents.message(props.sessionId, props.run.id, text)
  sending.value = false
  if (result.status === 'failed') error.value = result.error ?? 'could not send'
  else draft.value = ''
}

async function control(verb: ControlVerb): Promise<void> {
  error.value = null
  try {
    await agents.control(props.sessionId, props.run.id, verb)
  } catch (failure) {
    error.value = describe(failure)
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    event.preventDefault()
    void send()
  }
}
</script>

<template>
  <section class="agent-view" data-testid="agent-view" :data-agent-id="run.id" :data-status="run.status">
    <header>
      <button type="button" class="link back" data-testid="agent-back" @click="emit('close')">← all runs</button>
      <h3><span class="icon" :class="run.status" aria-hidden="true">{{ statusIcon(run.status) }}</span> {{ run.name }}</h3>
      <p v-if="run.description || run.task" class="task">{{ run.description || run.task }}</p>
      <p class="figures">
        {{ run.status }}<template v-if="run.maxSteps > 0"> · step {{ run.step }}/{{ run.maxSteps }}</template> · {{ tokens(run.tokens) }} tok<template v-if="run.cost > 0"> · {{ money(run.cost) }}</template><template v-if="run.model"> · {{ run.model }}</template>
      </p>
      <p v-if="run.error" class="error">{{ run.error }}</p>
      <div v-if="canWrite" class="controls">
        <template v-if="live">
          <button type="button" data-testid="agent-pause" @click="control('pause')">Pause</button>
          <button type="button" data-testid="agent-resume" @click="control('resume')">Resume</button>
          <button type="button" class="danger" data-testid="agent-cancel" @click="control('cancel')">Cancel</button>
        </template>
        <button v-else-if="canContinue" type="button" data-testid="agent-continue" @click="control('resume')">Continue</button>
      </div>
    </header>
    <p v-if="view.error" class="error" role="alert">{{ view.error }}</p>
    <p v-if="view.items.length === 0 && !view.loading" class="empty">Nothing in this run's transcript yet.</p>
    <AgentTranscript :items="view.items" />
    <ul v-if="view.pending.length > 0" class="pending" data-testid="agent-pending">
      <li v-for="(message, i) in view.pending" :key="i" :class="message.status">
        you → {{ message.text }} <span class="badge">{{ message.status === 'queued' ? 'waiting for its next step' : message.status === 'resuming' ? 'continuing the run' : message.status }}</span>
        <span v-if="message.error"> — {{ message.error }}</span>
        <button v-if="message.status === 'failed'" type="button" class="link" @click="agents.dismissPending(sessionId, run.id, i)">dismiss</button>
      </li>
    </ul>
    <p v-if="error" class="error" role="alert" data-testid="agent-error">{{ error }}</p>
    <div v-if="canWrite && (live || canContinue)" class="compose">
      <textarea
        v-model="draft"
        rows="2"
        :placeholder="live ? `Message ${run.name}… (read at its next step)` : `Continue ${run.name}…`"
        aria-label="Message the run"
        data-testid="agent-input"
        @keydown="onKeydown"
      />
      <button type="button" class="primary" :disabled="sending || draft.trim() === ''" data-testid="agent-send" @click="send">Send</button>
    </div>
  </section>
</template>

<style scoped>
.agent-view {
  display: grid;
  gap: 0.6rem;
  min-width: 0;
}
header {
  display: grid;
  gap: 0.25rem;
}
.back,
.controls {
  justify-self: start;
}
h3 {
  margin: 0;
  font-size: 1rem;
}
.icon.running {
  color: var(--accent);
}
.icon.done {
  color: var(--ok);
}
.icon.failed,
.icon.cancelled {
  color: var(--error);
}
.task {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.figures,
.empty {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
}
.controls {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
.pending {
  margin: 0;
  padding-left: 1rem;
  font-size: 0.875rem;
}
.pending .failed {
  color: var(--error);
}
.badge {
  color: var(--muted);
  font-size: 0.75rem;
}
.compose {
  display: flex;
  gap: 0.4rem;
  align-items: flex-end;
}
textarea {
  flex: 1;
  min-width: 0;
  resize: vertical;
  font: inherit;
  padding: 0.4rem 0.5rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
}
</style>
