<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { spendLabel, tokens } from '../lib/format'
import type { MethodResult, PermissionMode, SessionSummary } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { useContextSourcesStore } from '../stores/contextSources'
import { usePanelsStore } from '../stores/agents/panels'
import type { SessionState } from '../stores/reducer'

const MODES: PermissionMode[] = ['default', 'accept-edits', 'plan', 'auto', 'dont-ask', 'bypass-permissions']

const props = defineProps<{
  state: SessionState
  summary?: SessionSummary
  contextPct: number | null
  connection: string
}>()
const emit = defineEmits<{ setMode: [mode: PermissionMode]; runCommand: [text: string] }>()

/**
 * WEB-2: the served session runs /compact and /model on the host now (the
 * runsIn marker admits them), so the status bar carries both as controls.
 * The provider list rides `server.info` — the same read-scoped inventory
 * the About overlay shows — and a failed or empty fetch simply leaves the
 * model a plain span: the select is progressive enhancement, never a
 * second source of truth about what is selected (the session summary is).
 */
const socket = useConnectionStore()
// C-SEC-4: the trust prompts can arrive before any panel measured; the shelf
// in the context store keeps them, and the badge sends the user to the panel
// that answers them. C-SEC-5.b: the shelf is a list — the badge COUNTS the
// rows still awaiting their answer (one per pending grant door).
const context = useContextSourcesStore()
const panels = usePanelsStore()
const trustAwaiting = computed(() => context.pendingTrusts.filter((row) => row.status === 'asked').length)
const providers = ref<string[]>([])

onMounted(async () => {
  try {
    const info = await socket.request('server.info', {})
    providers.value = (info as MethodResult<'server.info'>).providers ?? []
  } catch {
    providers.value = []
  }
})

const currentProvider = computed(() => props.summary?.provider || '')
const selectableProviders = computed(() => {
  if (providers.value.length === 0) return []
  const names = new Set(providers.value)
  if (currentProvider.value !== '' && !names.has(currentProvider.value)) names.add(currentProvider.value)
  return [...names].sort()
})
/** The current entry keeps its exact model; switching to another provider takes that provider's configured default. */
function modelOptionValue(provider: string): string {
  const model = props.summary?.model || ''
  return provider === currentProvider.value && model !== '' ? `${provider} ${model}` : provider
}
function modelOptionLabel(provider: string): string {
  return provider === currentProvider.value ? model.value : provider
}
function pickModel(event: Event): void {
  const select = event.target as HTMLSelectElement
  const value = select.value
  if (value !== '' && value !== modelOptionValue(currentProvider.value)) emit('runCommand', `/model ${value}`)
  // C-WEB-1.b: a pick is a REQUEST the session has not granted yet — while the
  // switch is pending, or if it is refused, the summary keeps naming the old
  // entry. The bound :value did not change, so Vue never repaints the element
  // and the select would advertise a model that is not in use. Pull the DOM
  // back to the summary's entry; the select repaints when the summary moves.
  select.value = modelOptionValue(currentProvider.value)
}

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

/**
 * P-CTX.g (GAP-MOD-15w): the cache read-out, spelled exactly as the TUI
 * status line computes it (Renderer.php) — `read / prompt`, where prompt is
 * the three-bucket identity `cacheRead + cacheCreation + input`, or the
 * OpenAI-shaped `read + input` alone when the provider reports no
 * cache-write bucket at all. An unreported bucket is never a zero: any leg
 * null, the span vanishes rather than advertising 0%.
 */
const cachePct = computed<number | null>(() => {
  const usage = props.state.turnUsage
  const read = usage?.cacheReadTokens ?? null
  const input = usage?.inputTokens ?? null
  if (read === null || input === null) return null
  const creation = usage?.cacheCreationTokens ?? null
  const prompt = creation === null ? read + input : read + input + creation
  if (prompt <= 0) return null
  return Math.round((read / prompt) * 100)
})
</script>

<template>
  <footer class="status" data-testid="status-bar" :data-status="state.status">
    <span class="item state" :class="state.status">{{ statusText }}</span>
    <button v-if="trustAwaiting" type="button" class="item action" data-testid="trust-badge" :title="trustAwaiting === 1 ? 'This project is not trusted — open the Context panel to answer' : `${trustAwaiting} project-trust prompts await — open the Context panel to answer`" @click="panels.show('context')">{{ trustAwaiting === 1 ? 'trust?' : `trust? (${trustAwaiting})` }}</button>
    <button type="button" class="item action" data-testid="context-meter" title="Context used — open the Context panel" @click="panels.show('context')">
      ~{{ tokens(state.contextTokens) }} tok<template v-if="contextPct !== null"> · {{ contextPct }}%</template>
      <span v-if="contextPct !== null" class="meter" aria-hidden="true"><span :style="{ width: `${contextPct}%` }" /></span>
      <span v-if="cachePct !== null" class="cache" data-testid="status-cache">cache {{ cachePct }}%</span>
    </button>
    <span class="item" :title="state.unpriced ? 'No price on file for this model — spend shown is a lower bound' : 'Spent this session'" :data-unpriced="state.unpriced ? 'true' : 'false'" data-testid="spend">{{ spendLabel(spent, state.unpriced) }}</span>
    <label v-if="selectableProviders.length > 0" class="item" :title="model">
      <span class="sr-only">Model</span>
      <select :value="modelOptionValue(currentProvider)" data-testid="model-select" @change="pickModel">
        <option v-if="currentProvider === ''" value="">default model</option>
        <option v-for="provider in selectableProviders" :key="provider" :value="modelOptionValue(provider)">{{ modelOptionLabel(provider) }}</option>
      </select>
    </label>
    <span v-else class="item model" :title="model">{{ model }}</span>
    <button
      type="button"
      class="item action"
      data-testid="compact-button"
      title="Condense older exchanges into a summary"
      :disabled="state.status === 'busy'"
      @click="emit('runCommand', '/compact')"
    >compact</button>
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
button.action {
  font: inherit;
  color: inherit;
  background: transparent;
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 0.1rem 0.4rem;
  cursor: pointer;
}
button.action:disabled {
  color: var(--muted);
  cursor: default;
  opacity: 0.55;
}
.conn {
  margin-left: auto;
}
</style>
