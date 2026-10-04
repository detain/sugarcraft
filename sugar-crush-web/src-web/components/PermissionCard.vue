<script setup lang="ts">
import { computed, ref } from 'vue'
import { keyArgument } from '../lib/format'
import type { PendingAsk } from '../protocol/generated'

export type Reply = 'once' | 'always' | 'reject'

const props = defineProps<{ ask: PendingAsk; canAnswer: boolean }>()
const emit = defineEmits<{ answer: [reply: Reply, cascade: boolean] }>()

const busy = ref(false)
const what = computed(() => keyArgument(props.ask.arguments))
const argsJson = computed(() => JSON.stringify(props.ask.arguments, null, 2))
const offers = computed(() => new Set(props.ask.options))
const alwaysLabel = computed(() => {
  const scope = props.ask.alwaysScope
  const detail = scope ? Object.values(scope).filter((v) => v !== props.ask.tool).join(' ') : ''
  return detail !== '' ? `Always (${detail}, this session)` : 'Always (this session)'
})

function answer(reply: Reply, cascade = false): void {
  if (busy.value || !props.canAnswer) return
  busy.value = true
  emit('answer', reply, cascade)
}

/** y / a / n answer the focused card, the TUI's keys. */
function onKey(event: KeyboardEvent): void {
  if (event.target instanceof HTMLButtonElement && (event.key === 'Enter' || event.key === ' ')) return
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
    <details>
      <summary>arguments</summary>
      <pre>{{ argsJson }}</pre>
    </details>
    <div class="actions">
      <button type="button" class="primary" :disabled="busy || !canAnswer" data-testid="ask-once" @click="answer('once')">Allow once</button>
      <button v-if="offers.has('always')" type="button" :disabled="busy || !canAnswer" data-testid="ask-always" @click="answer('always')">{{ alwaysLabel }}</button>
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
pre {
  margin: 0.25rem 0 0;
  font-family: var(--mono);
  font-size: 0.8125rem;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
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
