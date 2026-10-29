<script setup lang="ts">
import { computed, ref } from 'vue'
import { keyArgument } from '../lib/format'
import { askDiffPreview, diffCounts } from '../lib/lineDiff'
import DiffView from './DiffView.vue'
import MessageMarkdown from './MessageMarkdown.vue'
import type { PendingAsk } from '../protocol/generated'

export type Reply = 'once' | 'always' | 'reject'

const props = defineProps<{ ask: PendingAsk; canAnswer: boolean }>()
// C-SEC-6.c: `save` is the fifth tuple member — the "Always (save)" verdict
// answers `always` and asks the server to persist the grant (`remember:
// "user"`, the TUI's `p`); every consumer already passed the first four.
const emit = defineEmits<{ answer: [reply: Reply, cascade: boolean, note: string, failed: () => void, save: boolean] }>()

const busy = ref(false)
/**
 * The note the agent reads: feedback on a rejection, or — on a question the
 * agent put itself (`AskUser`, `PlanExit`: source `tool:<name>`) — the answer
 * in the user's own words, which goes with Allow once as well (roadmap 5.7-2).
 */
const note = ref('')
const question = computed(() => (props.ask.source ?? '').startsWith('tool:'))
const what = computed(() => keyArgument(props.ask.arguments))
const argsJson = computed(() => JSON.stringify(props.ask.arguments, null, 2))
const offers = computed(() => new Set(props.ask.options))
const alwaysLabel = computed(() => {
  const scope = props.ask.alwaysScope
  const detail = scope ? Object.values(scope).filter((v) => v !== props.ask.tool).join(' ') : ''
  return detail !== '' ? `Always (${detail}, this session)` : 'Always (this session)'
})

/**
 * WEB-5a-ii: when the ask echoes an Edit/Write tool input, show the change as a
 * unified diff. Fail-soft by construction: anything unrecognisable yields null.
 * C-SEC-2: the raw arguments never hide behind that verdict — the arguments
 * `<details>` renders beside the preview always, so every byte the tool will
 * execute stays inspectable; the card is the security gate.
 * C-SEC-2.b: the server's own diff wins whenever it is there — it was computed
 * against the real file, so its `@@` headers and line counts are the change as
 * it will land, where the client mint below can only speak of the snippet it
 * was given. An absent, empty, or whitespace-only value falls back: the card
 * must always show SOMETHING inspectable for an edit-shaped call.
 */
const preview = computed(() => {
  const server = (props.ask as { diff?: unknown }).diff
  if (typeof server === 'string' && server.trim() !== '') {
    const args = props.ask.arguments as Record<string, unknown>
    const path = typeof args['file_path'] === 'string' ? args['file_path'] : props.ask.tool
    return { label: `${path} (server diff)`, text: server, ...diffCounts(server) }
  }
  return askDiffPreview(props.ask.tool, props.ask.arguments)
})

/**
 * C-WEB-7.d: `AskUser` rides the permission channel, and its real choices now
 * arrive typed on the ask itself — `choices`, produced server-side by
 * `PendingAsk::choices()` (gated on source `tool:AskUser`, capped at
 * `AskUserTool::MAX_OPTIONS`). The card renders a button per choice; answering
 * is `once` with the exact option string as the note — the server matches it
 * verbatim. Presence is the whole contract: no `choices` key means no buttons,
 * and the free-text note stays the "something else" answer path. (The old
 * client-side parse of `arguments.options` silently dropped to free text above
 * eight choices; the typed field carries them all.)
 */
const choices = computed<string[]>(() => {
  const raw = (props.ask as { choices?: unknown }).choices
  if (!Array.isArray(raw)) return []
  return raw.filter((option): option is string => typeof option === 'string' && option.trim() !== '')
})

/**
 * WEB-5a-iv: a `PlanExit` ask carries the plan document in `arguments.plan`.
 * Render it through the transcript's markdown surface; approval rides the
 * existing `once` verdict (server: reply once → "Plan approved:").
 */
const planText = computed(() => {
  const ask = props.ask
  if (ask.tool !== 'PlanExit' || (ask.source ?? '') !== 'tool:PlanExit') return ''
  const plan = (ask.arguments as { plan?: unknown }).plan
  return typeof plan === 'string' ? plan : ''
})
const planSummary = computed(() => {
  const summary = (props.ask.arguments as { summary?: unknown }).summary
  return planText.value !== '' && typeof summary === 'string' ? summary : ''
})

function answer(reply: Reply, cascade = false, forcedNote?: string, save = false): void {
  if (busy.value || !props.canAnswer) return
  busy.value = true
  const text = forcedNote ?? note.value.trim()
  // The card disables itself on the assumption the answer lands and the ask
  // disappears. It may not (ISS-110): a transport failure or a non-terminal
  // refusal leaves the same question open, and a permanently greyed card is
  // a dead end. The `failed` callback lets the caller re-arm the buttons.
  const failed = () => {
    busy.value = false
  }
  emit('answer', reply, cascade, reply === 'reject' || (reply === 'once' && question.value) ? text : '', failed, save)
}

/** WEB-5a-iii: clicking a choice answers with that exact option string. */
function answerChoice(option: string): void {
  answer('once', false, option)
}

/** y / a / n answer the focused card, the TUI's keys. */
function onKey(event: KeyboardEvent): void {
  if (event.target instanceof HTMLButtonElement && (event.key === 'Enter' || event.key === ' ')) return
  if (event.target instanceof HTMLInputElement) return
  if (event.key === 'y') answer('once')
  else if (event.key === 'a' && offers.value.has('always')) answer('always')
  else if (event.key === 'n') answer('reject')
}
</script>

<template>
  <section class="ask" tabindex="0" data-testid="permission-card" :data-ask-id="ask.askId" @keydown="onKey">
    <header>
      <span class="label">Permission</span>
      <strong>{{ ask.tool }}</strong>
      <span v-if="what" class="what">{{ what }}</span>
    </header>
    <p v-if="ask.reason" class="reason">{{ ask.reason }}</p>
    <div v-if="planText" class="plan-body" data-testid="ask-plan">
      <p v-if="planSummary" class="plan-summary">{{ planSummary }}</p>
      <MessageMarkdown :text="planText" />
    </div>
    <details v-if="preview" class="diff-box" data-testid="ask-diff">
      <summary>{{ preview.label }} (+{{ preview.added }} −{{ preview.removed }})</summary>
      <DiffView :diff="preview.text" />
    </details>
    <!-- C-SEC-2 (WEB-5-P1): the raw arguments ride along beside the preview,
         never behind it — a preview the tool could out-run is exactly the
         partial consent a security gate must not ask for. Collapsed by
         default, so the card's height is unchanged. -->
    <details data-testid="ask-arguments">
      <summary>arguments</summary>
      <pre>{{ argsJson }}</pre>
    </details>
    <input
      v-model="note"
      type="text"
      class="note-field"
      maxlength="2048"
      :disabled="busy || !canAnswer"
      :placeholder="question ? 'Your answer or feedback (optional)' : 'Note for the agent with Reject (optional)'"
      :aria-label="question ? 'Your answer or feedback' : 'Rejection note'"
      data-testid="ask-note"
    />
    <div v-if="choices.length" class="choices" data-testid="ask-choices">
      <button
        v-for="(option, index) in choices"
        :key="option"
        type="button"
        class="choice"
        :disabled="busy || !canAnswer"
        data-testid="ask-choice"
        @click="answerChoice(option)"
      >{{ index + 1 }}. {{ option }}<span v-if="index === 0" class="recommended"> (recommended)</span></button>
    </div>
    <div class="actions">
      <button type="button" class="primary" :disabled="busy || !canAnswer" data-testid="ask-once" @click="answer('once')">{{ planText ? 'Approve plan' : 'Allow once' }}</button>
      <button v-if="offers.has('always')" type="button" :disabled="busy || !canAnswer" data-testid="ask-always" @click="answer('always')">{{ alwaysLabel }}</button>
      <button v-if="offers.has('always')" type="button" :disabled="busy || !canAnswer" data-testid="ask-always-save" title="Answer always, and save the rule in your config for future launches" @click="answer('always', false, undefined, true)">Always (save)</button>
      <button type="button" :disabled="busy || !canAnswer" data-testid="ask-reject" @click="answer('reject')">Reject</button>
      <button type="button" class="danger" :disabled="busy || !canAnswer" data-testid="ask-reject-stop" @click="answer('reject', true)">Reject &amp; stop</button>
    </div>
    <p v-if="!canAnswer" class="note">This sign-in may not answer questions (no approve scope).</p>
  </section>
</template>

<style scoped>
.ask {
  border: 1px solid var(--warn);
  border-radius: 6px;
  padding: 0.6rem 0.75rem;
  margin: 0 0 0.5rem;
  background: var(--panel);
}
.ask:focus-visible {
  outline: 2px solid var(--accent);
}
header {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  flex-wrap: wrap;
  min-width: 0;
}
.label {
  color: var(--warn);
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.what {
  font-family: var(--mono);
  font-size: 0.8125rem;
  overflow-wrap: anywhere;
}
.reason {
  margin: 0.4rem 0;
  color: var(--muted);
  font-size: 0.875rem;
}
details summary {
  cursor: pointer;
  color: var(--muted);
  font-size: 0.8125rem;
}
.diff-box summary {
  font-family: var(--mono);
}
.plan-body {
  margin: 0.5rem 0;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--panel);
  max-height: 24rem;
  overflow-y: auto;
}
.plan-summary {
  margin: 0 0 0.5rem;
  color: var(--muted);
  font-size: 0.875rem;
}
.choices {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  margin-top: 0.5rem;
}
.choice {
  text-align: left;
  font: inherit;
  font-size: 0.875rem;
  padding: 0.35rem 0.6rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--panel);
  cursor: pointer;
  overflow-wrap: anywhere;
}
.choice:hover:not(:disabled) {
  border-color: var(--accent);
}
.recommended {
  color: var(--muted);
  font-size: 0.8125rem;
}
pre {
  margin: 0.25rem 0 0;
  font-family: var(--mono);
  font-size: 0.8125rem;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.note-field {
  display: block;
  box-sizing: border-box;
  width: 100%;
  margin-top: 0.5rem;
  font: inherit;
  font-size: 0.875rem;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.5rem;
}
.note {
  margin: 0.4rem 0 0;
  font-size: 0.8125rem;
  color: var(--muted);
}
</style>
